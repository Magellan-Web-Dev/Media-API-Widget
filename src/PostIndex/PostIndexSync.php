<?php

namespace MediaApiWidget\PostIndex;

use MediaApiWidget\Config\Options;
use MediaApiWidget\Support\MediaStore;
use MediaApiWidget\Support\OptionLock;
use WP_Error;

if (!defined('ABSPATH')) { exit; }

/**
 * Keeps a playlist's stored posts in step with its stored media data.
 *
 * Only playlists whose media item enables "Store playlist items as WordPress
 * posts" are ever synchronized. That setting is checked when a sync is queued,
 * when each queued batch runs, by manual resyncs, and by initial population, so
 * switching it off stops everything — including work already queued.
 *
 * Synchronization is asynchronous. The media pipeline stores data from inside
 * wp_head on ordinary page views, so the store listener only queues a WP-Cron
 * event carrying the playlist name and media type. The event reads the data back
 * from storage (the transient, falling back to the backup file) and works through
 * it in bounded batches, queueing a continuation until the pass is complete. A
 * pass that sees the stored data change between batches starts over, so one
 * pass never mixes two snapshots.
 *
 * What a pass does:
 *
 * 1. Upserts one post per stored item, matched by its stable source identity
 *    (video id or episode GUID, scoped to the playlist and media type). Existing
 *    posts keep their ID; source-controlled fields are rewritten when they
 *    change and cleared when the source stops supplying them. See
 *    {@see PostUpserter}.
 * 2. Removes posts for items that are no longer in the source — but only with
 *    authoritative evidence. Removal requires a {@see RefreshManifest} recorded
 *    by a complete, validated, stored remote refresh; that the manifest is still
 *    the current one when the pass finishes (no newer refresh superseded it);
 *    that every item was written without failure and every item had a usable
 *    identity; and that this refresh has not already been reconciled. A post is
 *    removed only when its item is in neither the refresh's complete membership
 *    nor the stored data, so a limited snapshot, an enrichment write, or a
 *    cache read can never shrink the set of posts.
 *
 * A pass with write failures is retried with a delay, up to a limit; a pass that
 * finds the playlist locked by another pass is retried shortly after.
 */
final class PostIndexSync
{
    /** Cron hook that performs one batch of a playlist's synchronization. */
    public const CRON_HOOK = 'maw_sync_post_index';

    /** Action fired after a synchronization pass completes. */
    public const ACTION_SYNCED = 'media_api_widget_post_index_synced';

    /** Filter for the number of items written per background batch. */
    public const FILTER_BATCH_SIZE = 'media_api_widget_post_index_batch_size';

    /** Context `source` for a synchronization run by WP-Cron. */
    public const SOURCE_CRON = 'cron';

    /** Context `source` for a synchronization run by an explicit rebuild. */
    public const SOURCE_MANUAL = 'manual_rebuild';

    /** Option-name prefix for the per-playlist synchronization lock. */
    public const LOCK_PREFIX = 'maw_post_index_lock_';

    /** Option-name prefix for a playlist's in-progress pass. */
    public const JOB_PREFIX = 'maw_post_index_job_';

    /**
     * How long a synchronization lock stays valid. A batch is bounded, so this
     * is generous; an expired lock can be reclaimed after a crashed worker.
     */
    public const LOCK_TTL_SECONDS = 300;

    /** Items written per background batch unless filtered. */
    public const DEFAULT_BATCH_SIZE = 50;

    /** Failed passes are retried this many times before waiting for new data. */
    public const MAX_ATTEMPTS = 5;

    /** Delay before a queued synchronization runs (collapses bursts of stores). */
    private const SCHEDULE_DELAY = 30;

    /** Delay between the batches of one pass. */
    private const CONTINUE_DELAY = 2;

    /** Delay before retrying when another pass holds the playlist's lock. */
    private const LOCKED_RETRY_DELAY = 60;

    /** Delay before retrying a pass that had write or delete failures. */
    private const FAILURE_RETRY_DELAY = 300;

    /**
     * Hooks the store listener and the cron callback.
     *
     * @return void
     */
    public function register(): void
    {
        add_action(MediaStore::ACTION_STORED, [$this, 'onDataStored'], 10, 2);
        add_action(self::CRON_HOOK, [$this, 'runScheduled'], 10, 2);
    }

    /**
     * Queues a synchronization after media data has been stored.
     *
     * Responds to every store source — a refresh, a shortcode warm-up, and an
     * enrichment write — so a transcript written through
     * media_api_widget_update_stored_data() reaches its post. Only a refresh
     * records a manifest, so only a refresh can authorize removals.
     *
     * Deliberately trivial: MediaStore swallows exceptions thrown here, so all
     * real work happens behind the cron boundary where failures are reported.
     *
     * @param mixed               $data    The stored payload. Never read.
     * @param array<string,mixed> $context Store context.
     * @return void
     */
    public function onDataStored($data, $context = []): void
    {
        if (!is_array($context)) {
            return;
        }

        $args = self::normalizeArgs($context['playlist_name'] ?? '', $context['media_type'] ?? '');

        if ($args === null || !Options::isPostStorageEnabled($args[0], $args[1])) {
            return;
        }

        if (self::scheduleSync($args[0], $args[1])) {
            self::markPending($args[0], $args[1], 'Queued after new data was stored.');
        }
    }

    /**
     * Queues one deduplicated synchronization event.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param int    $delay        Seconds from now.
     * @return bool True when an event is queued, including one that already was.
     */
    public static function scheduleSync(string $playlistName, string $mediaType, int $delay = self::SCHEDULE_DELAY): bool
    {
        $args = self::normalizeArgs($playlistName, $mediaType);

        if ($args === null || !Options::isPostStorageEnabled($args[0], $args[1])) {
            return false;
        }

        if (wp_next_scheduled(self::CRON_HOOK, $args) !== false) {
            // Already queued; the queued run reads the newest data anyway.
            return true;
        }

        return wp_schedule_single_event(time() + max(0, $delay), self::CRON_HOOK, $args) !== false;
    }

    /**
     * Removes every queued synchronization event for a playlist.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return void
     */
    public static function unschedule(string $playlistName, string $mediaType): void
    {
        $args = self::normalizeArgs($playlistName, $mediaType);
        if ($args === null) {
            return;
        }

        for ($guard = 0; $guard < 20; $guard++) {
            $next = wp_next_scheduled(self::CRON_HOOK, $args);
            if ($next === false) {
                return;
            }
            wp_unschedule_event((int) $next, self::CRON_HOOK, $args);
        }
    }

    /**
     * Queues a full resynchronization to start now (manual resync, initial
     * population). Any pass in progress starts over from the first item.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param string $message      Status message to show while pending.
     * @return true|WP_Error
     */
    public static function requestResync(string $playlistName, string $mediaType, string $message = 'Manual resync queued.')
    {
        $args = self::normalizeArgs($playlistName, $mediaType);
        if ($args === null) {
            return new WP_Error('maw_invalid_playlist_name', 'A usable playlist name and media type are required.');
        }

        if (!Options::isPostStorageEnabled($args[0], $args[1])) {
            return new WP_Error('maw_post_storage_disabled', 'Post storage is not enabled for this playlist.');
        }

        self::unschedule($args[0], $args[1]);
        self::clearJob($args[0], $args[1]);
        SyncStatus::update($args[0], $args[1], ['attempts' => 0]);

        if (!self::scheduleSync($args[0], $args[1], 0)) {
            return new WP_Error('maw_schedule_failed', 'The synchronization could not be queued.');
        }

        self::markPending($args[0], $args[1], $message);

        return true;
    }

    /**
     * Applies a change to the media item settings.
     *
     * Newly enabled playlists get a fresh start and an initial population from
     * whatever valid data is already stored; removals are not inferred from it.
     * Disabled or removed playlists stop synchronizing — queued work included —
     * and keep their posts.
     *
     * @param array<int,array<string,mixed>> $before Media items before the save.
     * @param array<int,array<string,mixed>> $after  Media items after the save.
     * @return array{enabled:array<int,array{0:string,1:string}>,disabled:array<int,array{0:string,1:string}>}
     */
    public static function applySettingsChange(array $before, array $after): array
    {
        $wasOn = self::enabledPairs($before);
        $isOn  = self::enabledPairs($after);

        $enabled  = array_values(array_diff_key($isOn, $wasOn));
        $disabled = array_values(array_diff_key($wasOn, $isOn));

        foreach ($enabled as [$playlist, $type]) {
            // No manifest yet: the first pass may only add and update.
            RefreshManifest::clear($playlist, $type);
            self::clearJob($playlist, $type);
            SyncStatus::update($playlist, $type, ['attempts' => 0, 'reconciled_token' => '', 'prune_note' => '']);
            self::requestResync($playlist, $type, 'Initial population queued.');
        }

        foreach ($disabled as [$playlist, $type]) {
            self::stop($playlist, $type);
        }

        return ['enabled' => $enabled, 'disabled' => $disabled];
    }

    /**
     * Stops synchronization for a playlist, keeping its posts.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return void
     */
    public static function stop(string $playlistName, string $mediaType): void
    {
        self::unschedule($playlistName, $mediaType);
        self::clearJob($playlistName, $mediaType);
        RefreshManifest::clear($playlistName, $mediaType);
        SyncStatus::update($playlistName, $mediaType, [
            'state'    => SyncStatus::STATE_DISABLED,
            'message'  => 'Post storage is off. Existing posts are kept; synchronization has stopped.',
            'progress' => null,
        ]);
    }

    /**
     * Runs one scheduled batch and queues whatever should follow it.
     *
     * @param mixed $playlistName Playlist name from the cron arguments.
     * @param mixed $mediaType    Media type from the cron arguments.
     * @return void
     */
    public function runScheduled($playlistName = '', $mediaType = ''): void
    {
        $args = self::normalizeArgs($playlistName, $mediaType);

        if ($args === null || !PostIndex::isRegistered()) {
            return;
        }

        $outcome = self::runPass($args[0], $args[1], self::SOURCE_CRON, self::batchSize($args[0], $args[1]));

        switch ($outcome['state']) {
            case 'continue':
                self::scheduleSync($args[0], $args[1], self::CONTINUE_DELAY);
                break;
            case 'locked':
                self::scheduleSync($args[0], $args[1], self::LOCKED_RETRY_DELAY);
                break;
            case 'retry':
                $status = SyncStatus::get($args[0], $args[1]);
                if ((int) $status['attempts'] < self::MAX_ATTEMPTS) {
                    self::scheduleSync($args[0], $args[1], self::FAILURE_RETRY_DELAY);
                }
                break;
        }
    }

    /**
     * Runs a complete synchronization pass immediately, in this request.
     *
     * Used by tests and code that needs a synchronous result. Applies exactly
     * the same rules as the background batches, without batch boundaries.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param string $source       One of the SOURCE_* constants.
     * @return array<string,mixed> Result summary; see {@see self::emptyResult()}.
     */
    public static function sync(string $playlistName, string $mediaType, string $source): array
    {
        $args = self::normalizeArgs($playlistName, $mediaType);

        if ($args === null) {
            $result = self::emptyResult((string) $playlistName, (string) $mediaType);
            $result['error'] = new WP_Error('maw_invalid_playlist_name', 'A usable playlist name and media type are required.');

            return $result;
        }

        $outcome = ['state' => 'continue', 'result' => self::emptyResult($args[0], $args[1])];

        for ($guard = 0; $guard < 1000; $guard++) {
            $outcome = self::runPass($args[0], $args[1], $source, null);

            if ($outcome['state'] !== 'continue') {
                return $outcome['result'];
            }
        }

        return $outcome['result'];
    }

    /**
     * Returns how many posts are stored for a playlist (Trash excluded).
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return int Post count.
     */
    public static function countStoredPosts(string $playlistName, string $mediaType): int
    {
        if (!PostIndex::isRegistered()) {
            return 0;
        }

        $statuses = array_values(array_diff(PostUpserter::ALL_STATUSES, ['trash']));

        return count(self::ownedPostIds($playlistName, $mediaType, $statuses));
    }

    /**
     * Deletes a playlist's generated posts — the explicit cleanup action.
     *
     * Only posts carrying the ownership marker and a source key for this
     * playlist are touched. Takes the synchronization lock so it cannot race a
     * pass, and removes at most $limit posts per call.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param int    $limit        Maximum posts to delete in this call.
     * @return array{deleted:int,failed:int,remaining:int}|WP_Error
     */
    public static function deleteStoredPosts(string $playlistName, string $mediaType, int $limit = 200)
    {
        $args = self::normalizeArgs($playlistName, $mediaType);
        if ($args === null) {
            return new WP_Error('maw_invalid_playlist_name', 'A usable playlist name and media type are required.');
        }

        if (!PostIndex::isRegistered()) {
            return new WP_Error('maw_post_index_unregistered', 'The media item post type is not registered yet.');
        }

        $lock = OptionLock::acquire(self::lockName($args[1], $args[0]), self::LOCK_TTL_SECONDS);
        if ($lock === null) {
            return new WP_Error('maw_post_index_locked', 'A synchronization is running for this playlist. Try again shortly.');
        }

        try {
            $ids     = self::ownedPostIds($args[0], $args[1], PostUpserter::ALL_STATUSES);
            $deleted = 0;
            $failed  = 0;

            foreach (array_slice($ids, 0, max(1, $limit)) as $id) {
                if (wp_delete_post($id, true)) {
                    $deleted++;
                } else {
                    $failed++;
                }
            }

            // A cleanup invalidates any in-progress pass and the reconciliation
            // record; an enabled playlist repopulates on its next run.
            self::clearJob($args[0], $args[1]);
            SyncStatus::update($args[0], $args[1], ['reconciled_token' => '']);

            // With no media items left anywhere, the admin menu entries for them
            // are shown again only once a playlist stores posts.
            $any = get_posts([
                'post_type'      => PostIndex::POST_TYPE,
                'post_status'    => PostUpserter::ALL_STATUSES,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            if ($any === []) {
                delete_option(PostIndex::OPTION_HAS_POSTS);
            }

            return ['deleted' => $deleted, 'failed' => $failed, 'remaining' => max(0, count($ids) - $deleted)];
        } finally {
            OptionLock::release($lock);
        }
    }

    /**
     * Returns the wp_options key for a playlist's synchronization lock.
     *
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param string $playlistName The playlist_name slug.
     * @return string e.g. 'maw_post_index_lock_youtube_my_show'.
     */
    public static function lockName(string $mediaType, string $playlistName): string
    {
        return self::LOCK_PREFIX . sanitize_key($mediaType) . '_' . sanitize_key($playlistName);
    }

    /**
     * Returns the option name holding a playlist's in-progress pass.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return string Option name.
     */
    public static function jobOptionName(string $playlistName, string $mediaType): string
    {
        return self::JOB_PREFIX . sanitize_key($mediaType) . '_' . sanitize_key($playlistName);
    }

    /**
     * Discards a playlist's in-progress pass.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return void
     */
    public static function clearJob(string $playlistName, string $mediaType): void
    {
        delete_option(self::jobOptionName($playlistName, $mediaType));
    }

    // ---------------------------------------------------------------------
    // The pass
    // ---------------------------------------------------------------------

    /**
     * Runs one step of a pass: a batch of upserts, or a batch of removals.
     *
     * @param string   $playlistName Normalized playlist slug.
     * @param string   $mediaType    Normalized media type.
     * @param string   $source       SOURCE_* constant.
     * @param int|null $limit        Items per step; null for the whole pass.
     * @return array{state:string,result:array<string,mixed>} state: done, continue, locked, retry, or error.
     */
    private static function runPass(string $playlistName, string $mediaType, string $source, ?int $limit): array
    {
        $result = self::emptyResult($playlistName, $mediaType);

        if (!PostIndex::isRegistered()) {
            $result['error'] = new WP_Error('maw_post_index_unregistered', 'The media item post type is not registered yet.');

            return ['state' => 'error', 'result' => $result];
        }

        if (!Options::isPostStorageEnabled($playlistName, $mediaType)) {
            // Switched off while queued: stop here, keep the posts.
            self::clearJob($playlistName, $mediaType);
            $result['error'] = new WP_Error('maw_post_storage_disabled', 'Post storage is not enabled for this playlist.');

            return ['state' => 'error', 'result' => $result];
        }

        $lock = OptionLock::acquire(self::lockName($mediaType, $playlistName), self::LOCK_TTL_SECONDS);

        if ($lock === null) {
            $result['error'] = new WP_Error('maw_post_index_locked', 'A post index synchronization is already running for this playlist.');

            return ['state' => 'locked', 'result' => $result];
        }

        try {
            // The manifest is read before the data, so the data read below is at
            // least as new as the refresh the manifest describes.
            $manifest = RefreshManifest::get($playlistName, $mediaType);
            $data     = media_api_widget_get_stored_data($playlistName, $mediaType);

            if ($data === null) {
                return self::fail($playlistName, $mediaType, $result, new WP_Error(
                    'maw_no_stored_data',
                    'No stored data was found for this playlist. Nothing was indexed.'
                ));
            }

            if (MediaStore::validate($data, $mediaType) instanceof WP_Error) {
                // An embed-only podcast stores a URL, not a feed; anything else
                // here is not a shape posts can be built from.
                return self::fail($playlistName, $mediaType, $result, new WP_Error(
                    'maw_unsupported_stored_data',
                    'The stored data for this playlist has no items that can be stored as posts.'
                ));
            }

            $hash    = md5((string) wp_json_encode($data));
            $mapped  = ItemMapper::fromStored($data, $playlistName, $mediaType);
            $records = $mapped['records'];
            $total   = count($records);

            $job = self::loadJob($playlistName, $mediaType);
            if ($job === null || $job['data_hash'] !== $hash) {
                // New pass, or the data changed between batches: start over so
                // one pass never mixes two snapshots.
                $job = self::newJob($hash, (string) ($manifest['token'] ?? ''), $source);
            }

            if ($job['phase'] === 'upsert') {
                $slice = array_slice($records, (int) $job['offset'], $limit ?? max(1, $total));
                $now   = time();

                foreach ($slice as $record) {
                    $outcome = PostUpserter::upsert($record, $playlistName, $mediaType, $now);
                    $status  = $outcome['status'];

                    if (isset($job['counts'][$status])) {
                        $job['counts'][$status]++;
                    }

                    $job['counts']['duplicates'] += (int) $outcome['duplicates'];
                }

                $job['offset'] = (int) $job['offset'] + count($slice);

                if ($job['offset'] < $total) {
                    self::saveJob($playlistName, $mediaType, $job);
                    SyncStatus::update($playlistName, $mediaType, [
                        'state'    => SyncStatus::STATE_RUNNING,
                        'message'  => sprintf('Writing posts: %d of %d items.', $job['offset'], $total),
                        'progress' => ['done' => (int) $job['offset'], 'total' => $total],
                    ]);

                    return ['state' => 'continue', 'result' => self::resultFromJob($result, $job, $mapped)];
                }

                $job['phase'] = 'prune';
            }

            // Removal is decided now, against the manifest as it stands at the
            // end of the pass.
            $decision = self::pruneDecision($playlistName, $mediaType, $job, $manifest, (int) $mapped['skipped']);

            if ($decision === 'ok') {
                $keep = array_fill_keys(array_merge(
                    (array) $manifest['keys'],
                    array_map(static fn (array $record): string => (string) $record['source_key'], $records)
                ), true);

                $candidates = self::missingPostIds($playlistName, $mediaType, $keep);
                $batch      = $limit === null ? $candidates : array_slice($candidates, 0, max(1, $limit * 2));

                foreach ($batch as $id) {
                    if (wp_delete_post($id, true)) {
                        $job['pruned']++;
                    } else {
                        $job['prune_failed']++;
                    }
                }

                if (count($candidates) > count($batch)) {
                    self::saveJob($playlistName, $mediaType, $job);
                    SyncStatus::update($playlistName, $mediaType, [
                        'state'   => SyncStatus::STATE_RUNNING,
                        'message' => sprintf('Removing posts for items no longer in the source: %d removed so far.', $job['pruned']),
                    ]);

                    return ['state' => 'continue', 'result' => self::resultFromJob($result, $job, $mapped)];
                }

                $decision = $job['prune_failed'] > 0
                    ? 'delete_failures'
                    : ($job['pruned'] > 0 ? 'removed' : 'none_missing');
            }

            return self::finish($playlistName, $mediaType, $source, $job, $mapped, $manifest, $decision, $result);
        } finally {
            // Released on success, on every early return, and on a thrown
            // exception, so a fatal mid-sync cannot wedge future runs.
            OptionLock::release($lock);
        }
    }

    /**
     * Decides whether this pass may remove posts for missing items.
     *
     * @param string                   $playlistName Playlist slug.
     * @param string                   $mediaType    Media type.
     * @param array<string,mixed>      $job          The pass.
     * @param array<string,mixed>|null $manifest     Current refresh manifest.
     * @param int                      $skipped      Stored items with no usable identity.
     * @return string 'ok', or the note code explaining why not.
     */
    private static function pruneDecision(string $playlistName, string $mediaType, array $job, ?array $manifest, int $skipped): string
    {
        if ($manifest === null) {
            return 'no_refresh_record';
        }

        if ((string) $manifest['token'] !== (string) $job['manifest_token']) {
            return 'superseded';
        }

        if (empty($manifest['complete'])) {
            return 'incomplete_refresh';
        }

        if ($skipped > 0) {
            return 'unidentified_items';
        }

        if ((int) $job['counts']['failed'] > 0) {
            return 'write_failures';
        }

        if (SyncStatus::get($playlistName, $mediaType)['reconciled_token'] === (string) $manifest['token']) {
            return 'already_reconciled';
        }

        return 'ok';
    }

    /**
     * Completes a pass: records status, fires the synced action.
     *
     * @param string                   $playlistName Playlist slug.
     * @param string                   $mediaType    Media type.
     * @param string                   $source       SOURCE_* constant.
     * @param array<string,mixed>      $job          The pass.
     * @param array<string,mixed>      $mapped       ItemMapper output.
     * @param array<string,mixed>|null $manifest     Current refresh manifest.
     * @param string                   $decision     Prune note code.
     * @param array<string,mixed>      $result       Result being built.
     * @return array{state:string,result:array<string,mixed>}
     */
    private static function finish(
        string $playlistName,
        string $mediaType,
        string $source,
        array $job,
        array $mapped,
        ?array $manifest,
        string $decision,
        array $result
    ): array {
        $result = self::resultFromJob($result, $job, $mapped);
        $result['prune_note'] = $decision;
        $result['ok'] = $result['failed'] === 0 && $result['prune_failed'] === 0;

        self::clearJob($playlistName, $mediaType);

        $status  = SyncStatus::get($playlistName, $mediaType);
        $changes = [
            'last_result' => array_intersect_key($result, array_flip([
                'created', 'updated', 'unchanged', 'failed', 'skipped', 'duplicates', 'total_seen', 'pruned', 'prune_failed',
            ])),
            'prune_note'  => $decision,
            'progress'    => null,
        ];

        if (in_array($decision, ['removed', 'none_missing'], true) && $manifest !== null) {
            // Reconciled once per completed refresh.
            $changes['reconciled_token'] = (string) $manifest['token'];
        }

        if ($result['ok']) {
            $changes += [
                'state'           => SyncStatus::STATE_COMPLETE,
                'message'         => sprintf(
                    'Synchronized %d items (%d created, %d updated, %d unchanged, %d removed).',
                    $result['total_seen'] - $result['skipped'],
                    $result['created'],
                    $result['updated'],
                    $result['unchanged'],
                    $result['pruned']
                ),
                'last_success_at' => time(),
                'attempts'        => 0,
            ];
        } else {
            $changes += [
                'state'    => SyncStatus::STATE_ERROR,
                'message'  => sprintf(
                    '%d items could not be written and %d posts could not be removed. Existing posts were kept.',
                    $result['failed'],
                    $result['prune_failed']
                ),
                'attempts' => (int) $status['attempts'] + 1,
            ];
        }

        SyncStatus::update($playlistName, $mediaType, $changes);

        self::fireSynced($result, [
            'playlist_name' => $playlistName,
            'media_type'    => $mediaType,
            'source'        => $source,
        ]);

        return ['state' => $result['ok'] ? 'done' : 'retry', 'result' => $result];
    }

    /**
     * Ends a pass that could not run, keeping every existing post.
     *
     * @param string              $playlistName Playlist slug.
     * @param string              $mediaType    Media type.
     * @param array<string,mixed> $result       Result being built.
     * @param WP_Error            $error        Why.
     * @return array{state:string,result:array<string,mixed>}
     */
    private static function fail(string $playlistName, string $mediaType, array $result, WP_Error $error): array
    {
        self::clearJob($playlistName, $mediaType);
        SyncStatus::update($playlistName, $mediaType, [
            'state'    => SyncStatus::STATE_ERROR,
            'message'  => $error->get_error_message() . ' Existing posts were kept.',
            'progress' => null,
        ]);

        $result['error'] = $error;

        return ['state' => 'error', 'result' => $result];
    }

    /**
     * Returns managed posts of this playlist whose items are in neither the
     * refresh's complete membership nor the stored data.
     *
     * @param string            $playlistName Playlist slug.
     * @param string            $mediaType    Media type.
     * @param array<string,bool> $keep        Source keys to keep.
     * @return array<int,int> Post IDs.
     */
    private static function missingPostIds(string $playlistName, string $mediaType, array $keep): array
    {
        $missing = [];

        foreach (self::ownedPostIds($playlistName, $mediaType, PostUpserter::ALL_STATUSES) as $id) {
            if (!isset($keep[(string) get_post_meta($id, PostIndex::META_SOURCE_KEY, true)])) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * Returns posts this plugin generated for one playlist and media type.
     *
     * Scoped four ways: the plugin's post type, the playlist and media type
     * meta, and the protected `_maw_source_key` — written only by this plugin,
     * hidden from the Custom Fields UI and REST — carrying this playlist's
     * prefix. A post failing any of them is never touched. (Posts written now
     * also carry `_maw_managed`; the source key is what is required, so posts
     * written by earlier versions of the index are recognized too.)
     *
     * @param string            $playlistName Playlist slug.
     * @param string            $mediaType    Media type.
     * @param array<int,string> $statuses     Statuses to include.
     * @return array<int,int> Post IDs.
     */
    private static function ownedPostIds(string $playlistName, string $mediaType, array $statuses): array
    {
        $prefix = ItemMapper::sourceKeyPrefix($mediaType, $playlistName);
        $owned  = [];

        foreach (self::managedPostIds($playlistName, $mediaType, $statuses) as $id) {
            $key = get_post_meta($id, PostIndex::META_SOURCE_KEY, true);

            if (is_string($key) && str_starts_with($key, $prefix) && get_post_type($id) === PostIndex::POST_TYPE) {
                $owned[] = $id;
            }
        }

        return $owned;
    }

    /**
     * @param string            $playlistName Playlist slug.
     * @param string            $mediaType    Media type.
     * @param array<int,string> $statuses     Statuses to include.
     * @return array<int,int> Post IDs carrying a source key for this playlist's meta.
     */
    private static function managedPostIds(string $playlistName, string $mediaType, array $statuses): array
    {
        $ids = get_posts([
            'post_type'              => PostIndex::POST_TYPE,
            'post_status'            => $statuses,
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => [
                ['key' => PostIndex::META_PLAYLIST_NAME, 'value' => sanitize_key($playlistName), 'compare' => '='],
                ['key' => PostIndex::META_MEDIA_TYPE, 'value' => sanitize_key($mediaType), 'compare' => '='],
                ['key' => PostIndex::META_SOURCE_KEY, 'compare' => 'EXISTS'],
            ],
        ]);

        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    // ---------------------------------------------------------------------
    // Job state
    // ---------------------------------------------------------------------

    /**
     * @param string $hash          Hash of the stored data this pass reads.
     * @param string $manifestToken Token of the manifest current at pass start.
     * @param string $source        SOURCE_* constant.
     * @return array<string,mixed> A new pass.
     */
    private static function newJob(string $hash, string $manifestToken, string $source): array
    {
        return [
            'data_hash'      => $hash,
            'manifest_token' => $manifestToken,
            'source'         => $source,
            'phase'          => 'upsert',
            'offset'         => 0,
            'started_at'     => time(),
            'counts'         => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'duplicates' => 0],
            'pruned'         => 0,
            'prune_failed'   => 0,
        ];
    }

    /**
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    Media type.
     * @return array<string,mixed>|null The pass in progress.
     */
    private static function loadJob(string $playlistName, string $mediaType): ?array
    {
        $job = get_option(self::jobOptionName($playlistName, $mediaType), null);

        return is_array($job) && isset($job['data_hash'], $job['phase'], $job['counts']) ? $job : null;
    }

    /**
     * @param string              $playlistName Playlist slug.
     * @param string              $mediaType    Media type.
     * @param array<string,mixed> $job          The pass.
     * @return void
     */
    private static function saveJob(string $playlistName, string $mediaType, array $job): void
    {
        update_option(self::jobOptionName($playlistName, $mediaType), $job, false);
    }

    /**
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    Media type.
     * @param string $message      Status message.
     * @return void
     */
    private static function markPending(string $playlistName, string $mediaType, string $message): void
    {
        SyncStatus::update($playlistName, $mediaType, ['state' => SyncStatus::STATE_PENDING, 'message' => $message]);
    }

    /**
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    Media type.
     * @return int Items per background batch.
     */
    private static function batchSize(string $playlistName, string $mediaType): int
    {
        /**
         * Filters how many items one background batch writes.
         *
         * @param int    $size         Default 50.
         * @param string $playlistName Playlist slug.
         * @param string $mediaType    'youtube' or 'podcast'.
         */
        $size = (int) apply_filters(self::FILTER_BATCH_SIZE, self::DEFAULT_BATCH_SIZE, $playlistName, $mediaType);

        return max(1, min(500, $size));
    }

    /**
     * @param array<int|string,mixed> $items Media item rows.
     * @return array<string,array{0:string,1:string}> Enabled pairs keyed "type|playlist".
     */
    private static function enabledPairs(array $items): array
    {
        $pairs = [];

        foreach ($items as $item) {
            if (!is_array($item) || !Options::rowStoresPosts($item)) {
                continue;
            }

            $args = self::normalizeArgs($item['playlist_name'] ?? '', $item['type'] ?? '');
            if ($args !== null) {
                $pairs[$args[1] . '|' . $args[0]] = $args;
            }
        }

        return $pairs;
    }

    /**
     * Canonicalizes cron arguments.
     *
     * WordPress keys scheduled events by md5(serialize($args)), so every entry
     * point normalizes before checking for and scheduling an event.
     *
     * @param mixed $playlistName Raw playlist name.
     * @param mixed $mediaType    Raw media type.
     * @return array{0:string,1:string}|null Normalized args, or null when unusable.
     */
    private static function normalizeArgs($playlistName, $mediaType): ?array
    {
        $playlist = sanitize_key((string) $playlistName);
        $type     = sanitize_key((string) $mediaType);

        if ($playlist === '' || !in_array($type, MediaStore::SUPPORTED_MEDIA_TYPES, true)) {
            return null;
        }

        return [$playlist, $type];
    }

    /**
     * Builds a zeroed result summary.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return array<string,mixed> The summary shape callers and listeners receive.
     */
    private static function emptyResult(string $playlistName, string $mediaType): array
    {
        return [
            'ok'            => false,
            'playlist_name' => $playlistName,
            'media_type'    => $mediaType,
            'created'       => 0,
            'updated'       => 0,
            'unchanged'     => 0,
            'failed'        => 0,
            'skipped'       => 0,
            'duplicates'    => 0,
            'total_seen'    => 0,
            'pruned'        => 0,
            'prune_failed'  => 0,
            'prune_note'    => '',
            'error'         => null,
        ];
    }

    /**
     * @param array<string,mixed> $result Result being built.
     * @param array<string,mixed> $job    The pass.
     * @param array<string,mixed> $mapped ItemMapper output.
     * @return array<string,mixed> Result with the pass's counts.
     */
    private static function resultFromJob(array $result, array $job, array $mapped): array
    {
        foreach (['created', 'updated', 'unchanged', 'failed', 'duplicates'] as $key) {
            $result[$key] = (int) $job['counts'][$key];
        }

        $result['skipped']      = (int) $mapped['skipped'];
        $result['total_seen']   = count($mapped['records']) + (int) $mapped['skipped'];
        $result['pruned']       = (int) $job['pruned'];
        $result['prune_failed'] = (int) $job['prune_failed'];

        return $result;
    }

    /**
     * Fires the post-synchronization action, absorbing callback exceptions.
     *
     * @param array<string,mixed> $result  The synchronization summary.
     * @param array<string,mixed> $context Playlist, media type, and source.
     * @return void
     */
    private static function fireSynced(array $result, array $context): void
    {
        try {
            /**
             * Fires after a playlist's stored posts have been synchronized.
             *
             * Intended for cache invalidation: a page-builder output cache
             * holding a rendered loop needs purging once the posts change.
             *
             * @param array<string,mixed> $result  Counts, removals, and any WP_Error.
             * @param array<string,mixed> $context playlist_name, media_type, source.
             */
            do_action(self::ACTION_SYNCED, $result, $context);
        } catch (\Throwable $e) {
            // The message is deliberately not logged: a third-party callback's
            // exception text can carry the credentials it was calling out with.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'Media API Widget: a %s callback threw (%s at %s:%d).',
                    self::ACTION_SYNCED,
                    get_class($e),
                    $e->getFile(),
                    $e->getLine()
                ));
            }
        }
    }
}
