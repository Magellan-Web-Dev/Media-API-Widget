<?php

namespace MediaApiWidget\Support;

if (!defined('ABSPATH')) { exit; }

/**
 * Atomic named mutex built on a wp_options row.
 *
 * The lock is a wp_options row created by a bare INSERT, which fails on the
 * unique `option_name` index when another worker already holds it — a true
 * compare-and-set that needs no extra table and no external cache. The stored
 * value carries an owner token and an expiry, so a worker killed mid-operation
 * (a PHP timeout, for instance) cannot block the lock permanently: the next
 * caller sees the expiry has passed and steals the row with a compare-and-swap
 * against the exact stale value.
 *
 * Two lock namespaces use this primitive, and they are deliberately separate:
 *
 * - `maw_yt_lock_{playlist}` — {@see YoutubeGuard}'s per-playlist *refresh*
 *   lock, which deduplicates concurrent outbound YouTube fetches.
 * - `maw_media_data_lock_{type}_{playlist}` — the per-playlist *storage* lock
 *   held by {@see MediaStore} around a persist sequence, and by the stored-data
 *   updater across its read-modify-write. Keeping it out of the refresh
 *   namespace means a routine enrichment write does not read as a
 *   `concurrent_refresh` guard event on the admin Caching page.
 *
 * Every database access degrades open: when there is no usable `$wpdb`, or when
 * an INSERT fails for an environmental reason rather than a genuine conflict,
 * the caller receives a fallback handle that permits the operation and releases
 * as a no-op. A lock is a throughput guard here, not a correctness barrier, so
 * failing closed would take the site down for no benefit.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class OptionLock
{
    /**
     * Option-name prefix for the shared per-playlist/media storage lock.
     *
     * @var string
     */
    public const OPTION_DATA_LOCK_PREFIX = 'maw_media_data_lock_';

    /**
     * How long a storage lock stays valid before another caller may steal it.
     *
     * Short by design: no outbound request is ever made while it is held — only
     * validation, JSON encoding, a transient write, and a backup file write.
     *
     * @var int
     */
    public const DATA_LOCK_TTL_SECONDS = 30;

    /**
     * Attempts to acquire an exclusive lock on one option name.
     *
     * @param string   $optionName wp_options key the lock occupies.
     * @param int      $ttlSeconds How long the lock stays valid before it may be stolen.
     * @param int|null $now        Unix timestamp to evaluate expiry against.
     * @return array<string,mixed>|null Lock handle to pass to {@see self::release()},
     *                                  or null when another worker holds a live lock.
     */
    public static function acquire(string $optionName, int $ttlSeconds, ?int $now = null): ?array
    {
        global $wpdb;

        $now  = $now ?? time();
        $ttl  = max(1, $ttlSeconds);
        $name = $optionName;

        // No usable database handle: fall back to a handle that releases as a
        // no-op, permitting the operation rather than blocking it.
        if (!is_object($wpdb)) {
            return ['name' => $name, 'value' => '', 'fallback' => true];
        }

        $value = self::buildLockValue($now + $ttl);

        if (self::insertLockRow($name, $value)) {
            return ['name' => $name, 'value' => $value, 'fallback' => false];
        }

        $existing = self::readRawOption($name);

        // The INSERT failed but no row exists, so the failure was environmental
        // rather than a genuine conflict. Degrade to permitting the operation.
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
     * Releases a lock previously returned by {@see self::acquire()}.
     *
     * The DELETE matches on the stored value as well as the name, so a worker
     * whose lock was already stolen after expiring cannot delete the new
     * owner's lock. Safe to call with null or a fallback handle.
     *
     * @param array<string,mixed>|null $lock Lock handle, or null.
     * @return void
     */
    public static function release(?array $lock): void
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
     * Returns the wp_options key for a playlist's shared storage lock.
     *
     * Both media types get their own key, so enriching a podcast cannot block a
     * YouTube refresh for the same playlist slug.
     *
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param string $playlistName Playlist slug.
     * @return string e.g. 'maw_media_data_lock_youtube_my_show'.
     */
    public static function dataLockName(string $mediaType, string $playlistName): string
    {
        return self::OPTION_DATA_LOCK_PREFIX . sanitize_key($mediaType) . '_' . sanitize_key($playlistName);
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
     * treated as expired so a corrupted row can never wedge the lock.
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
}
