<?php

namespace MediaApiWidget\Support;

use MediaApiWidget\Config\Options;
use MediaApiWidget\Stats\BackupInventory;
use WP_Error;

if (!defined('ABSPATH')) { exit; }

/**
 * The single place successful media data becomes persisted state.
 *
 * Before 5.0.0 three unrelated code paths wrote media data inline — the YouTube
 * refresh, the podcast refresh, and the shortcode cache warm-up — each with its
 * own TTL, its own backup behavior, and its own serialization. A hook added to
 * one of them would have been silently bypassed by the other two. All three now
 * funnel through {@see self::store()}, and the stored-data updater funnels
 * through {@see self::persist()}, so the developer hooks cannot be bypassed and
 * the storage rules have one definition.
 *
 * Two extension points fire from here:
 *
 * - `media_api_widget_data_before_store` (filter) — receives the parsed payload
 *   and a context array immediately before anything is written. Its return
 *   value is validated and is then the exact value written to storage and used
 *   for the current response.
 * - `media_api_widget_data_stored` (action) — fires after a successful write.
 *   Post-commit: a callback that throws is logged and discarded, and can never
 *   turn a completed store into a reported failure.
 *
 * Storage is *ordered*, not transactional. A filesystem write and a database
 * write cannot be one atomic operation, so instead:
 *
 *   1. Both payloads are JSON-encoded first, and an encoding failure rejects the
 *      whole store before anything is written.
 *   2. The transient is written before the backup file, so the common failure
 *      leaves nothing written at all rather than a backup ahead of the cache.
 *   3. The backup file is replaced by an atomic rename, so a failed write can
 *      never truncate or corrupt the previous good backup.
 *
 * The result is that every failure mode lands on either the previous good data
 * or the intended new data — never a torn payload — which is what the caller's
 * "a failed or partial refresh never overwrites good data" guarantee needs.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class MediaStore
{
    /**
     * Filter fired once per complete, successful logical remote refresh,
     * immediately before the parsed data is written to server storage.
     *
     * @var string
     */
    public const FILTER_BEFORE_STORE = 'media_api_widget_data_before_store';

    /**
     * Action fired after media data has been written to server storage.
     *
     * @var string
     */
    public const ACTION_STORED = 'media_api_widget_data_stored';

    /**
     * Context `source` for a completed remote API refresh (wp_head pipeline).
     *
     * @var string
     */
    public const SOURCE_REMOTE_REFRESH = 'remote_refresh';

    /**
     * Context `source` for the shortcode podcast cache warm-up path.
     *
     * @var string
     */
    public const SOURCE_SHORTCODE_WARMUP = 'shortcode_warmup';

    /**
     * Context `source` for a write made by media_api_widget_update_stored_data().
     *
     * @var string
     */
    public const SOURCE_MANUAL_UPDATE = 'manual_update';

    /**
     * Media types this store accepts.
     *
     * @var array<int,string>
     */
    public const SUPPORTED_MEDIA_TYPES = ['youtube', 'podcast'];

    /**
     * Storage lock names currently held by this PHP process.
     *
     * The stored-data updater holds the lock across its whole read-modify-write
     * and then calls {@see self::persist()}, which would otherwise try to take
     * the same lock and fail against itself. Tracking held names makes that
     * nested acquire a no-op. PHP serves one request per process, so an
     * in-process set is sufficient — cross-process exclusion is still the
     * wp_options row.
     *
     * @var array<string,bool>
     */
    private static array $heldLocks = [];

    /**
     * Builds the context array passed to both the filter and the action.
     *
     * The shape is documented and stable: integrations may rely on all four
     * keys always being present. Nothing secret is ever placed here — no API
     * key, no credential, no request URL, and no response body — because hook
     * context is visible to every callback on the site.
     *
     * @param string      $playlistName    The playlist_name slug.
     * @param string      $mediaType       'youtube' or 'podcast'.
     * @param string      $source          One of the SOURCE_* constants.
     * @param string|null $podcastPlatform Podcast platform slug, or null for YouTube.
     * @return array{playlist_name:string,media_type:string,source:string,podcast_platform:string|null}
     */
    public static function buildContext(
        string $playlistName,
        string $mediaType,
        string $source,
        ?string $podcastPlatform = null
    ): array {
        return [
            'playlist_name'    => $playlistName,
            'media_type'       => $mediaType,
            'source'           => $source,
            'podcast_platform' => $podcastPlatform,
        ];
    }

    /**
     * Filters, validates, and persists media data from a successful refresh.
     *
     * Call this only after a *complete* logical refresh has succeeded. For a
     * paginated YouTube playlist that means after every page has been fetched
     * and the playlist parsed — not once per page. Failed requests, partial
     * refreshes, malformed responses, and cache or backup reads must never
     * reach this method, because reaching it is what fires the filter.
     *
     * @param array{playlist_name:string,media_type:string,source:string,podcast_platform:string|null} $context
     *        Context from {@see self::buildContext()}.
     * @param mixed                $data    Parsed payload: a list of item arrays
     *                                      for YouTube, a normalized array tree
     *                                      for podcast.
     * @param array<string,mixed>  $options 'ttl' (int, defaults to the configured
     *                                      media cache TTL), 'write_backup' (bool,
     *                                      default true), 'now' (?int).
     * @return array{ok:bool,wrote:bool,data:mixed,error:\WP_Error|null}
     *         `ok` is true only when everything was written. `wrote` is true when
     *         the transient was updated, which stays true for a partial persist
     *         (transient written, backup write failed) so the caller can still
     *         render the filtered value while treating the refresh as unfinished.
     */
    public static function store(array $context, $data, array $options = []): array
    {
        return self::write($context, $data, $options, true);
    }

    /**
     * Validates and persists media data without firing the pre-store filter.
     *
     * Used by media_api_widget_update_stored_data(), where the payload has
     * already been produced by the caller's own mutator. Skipping the filter is
     * what keeps a manual update from recursively re-entering the
     * remote-refresh filter. Validation, the encodability gate, both writes, and
     * the post-storage action are identical to {@see self::store()}.
     *
     * @param array{playlist_name:string,media_type:string,source:string,podcast_platform:string|null} $context
     * @param mixed               $data
     * @param array<string,mixed> $options
     * @return array{ok:bool,wrote:bool,data:mixed,error:\WP_Error|null}
     */
    public static function persist(array $context, $data, array $options = []): array
    {
        return self::write($context, $data, $options, false);
    }

    /**
     * Runs a callback while holding the per-playlist/media storage lock.
     *
     * Both the refresh paths and the stored-data updater take this lock, so a
     * refresh and a manual update can never interleave their writes or leave the
     * transient and the backup file disagreeing with each other. The refresh
     * paths hold it only around the persist sequence — never around the outbound
     * fetch — so a long multi-page refresh cannot block enrichment writes.
     *
     * Note the boundary of what this guarantees: it serializes writes, but a
     * remote refresh *replaces* the payload with whatever the API returned, so
     * fields a manual update added are simply absent from the new response. Use
     * {@see self::FILTER_BEFORE_STORE} to re-apply derived fields on every
     * refresh, and keep large authoritative data in your own storage keyed by
     * video id or episode GUID.
     *
     * The lock is released on every exit path, including a thrown exception. A
     * nested call for a lock this process already holds runs the callback
     * directly rather than deadlocking against itself.
     *
     * @param string   $mediaType    'youtube' or 'podcast'.
     * @param string   $playlistName The playlist_name slug.
     * @param callable $callback     Receives no arguments; its return value is returned.
     * @return mixed The callback's return value, or a WP_Error when the lock is held elsewhere.
     */
    public static function withDataLock(string $mediaType, string $playlistName, callable $callback)
    {
        $name = OptionLock::dataLockName($mediaType, $playlistName);

        if (isset(self::$heldLocks[$name])) {
            return $callback();
        }

        $lock = OptionLock::acquire($name, OptionLock::DATA_LOCK_TTL_SECONDS);

        if ($lock === null) {
            return new WP_Error(
                'maw_data_locked',
                'Another process is currently writing stored data for this playlist.'
            );
        }

        self::$heldLocks[$name] = true;

        try {
            return $callback();
        } finally {
            unset(self::$heldLocks[$name]);
            OptionLock::release($lock);
        }
    }

    /**
     * Converts parsed podcast RSS into the documented plain PHP array shape.
     *
     * The RSS parser returns a SimpleXMLElement, which is convenient for reading
     * but a poor thing to hand to a third-party callback: property access is
     * magic, values are SimpleXMLElement rather than string, and mutating it
     * requires knowing the XML semantics. Round-tripping through JSON produces
     * the plain nested array integrations can inspect and modify normally — and
     * is already the shape every reader in the plugin decodes to.
     *
     * @param mixed $parsed A SimpleXMLElement, or an array that already matches.
     * @return array<string,mixed>|null Normalized tree, or null when unusable.
     */
    public static function normalizePodcastData($parsed): ?array
    {
        if (is_array($parsed)) {
            return $parsed;
        }

        if (!$parsed instanceof \SimpleXMLElement) {
            return null;
        }

        $encoded = json_encode($parsed);
        if (!is_string($encoded)) {
            return null;
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Validates a payload against the documented shape for its media type.
     *
     * Only the container is inspected, never individual item keys, so arbitrary
     * custom keys a filter or mutator adds to an item are preserved rather than
     * stripped. That is deliberate: adding metadata to items is the whole point
     * of the extension API.
     *
     * @param mixed  $data      The payload to check.
     * @param string $mediaType 'youtube' or 'podcast'.
     * @return \WP_Error|null Null when the payload is valid.
     */
    public static function validate($data, string $mediaType): ?WP_Error
    {
        if ($mediaType === 'youtube') {
            if (!is_array($data) || !array_is_list($data)) {
                return new WP_Error(
                    'maw_invalid_youtube_data',
                    'YouTube data must be a list array of media item arrays.'
                );
            }

            foreach ($data as $item) {
                if (!is_array($item)) {
                    return new WP_Error(
                        'maw_invalid_youtube_data',
                        'Every YouTube media item must be an array.'
                    );
                }
            }

            // An empty list is accepted: a playlist with no items parses to []
            // today and is stored, so rejecting it would change behavior.
            return null;
        }

        if ($mediaType === 'podcast') {
            if (!is_array($data) || !isset($data['channel']) || !is_array($data['channel'])) {
                return new WP_Error(
                    'maw_invalid_podcast_data',
                    'Podcast data must be an array containing a channel array.'
                );
            }

            // channel.item is deliberately not required to exist or to be a
            // list: a single-episode feed serializes it as an associative array
            // and a feed with no episodes yet is still valid.
            return null;
        }

        return new WP_Error(
            'maw_unsupported_media_type',
            'Unsupported media type. Expected youtube or podcast.'
        );
    }

    /**
     * Returns the transient name holding a playlist's media data.
     *
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param string $playlistName The playlist_name slug.
     * @return string e.g. 'youtube_my_show'.
     */
    public static function transientName(string $mediaType, string $playlistName): string
    {
        return $mediaType . '_' . $playlistName;
    }

    /**
     * Reads the current canonical stored data for a playlist.
     *
     * Prefers the transient, falling back to the backup JSON file, matching the
     * precedence the front-end read paths use. Makes no external request and
     * never triggers a refresh, so it is safe to call from a cron job or a
     * background worker.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return mixed Decoded data, or null when nothing usable is stored.
     */
    public static function readStored(string $playlistName, string $mediaType)
    {
        $cached = get_transient(self::transientName($mediaType, $playlistName));

        if ($cached !== false && $cached !== null) {
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if ($decoded !== null) {
                    return $decoded;
                }
            } elseif (is_array($cached)) {
                return $cached;
            }
        }

        $path = BackupFiles::filePath($playlistName, $mediaType);

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
            return null;
        }

        return $decoded['data'];
    }

    /**
     * Looks up the configured podcast platform for a playlist.
     *
     * Used so the manual-update context carries the same `podcast_platform`
     * value a refresh would supply. Returns null when the playlist is not a
     * configured podcast item, which keeps the context key present but empty
     * rather than absent.
     *
     * @param string $playlistName The playlist_name slug.
     * @return string|null Platform slug, or null when unknown.
     */
    public static function lookupPodcastPlatform(string $playlistName): ?string
    {
        $items = Options::getMediaItems();

        // Back-compat: if a theme/WPCode still defines MEDIA_CONTENT_DATA, merge it in.
        if (defined('MEDIA_CONTENT_DATA') && is_array(constant('MEDIA_CONTENT_DATA'))) {
            $items = array_merge($items, constant('MEDIA_CONTENT_DATA'));
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (sanitize_key((string) ($item['playlist_name'] ?? '')) !== sanitize_key($playlistName)) {
                continue;
            }

            if (sanitize_key((string) ($item['type'] ?? '')) !== 'podcast') {
                continue;
            }

            $platform = sanitize_key((string) ($item['podcast_platform'] ?? ''));

            return $platform === '' ? null : $platform;
        }

        return null;
    }

    /**
     * Shared implementation behind {@see self::store()} and {@see self::persist()}.
     *
     * @param array<string,mixed> $context
     * @param mixed               $data
     * @param array<string,mixed> $options
     * @param bool                $applyFilter Whether to fire the pre-store filter.
     * @return array{ok:bool,wrote:bool,data:mixed,error:\WP_Error|null}
     */
    private static function write(array $context, $data, array $options, bool $applyFilter): array
    {
        $playlistName = (string) ($context['playlist_name'] ?? '');
        $mediaType    = (string) ($context['media_type'] ?? '');

        if (!in_array($mediaType, self::SUPPORTED_MEDIA_TYPES, true)) {
            return self::reject(new WP_Error(
                'maw_unsupported_media_type',
                'Unsupported media type. Expected youtube or podcast.'
            ));
        }

        if ($playlistName === '') {
            return self::reject(new WP_Error(
                'maw_invalid_playlist_name',
                'A playlist name is required to store media data.'
            ));
        }

        $result = self::withDataLock($mediaType, $playlistName, static function () use (
            $context,
            $data,
            $options,
            $applyFilter,
            $playlistName,
            $mediaType
        ): array {
            return self::writeLocked($context, $data, $options, $applyFilter, $playlistName, $mediaType);
        });

        // withDataLock() returns a WP_Error rather than the callback's array
        // when the lock is held by another process.
        if (is_wp_error($result)) {
            return self::reject($result);
        }

        return $result;
    }

    /**
     * Performs the filter, validation, encoding, and both writes under the lock.
     *
     * @param array<string,mixed> $context
     * @param mixed               $data
     * @param array<string,mixed> $options
     * @return array{ok:bool,wrote:bool,data:mixed,error:\WP_Error|null}
     */
    private static function writeLocked(
        array $context,
        $data,
        array $options,
        bool $applyFilter,
        string $playlistName,
        string $mediaType
    ): array {
        $now         = isset($options['now']) ? (int) $options['now'] : time();
        $writeBackup = !array_key_exists('write_backup', $options) || (bool) $options['write_backup'];
        $ttl         = isset($options['ttl'])
            ? max(1, (int) $options['ttl'])
            : max(1, (int) (Options::getCacheExpirations()['media_cache_ttl'] ?? 7200));

        // Validate what came in before exposing it, so a callback is never handed
        // a payload the plugin itself considers malformed.
        $invalidInput = self::validate($data, $mediaType);
        if ($invalidInput instanceof WP_Error) {
            // On the filter path this means the plugin's own parse produced
            // something invalid, which is worth distinguishing. On the mutator
            // path the incoming value *is* the caller's return value, so the
            // specific validation code is what they need to see.
            if (!$applyFilter) {
                return self::reject($invalidInput);
            }

            return self::reject(new WP_Error(
                'maw_invalid_pre_store_data',
                'Parsed media data was invalid before storage: ' . $invalidInput->get_error_message()
            ));
        }

        $filtered = $data;

        if ($applyFilter) {
            try {
                $filtered = apply_filters(self::FILTER_BEFORE_STORE, $data, $context);
            } catch (\Throwable $e) {
                // A third-party callback throwing inside wp_head must not take
                // the page down, and must not overwrite good stored data.
                self::logThrowable('A ' . self::FILTER_BEFORE_STORE . ' callback threw', $e);

                return self::reject(new WP_Error(
                    'maw_filter_threw',
                    'A media_api_widget_data_before_store callback threw an exception.'
                ));
            }

            if (is_wp_error($filtered)) {
                return self::reject($filtered);
            }

            $invalidFiltered = self::validate($filtered, $mediaType);
            if ($invalidFiltered instanceof WP_Error) {
                return self::reject($invalidFiltered);
            }
        }

        // Encodability gate. Checked unconditionally, even when no backup will
        // be written, because data that cannot be JSON encoded cannot survive a
        // round trip through either store and must be treated as a failure.
        $backupJson = wp_json_encode(['time_stored' => $now, 'data' => $filtered]);
        if (!is_string($backupJson)) {
            return self::reject(new WP_Error(
                'maw_json_encode_failed',
                'Media data could not be JSON encoded and was not stored.'
            ));
        }

        $transientValue = $filtered;

        if ($mediaType === 'podcast') {
            $encoded = wp_json_encode($filtered);
            if (!is_string($encoded)) {
                return self::reject(new WP_Error(
                    'maw_json_encode_failed',
                    'Podcast data could not be JSON encoded and was not stored.'
                ));
            }
            $transientValue = $encoded;
        }

        // Transient first: a failure here leaves nothing written at all, which
        // is the outcome that best preserves previously good data.
        if (!self::writeTransient($mediaType, $playlistName, $transientValue, $ttl)) {
            return self::reject(new WP_Error(
                'maw_transient_write_failed',
                'The media data transient could not be written.'
            ));
        }

        if ($writeBackup && !self::writeBackup($playlistName, $mediaType, $backupJson)) {
            // Partial persist: the transient holds the intended value, and the
            // previous backup is untouched and still valid. Reported as a
            // failure so a refresh is not finalized, but the data is returned so
            // the current response can still use the filtered value.
            //
            // The stored action deliberately does not fire here. The refresh will
            // be retried, and firing now would hand an integration the same
            // payload twice — once for the incomplete store and again when the
            // retry succeeds.
            return [
                'ok'    => false,
                'wrote' => true,
                'data'  => $filtered,
                'error' => new WP_Error(
                    'maw_backup_write_failed',
                    'The media data backup file could not be written.'
                ),
            ];
        }

        self::fireStored($filtered, $context);

        return ['ok' => true, 'wrote' => true, 'data' => $filtered, 'error' => null];
    }

    /**
     * Writes the media data transient, disambiguating a false return.
     *
     * set_transient() returns false both when the write genuinely failed and
     * when the stored value was already byte-identical, because WordPress routes
     * it to update_option(), which reports no-change as failure. Treating that
     * as a failure would reject a correct store, so a false return is resolved
     * by reading the value back and comparing it to the intended one.
     *
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param string $playlistName The playlist_name slug.
     * @param mixed  $value        Native array for YouTube, JSON string for podcast.
     * @param int    $ttl          Transient lifetime in seconds.
     * @return bool True when storage holds the intended value afterwards.
     */
    private static function writeTransient(string $mediaType, string $playlistName, $value, int $ttl): bool
    {
        $name = self::transientName($mediaType, $playlistName);

        if (set_transient($name, $value, $ttl)) {
            return true;
        }

        $stored = get_transient($name);

        if ($stored === false) {
            return false;
        }

        return wp_json_encode($stored) === wp_json_encode($value);
    }

    /**
     * Replaces a playlist's backup JSON file atomically.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param string $json         Encoded `{"time_stored":…,"data":…}` wrapper.
     * @return bool True when the file now holds the new payload.
     */
    private static function writeBackup(string $playlistName, string $mediaType, string $json): bool
    {
        $path = BackupFiles::filePath($playlistName, $mediaType);

        if ($path === '') {
            return false;
        }

        return self::atomicWrite($path, $json);
    }

    /**
     * Writes a file by creating a sibling temp file and renaming it into place.
     *
     * rename() within one directory is atomic, so a reader either sees the whole
     * old file or the whole new one — never a half-written payload. That matters
     * because the admin Stats page and the front-end fallback both read these
     * files while a refresh may be writing them.
     *
     * The temp file is a sibling with a `.tmp.json` suffix rather than a
     * tempnam() file: it is guaranteed to be on the same filesystem (so the
     * rename really is atomic), it inherits the normal umask instead of
     * tempnam()'s 0600 (which would leave it unreadable to other PHP workers),
     * and it is swept by the same `*.json` cleanup the test suite already runs.
     *
     * @param string $path     Destination absolute path.
     * @param string $contents Bytes to write.
     * @return bool True when the destination was replaced.
     */
    private static function atomicWrite(string $path, string $contents): bool
    {
        $tmp = $path . '.' . getmypid() . '-' . uniqid('', true) . '.tmp.json';

        $written = @file_put_contents($tmp, $contents, LOCK_EX);

        if ($written !== strlen($contents)) {
            @unlink($tmp);

            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        // The Stats page memoizes backup metadata for the life of a request, so
        // a file replaced mid-request must invalidate that memo.
        BackupInventory::flushCache();

        return true;
    }

    /**
     * Fires the post-storage action, absorbing anything a callback throws.
     *
     * The action is post-commit by contract: the data is already stored, so a
     * misbehaving integration must not be able to make a completed store look
     * like a failed one, block a refresh from finalizing, or white-screen a
     * front-end page view.
     *
     * @param mixed               $data    The stored payload.
     * @param array<string,mixed> $context Hook context.
     * @return void
     */
    private static function fireStored($data, array $context): void
    {
        try {
            do_action(self::ACTION_STORED, $data, $context);
        } catch (\Throwable $e) {
            self::logThrowable('A ' . self::ACTION_STORED . ' callback threw', $e);
        }
    }

    /**
     * Builds a rejection result: nothing was written.
     *
     * @param \WP_Error $error Why the store was refused.
     * @return array{ok:bool,wrote:bool,data:mixed,error:\WP_Error}
     */
    private static function reject(WP_Error $error): array
    {
        return ['ok' => false, 'wrote' => false, 'data' => null, 'error' => $error];
    }

    /**
     * Logs a callback exception without echoing its message.
     *
     * The class, file, and line are recorded; the message deliberately is not.
     * A third-party callback's exception message can easily contain the API
     * credentials it was calling out with, and a debug log is not the place to
     * copy them to.
     *
     * @param string     $prefix Short description of where the throw happened.
     * @param \Throwable $e      The caught exception.
     * @return void
     */
    private static function logThrowable(string $prefix, \Throwable $e): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log(sprintf(
            'Media API Widget: %s (%s at %s:%d).',
            $prefix,
            get_class($e),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
