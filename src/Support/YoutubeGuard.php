<?php
namespace MediaApiWidget\Support;

if (!defined('ABSPATH')) { exit; }

/**
 * Quota and concurrency guards for outbound YouTube Data API requests.
 *
 * This class exists because the plugin fetches playlists from inside wp_head
 * on ordinary front-end page views. Anything that can loop, or that can be
 * entered by several PHP workers at once, multiplies straight into billed API
 * quota. Three independent protections live here:
 *
 *   1. A persistent per-site daily call counter (circuit breaker) that is
 *      reserved *before* a request goes outbound, so requests that fail still
 *      consume their slot — failed YouTube calls can still cost quota.
 *   2. A genuinely atomic per-playlist refresh lock, so simultaneous cache
 *      misses cannot all start fetching the same playlist.
 *   3. A single sanitized "guard event" record per aborted refresh, so an
 *      administrator can see why fetching stopped without any secret being
 *      written anywhere.
 *
 * Both the counter and the lock are backed by direct SQL against wp_options
 * rather than the Options API. The reason is correctness, not speed:
 *
 *   - get_option() is served from the object cache, so it cannot observe an
 *     increment made by a different PHP worker in the same second.
 *   - add_option() is *not* an atomic mutex. WordPress core implements it as
 *     an `INSERT ... ON DUPLICATE KEY UPDATE` guarded by a cached read, so two
 *     concurrent callers can both believe they created the row.
 *
 * A bare `INSERT` that is allowed to fail on the `option_name` unique index is
 * a real compare-and-set, and `INSERT ... ON DUPLICATE KEY UPDATE value+1` is
 * a real atomic increment. When those statements cannot run at all (unusual
 * database privileges), every method degrades to permitting the operation
 * rather than blocking legitimate refreshes.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class YoutubeGuard
{
    /**
     * wp_options key prefix for the per-quota-day outbound call counter.
     * The full name is built by {@see self::dailyCounterOptionName()}.
     *
     * @var string
     */
    private const OPTION_DAILY_PREFIX = 'maw_yt_calls_';

    /**
     * wp_options key prefix for the per-playlist atomic refresh lock.
     *
     * @var string
     */
    private const OPTION_LOCK_PREFIX = 'maw_yt_lock_';

    /**
     * wp_options key holding the most recent sanitized guard event.
     *
     * @var string
     */
    private const OPTION_GUARD_STATUS = 'maw_yt_guard_status';

    /**
     * wp_options key holding the GMT timestamp of the last counter cleanup.
     *
     * @var string
     */
    private const OPTION_LAST_CLEANUP = 'maw_yt_calls_last_cleanup';

    /**
     * Minimum interval, in seconds, between obsolete-counter cleanup passes.
     *
     * @var int
     */
    private const CLEANUP_INTERVAL_SECONDS = 3600;

    /**
     * The timezone YouTube resets Data API quota in (midnight Pacific).
     *
     * @var string
     */
    public const QUOTA_TIMEZONE = 'America/Los_Angeles';

    /**
     * Every guard reason that may be persisted or displayed.
     *
     * Recording is restricted to this list so a reason string can never carry
     * an API key, a request URL, or any fragment of a response body.
     *
     * @var array<int,string>
     */
    public const REASONS = [
        'repeated_page_token',
        'empty_page_with_next_token',
        'malformed_response',
        'maximum_pages_reached',
        'daily_limit_reached',
        'concurrent_refresh',
        'http_error',
    ];

    // ---------------------------------------------------------------------
    // Daily circuit breaker
    // ---------------------------------------------------------------------

    /**
     * Reserves one outbound YouTube request against today's call budget.
     *
     * Must be called immediately before each request is sent, and the result
     * honored: a false return means no request may go outbound. Reserving up
     * front (rather than counting successes afterwards) is deliberate, because
     * a YouTube call that returns an error can still have consumed quota.
     *
     * Concurrency: the pre-check is a direct uncached read and the reservation
     * itself is a single atomic SQL increment, so parallel PHP workers can
     * overshoot the limit only by roughly the number of workers that were
     * inside this method at the same instant — never by an unbounded amount.
     *
     * @param int      $limit Maximum outbound YouTube requests allowed per quota day.
     * @param int|null $now   Unix timestamp to evaluate the quota day against.
     *                        Defaults to the current time.
     * @return bool True when a slot was reserved and the request may be sent.
     */
    public static function reserveDailyCall(int $limit, ?int $now = null): bool
    {
        $limit = max(1, $limit);
        $now   = $now ?? time();

        self::maybeCleanupObsoleteCounters($now);

        $option = self::dailyCounterOptionName($now);
        $used   = self::readCounter($option);

        // Already at or over budget: block without consuming another slot, so
        // repeated blocked page views cannot inflate the counter.
        if ($used !== null && $used >= $limit) {
            return false;
        }

        $reserved = self::incrementCounter($option);

        // The increment could not be read back (no usable direct SQL). Allow
        // the request rather than blocking every refresh on this install.
        if ($reserved === null) {
            return true;
        }

        return $reserved <= $limit;
    }

    /**
     * Returns how many outbound YouTube requests have been counted today.
     *
     * @param int|null $now Unix timestamp to evaluate the quota day against.
     * @return int Reserved call count for the current quota day (0 when unset).
     */
    public static function getDailyCallCount(?int $now = null): int
    {
        return max(0, (int) self::readCounter(self::dailyCounterOptionName($now ?? time())));
    }

    /**
     * Returns the current YouTube quota day as a Y-m-d string in Pacific time.
     *
     * @param int|null $now Unix timestamp to evaluate.
     * @return string e.g. '2026-07-28'.
     */
    public static function getQuotaDayLabel(?int $now = null): string
    {
        return self::quotaDay($now ?? time(), 'Y-m-d');
    }

    /**
     * Returns the wp_options key holding a given quota day's call counter.
     *
     * @param int $timestamp Unix timestamp to resolve the quota day from.
     * @return string e.g. 'maw_yt_calls_20260728'.
     */
    public static function dailyCounterOptionName(int $timestamp): string
    {
        return self::OPTION_DAILY_PREFIX . self::quotaDay($timestamp, 'Ymd');
    }

    /**
     * Formats a Unix timestamp as a date in the YouTube quota timezone.
     *
     * Converting the timestamp into the Pacific zone and formatting a wall-clock
     * date means the rollover always lands on local midnight, and daylight-saving
     * transitions are handled by the timezone database rather than by arithmetic.
     *
     * @param int    $timestamp Unix timestamp.
     * @param string $format    Any date() format string.
     * @return string Formatted date in the quota timezone.
     */
    private static function quotaDay(int $timestamp, string $format): string
    {
        try {
            $zone = new \DateTimeZone(self::QUOTA_TIMEZONE);
        } catch (\Exception $e) {
            $zone = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format($format);
    }

    /**
     * Reads a counter option directly from the database, bypassing the cache.
     *
     * @param string $option wp_options key to read.
     * @return int|null Current value, or null when absent or unreadable.
     */
    private static function readCounter(string $option): ?int
    {
        global $wpdb;

        if (!is_object($wpdb)) {
            return null;
        }

        $value = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option)
        );

        return $value === null ? null : (int) $value;
    }

    /**
     * Atomically increments a counter option by one and returns the new value.
     *
     * @param string $option wp_options key to increment.
     * @return int|null New value after the increment, or null when the atomic
     *                  statement could not be executed.
     */
    private static function incrementCounter(string $option): ?int
    {
        global $wpdb;

        if (!is_object($wpdb)) {
            return null;
        }

        $sql = $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
            $option
        );

        $suppressed = $wpdb->suppress_errors(true);
        $result     = $wpdb->query($sql);
        $wpdb->suppress_errors($suppressed);

        self::forgetOption($option);

        if ($result === false) {
            return null;
        }

        return self::readCounter($option);
    }

    /**
     * Deletes counter options from quota days before yesterday.
     *
     * Runs at most once per {@see self::CLEANUP_INTERVAL_SECONDS} so wp_options
     * cannot accumulate one permanent row per day of the site's lifetime, while
     * still leaving yesterday's figure available for diagnostics. The option
     * names embed a YYYYMMDD date, which sorts lexicographically, so the cutoff
     * is a plain string comparison.
     *
     * @param int $now Unix timestamp for the current request.
     * @return void
     */
    private static function maybeCleanupObsoleteCounters(int $now): void
    {
        global $wpdb;

        $lastCleanup = (int) get_option(self::OPTION_LAST_CLEANUP, 0);
        if ($lastCleanup > 0 && ($now - $lastCleanup) < self::CLEANUP_INTERVAL_SECONDS) {
            return;
        }

        update_option(self::OPTION_LAST_CLEANUP, $now, false);

        if (!is_object($wpdb)) {
            return;
        }

        $cutoff = self::OPTION_DAILY_PREFIX . self::quotaDay($now - DAY_IN_SECONDS, 'Ymd');

        $suppressed = $wpdb->suppress_errors(true);
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
                self::OPTION_DAILY_PREFIX . '________',
                $cutoff
            )
        );
        $wpdb->suppress_errors($suppressed);
    }

    // ---------------------------------------------------------------------
    // Atomic per-playlist refresh lock
    // ---------------------------------------------------------------------

    /**
     * Attempts to acquire the exclusive refresh lock for one playlist.
     *
     * The lock is a wp_options row created by a bare INSERT, which fails on the
     * unique `option_name` index when another worker already holds it — a true
     * compare-and-set. The stored value carries an owner token and an expiry so
     * a worker killed mid-refresh (a PHP timeout, for instance) cannot block
     * refreshes permanently: the next caller sees the expiry has passed and
     * steals the lock with a compare-and-swap against the exact stale value.
     *
     * @param string $playlistName Playlist slug the lock protects.
     * @param int    $ttlSeconds   How long the lock stays valid before it may be stolen.
     * @param int|null $now        Unix timestamp to evaluate expiry against.
     * @return array<string,mixed>|null Lock handle to pass to {@see self::releaseLock()},
     *                                  or null when another worker holds a live lock.
     */
    public static function acquireLock(string $playlistName, int $ttlSeconds, ?int $now = null): ?array
    {
        global $wpdb;

        $now  = $now ?? time();
        $ttl  = max(1, $ttlSeconds);
        $name = self::lockOptionName($playlistName);

        // No usable database handle: fall back to a handle that releases as a
        // no-op. The legacy in-progress transient remains as a soft guard.
        if (!is_object($wpdb)) {
            return ['name' => $name, 'value' => '', 'fallback' => true];
        }

        $value = self::buildLockValue($now + $ttl);

        if (self::insertLockRow($name, $value)) {
            return ['name' => $name, 'value' => $value, 'fallback' => false];
        }

        $existing = self::readRawOption($name);

        // The INSERT failed but no row exists, so the failure was environmental
        // rather than a genuine conflict. Degrade to permitting the refresh.
        if ($existing === null) {
            return ['name' => $name, 'value' => '', 'fallback' => true];
        }

        if (!self::isLockExpired($existing, $now)) {
            return null;
        }

        // Stale or unparseable lock: steal it, but only if nobody else changed
        // the row in the meantime.
        $stolen = self::compareAndSwapOption($name, $existing, $value);

        return $stolen ? ['name' => $name, 'value' => $value, 'fallback' => false] : null;
    }

    /**
     * Releases a lock previously returned by {@see self::acquireLock()}.
     *
     * The DELETE matches on the stored value as well as the name, so a worker
     * whose lock was already stolen after expiring cannot delete the new
     * owner's lock. Safe to call with null or a fallback handle.
     *
     * @param array<string,mixed>|null $lock Lock handle, or null.
     * @return void
     */
    public static function releaseLock(?array $lock): void
    {
        global $wpdb;

        if ($lock === null || !empty($lock['fallback']) || !is_object($wpdb)) {
            return;
        }

        $name  = (string) ($lock['name'] ?? '');
        $value = (string) ($lock['value'] ?? '');
        if ($name === '' || $value === '') {
            return;
        }

        $suppressed = $wpdb->suppress_errors(true);
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $name,
                $value
            )
        );
        $wpdb->suppress_errors($suppressed);

        self::forgetOption($name);
    }

    /**
     * Returns the wp_options key for a playlist's refresh lock.
     *
     * @param string $playlistName Playlist slug.
     * @return string e.g. 'maw_yt_lock_my_playlist'.
     */
    public static function lockOptionName(string $playlistName): string
    {
        return self::OPTION_LOCK_PREFIX . sanitize_key($playlistName);
    }

    /**
     * Builds the JSON lock value containing a fresh owner token and expiry.
     *
     * @param int $expiresAt Unix timestamp after which the lock may be stolen.
     * @return string JSON encoded lock value.
     */
    private static function buildLockValue(int $expiresAt): string
    {
        $token = function_exists('wp_generate_password')
            ? wp_generate_password(20, false, false)
            : bin2hex(random_bytes(10));

        return (string) wp_json_encode([
            'owner'   => $token . '-' . getmypid(),
            'expires' => $expiresAt,
        ]);
    }

    /**
     * Returns whether a stored lock value has expired.
     *
     * A value that cannot be parsed, or that carries no usable expiry, is
     * treated as expired so a corrupted row can never wedge refreshes.
     *
     * @param string $storedValue Raw option value read from the database.
     * @param int    $now         Unix timestamp to compare against.
     * @return bool True when the lock is stale and may be stolen.
     */
    private static function isLockExpired(string $storedValue, int $now): bool
    {
        $decoded = json_decode($storedValue, true);

        if (!is_array($decoded) || !isset($decoded['expires'])) {
            return true;
        }

        return (int) $decoded['expires'] <= $now;
    }

    /**
     * Inserts the lock row, returning false when the row already exists.
     *
     * Deliberately a bare INSERT: the duplicate-key failure on wp_options'
     * unique `option_name` index is what makes this an atomic test-and-set.
     * Database errors are suppressed so an expected conflict does not surface
     * as a visible SQL error.
     *
     * @param string $name  Option name.
     * @param string $value Option value.
     * @return bool True when this caller created the row.
     */
    private static function insertLockRow(string $name, string $value): bool
    {
        global $wpdb;

        $suppressed = $wpdb->suppress_errors(true);
        $result     = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $name,
                $value
            )
        );
        $wpdb->suppress_errors($suppressed);

        self::forgetOption($name);

        return $result === 1 || $result === true;
    }

    /**
     * Replaces an option's value only if it still holds the expected value.
     *
     * @param string $name     Option name.
     * @param string $expected The value the row must currently hold.
     * @param string $value    The new value to store.
     * @return bool True when exactly this caller performed the swap.
     */
    private static function compareAndSwapOption(string $name, string $expected, string $value): bool
    {
        global $wpdb;

        $suppressed = $wpdb->suppress_errors(true);
        $result     = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $value,
                $name,
                $expected
            )
        );
        $wpdb->suppress_errors($suppressed);

        self::forgetOption($name);

        return $result === 1 || $result === true;
    }

    /**
     * Reads an option's raw stored value directly from the database.
     *
     * @param string $name Option name.
     * @return string|null Raw value, or null when the row does not exist.
     */
    private static function readRawOption(string $name): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name)
        );

        return $value === null ? null : (string) $value;
    }

    /**
     * Drops an option from the object cache after a direct SQL write.
     *
     * Without this, a later get_option() in the same request (or on another
     * worker sharing a persistent cache) could return the pre-write value.
     *
     * @param string $name Option name.
     * @return void
     */
    private static function forgetOption(string $name): void
    {
        if (!function_exists('wp_cache_delete')) {
            return;
        }

        wp_cache_delete($name, 'options');

        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$name])) {
            unset($notoptions[$name]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }

    // ---------------------------------------------------------------------
    // Guard diagnostics
    // ---------------------------------------------------------------------

    /**
     * Records a single sanitized guard event for the admin Caching page.
     *
     * Only a reason drawn from {@see self::REASONS}, the playlist slug, a page
     * count, and a timestamp are stored. The API key, request URL, response
     * bodies, and response headers are never passed in and never persisted.
     * One event is written per aborted refresh; nothing is written to the PHP
     * error log unless WP_DEBUG is enabled, so a repeating fault cannot flood it.
     *
     * @param string $reason       One of {@see self::REASONS}.
     * @param string $playlistName Playlist slug the refresh was for.
     * @param int    $pages        Pages requested before the refresh aborted.
     * @return void
     */
    public static function recordGuardEvent(string $reason, string $playlistName, int $pages = 0): void
    {
        $reason = in_array($reason, self::REASONS, true) ? $reason : 'malformed_response';

        update_option(self::OPTION_GUARD_STATUS, [
            'reason'    => $reason,
            'playlist'  => sanitize_key($playlistName),
            'pages'     => max(0, $pages),
            'timestamp' => time(),
        ], false);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'Media API Widget: YouTube refresh aborted (%s) for playlist "%s" after %d page(s).',
                $reason,
                sanitize_key($playlistName),
                max(0, $pages)
            ));
        }
    }

    /**
     * Returns the most recent guard event, or null when none was recorded.
     *
     * @return array{reason:string,playlist:string,pages:int,timestamp:int}|null
     */
    public static function getGuardStatus(): ?array
    {
        $stored = get_option(self::OPTION_GUARD_STATUS, null);

        if (!is_array($stored) || empty($stored['reason'])) {
            return null;
        }

        $reason = (string) $stored['reason'];

        return [
            'reason'    => in_array($reason, self::REASONS, true) ? $reason : 'malformed_response',
            'playlist'  => sanitize_key((string) ($stored['playlist'] ?? '')),
            'pages'     => max(0, (int) ($stored['pages'] ?? 0)),
            'timestamp' => max(0, (int) ($stored['timestamp'] ?? 0)),
        ];
    }

    /**
     * Returns a human-readable label for a guard reason code.
     *
     * @param string $reason One of {@see self::REASONS}.
     * @return string Display label.
     */
    public static function describeReason(string $reason): string
    {
        $labels = [
            'repeated_page_token'        => 'A page token repeated (pagination would have looped)',
            'empty_page_with_next_token' => 'A page returned no items but supplied another page token',
            'malformed_response'         => 'The API response was malformed or not valid JSON',
            'maximum_pages_reached'      => 'The maximum pages per refresh limit was reached',
            'daily_limit_reached'        => 'The daily YouTube call limit was reached',
            'concurrent_refresh'         => 'Another refresh for this playlist was already running',
            'http_error'                 => 'An HTTP or connection error occurred',
        ];

        return $labels[$reason] ?? $reason;
    }
}
