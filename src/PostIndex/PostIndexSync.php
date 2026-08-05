<?php

namespace MediaApiWidget\PostIndex;

use MediaApiWidget\Support\MediaStore;
use MediaApiWidget\Support\OptionLock;
use WP_Error;

if (!defined('ABSPATH')) { exit; }

/**
 * Keeps the media post index in step with the canonical media store.
 *
 * Synchronization is deliberately asynchronous. The media pipeline stores data
 * from inside wp_head on ordinary front-end page views, and projecting a
 * several-hundred-episode playlist into posts there would add that work to a
 * visitor's page load. So the listener does almost nothing: it validates two
 * strings and schedules a single WP-Cron event. All the real work happens later,
 * out of band, reading the payload back from storage.
 *
 * Cron arguments carry only the playlist name and media type. Serializing a
 * whole playlist into the cron option would bloat wp_options, and the payload
 * could be stale by the time the event ran anyway — reading it fresh from the
 * store is both smaller and more correct.
 *
 * The sync itself is idempotent: running it repeatedly over the same payload
 * produces no writes beyond the maw_last_seen stamp. That is what makes a
 * duplicated cron event, a manual rebuild racing a scheduled one, or a retry
 * after a fatal all harmless.
 *
 * All methods are static except the hook callbacks, which need to be instance
 * methods to be registered from {@see self::register()}.
 */
final class PostIndexSync
{
    /**
     * Cron hook that performs one playlist's synchronization.
     *
     * @var string
     */
    public const CRON_HOOK = 'maw_sync_post_index';

    /**
     * Action fired after a synchronization completes.
     *
     * @var string
     */
    public const ACTION_SYNCED = 'media_api_widget_post_index_synced';

    /**
     * Context `source` for a synchronization run by WP-Cron.
     *
     * @var string
     */
    public const SOURCE_CRON = 'cron';

    /**
     * Context `source` for a synchronization run by an explicit rebuild.
     *
     * @var string
     */
    public const SOURCE_MANUAL = 'manual_rebuild';

    /**
     * Option-name prefix for the per-playlist synchronization lock.
     *
     * @var string
     */
    public const LOCK_PREFIX = 'maw_post_index_lock_';

    /**
     * How long a synchronization lock stays valid.
     *
     * Much longer than the storage lock's 30 seconds, because a first-time sync
     * of a large playlist performs hundreds of post inserts. A lock that expired
     * mid-run could be stolen by a second worker, and the two would then race to
     * create the same posts.
     *
     * @var int
     */
    public const LOCK_TTL_SECONDS = 300;

    /**
     * Delay before a scheduled synchronization runs.
     *
     * Non-zero for two reasons: it collapses a burst of stores in one request
     * into a single event per playlist, and it guarantees the event fires on a
     * later request where `init` has run and the post type exists.
     *
     * @var int
     */
    private const SCHEDULE_DELAY = 30;

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
     * Schedules a synchronization after media data has been stored.
     *
     * Responds to all three store sources. A manual enrichment write therefore
     * updates the index automatically, which is the point: a transcript written
     * through media_api_widget_update_stored_data() should appear in the indexed
     * post without the integration needing to know the index exists.
     *
     * There is no loop risk. Synchronizing writes posts, post meta, and terms —
     * never stored media data — so it cannot cause the store action to fire
     * again.
     *
     * Deliberately trivial. MediaStore fires this action inside a try/catch that
     * swallows exceptions, so anything thrown here would vanish silently; all
     * the work that can fail belongs behind the cron boundary where it is
     * reported.
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

        if ($args === null) {
            return;
        }

        self::scheduleSync($args[0], $args[1]);
    }

    /**
     * Schedules one deduplicated synchronization event.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return bool True when an event is queued, including one that already was.
     */
    public static function scheduleSync(string $playlistName, string $mediaType): bool
    {
        $args = self::normalizeArgs($playlistName, $mediaType);

        if ($args === null) {
            return false;
        }

        if (wp_next_scheduled(self::CRON_HOOK, $args) !== false) {
            // Already queued. Reported as success so a caller gating on the
            // return value — the backfill, notably — does not treat a
            // successfully-pending job as a failure worth retrying.
            return true;
        }

        return wp_schedule_single_event(time() + self::SCHEDULE_DELAY, self::CRON_HOOK, $args) !== false;
    }

    /**
     * Canonicalizes cron arguments.
     *
     * WordPress keys scheduled events by md5(serialize($args)), so two callers
     * passing 'My_Show' and 'my_show' would hash differently and queue two
     * events for one playlist. Running the same normalizer before both the
     * duplicate check and the schedule call is what makes deduplication actually
     * work, so every entry point uses this.
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
     * Runs a scheduled synchronization.
     *
     * @param mixed $playlistName Playlist name from the cron arguments.
     * @param mixed $mediaType    Media type from the cron arguments.
     * @return void
     */
    public function runScheduled($playlistName = '', $mediaType = ''): void
    {
        $args = self::normalizeArgs($playlistName, $mediaType);

        if ($args === null) {
            return;
        }

        // Refuse to insert into a post type that does not exist: the rows would
        // be invisible to every query and every admin screen.
        if (!PostIndex::isRegistered()) {
            return;
        }

        self::sync($args[0], $args[1], self::SOURCE_CRON);
    }

    /**
     * Synchronizes one playlist's indexed posts from stored data.
     *
     * Holds a per-playlist lock for the whole run, so a scheduled sync and a
     * manual rebuild cannot both decide an item is missing and both create it.
     * The lock is a separate namespace from the storage lock on purpose: sharing
     * it would let a long sync block a refresh from persisting, and the storage
     * lock's short expiry is documented as safe only because no slow work
     * happens under it.
     *
     * Reads exclusively through media_api_widget_get_stored_data(), which
     * prefers the transient and falls back to the backup file. No external
     * request is made on any path.
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
            $result['error'] = new WP_Error(
                'maw_invalid_playlist_name',
                'A usable playlist name and media type are required.'
            );

            return $result;
        }

        [$playlistName, $mediaType] = $args;

        $result = self::emptyResult($playlistName, $mediaType);

        if (!PostIndex::isRegistered()) {
            $result['error'] = new WP_Error(
                'maw_post_index_unregistered',
                'The media item post type is not registered yet.'
            );

            return $result;
        }

        $lock = OptionLock::acquire(self::lockName($mediaType, $playlistName), self::LOCK_TTL_SECONDS);

        if ($lock === null) {
            $result['error'] = new WP_Error(
                'maw_post_index_locked',
                'A post index synchronization is already running for this playlist.'
            );

            return $result;
        }

        try {
            $data = media_api_widget_get_stored_data($playlistName, $mediaType);

            if ($data === null) {
                $result['error'] = new WP_Error(
                    'maw_no_stored_data',
                    'No stored data was found for this playlist. Nothing was indexed.'
                );

                return $result;
            }

            $mapped  = ItemMapper::fromStored($data, $playlistName, $mediaType);
            $records = $mapped['records'];
            $now     = time();

            $result['skipped']    = $mapped['skipped'];
            $result['total_seen'] = count($records) + $mapped['skipped'];

            foreach ($records as $record) {
                $outcome = PostUpserter::upsert($record, $playlistName, $mediaType, $now);

                $status = $outcome['status'];
                if (isset($result[$status])) {
                    $result[$status]++;
                }

                $result['duplicates'] += (int) $outcome['duplicates'];
            }

            $result['ok'] = $result['failed'] === 0;

            self::fireSynced($result, [
                'playlist_name' => $playlistName,
                'media_type'    => $mediaType,
                'source'        => $source,
            ]);

            return $result;
        } finally {
            // Released on success, on every early return, and on a thrown
            // exception, so a fatal mid-sync cannot wedge future runs.
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
            'error'         => null,
        ];
    }

    /**
     * Fires the post-synchronization action, absorbing callback exceptions.
     *
     * Post-commit by contract: the posts are already written, so a misbehaving
     * cache-purge integration must not be able to make a completed sync look
     * like a failure or abort a cron run part-way through a playlist.
     *
     * @param array<string,mixed> $result  The synchronization summary.
     * @param array<string,mixed> $context Playlist, media type, and source.
     * @return void
     */
    private static function fireSynced(array $result, array $context): void
    {
        try {
            /**
             * Fires after the media post index has been synchronized.
             *
             * Intended for cache invalidation: a page-builder output cache
             * holding a rendered loop needs purging once the underlying posts
             * change. Keeping this an action rather than a hard dependency is
             * what lets builder- and host-specific integrations live outside the
             * plugin.
             *
             * @param array<string,mixed> $result  Counts and any WP_Error.
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
