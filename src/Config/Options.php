<?php
namespace MediaApiWidget\Config;

if (!defined('ABSPATH')) { exit; }

/**
 * Central registry for all plugin WordPress options.
 *
 * Provides typed, validated accessors and mutators for the four option
 * groups stored in the wp_options table:
 *
 * - Media items      — the configured YouTube playlists and podcast feeds.
 * - Shortcode fields — global key/value pairs referenceable in shortcodes.
 * - Cookie name      — the client-side cache-invalidation cookie name.
 * - Cache TTLs       — time-to-live values for each caching layer.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class Options
{
    /**
     * Option key for the array of configured media items (YouTube playlists
     * and podcast feeds).
     *
     * @var string
     */
    public const OPTION_MEDIA_ITEMS = 'maw_media_items';

    /**
     * Option key for the array of shortcode key/value fields.
     *
     * @var string
     */
    public const OPTION_SHORTCODES = 'maw_shortcodes';

    /**
     * Option key for the client-side cookie name used for cache invalidation.
     *
     * @var string
     */
    public const OPTION_COOKIE_NAME = 'maw_cookie_name';

    /**
     * Option key for the array of cache TTL settings.
     *
     * @var string
     */
    public const OPTION_CACHE_EXPIRATIONS = 'maw_cache_expirations';

    /**
     * Returns the eight default podcast-player shortcode fields.
     *
     * These fields drive the default styling of the podcast player across
     * the site. They are auto-seeded on activation and their field names
     * are locked (cannot be renamed or removed by the user).
     *
     * @return array<int, array<string,string>> Ordered list of ['field' => string, 'value' => string] pairs.
     */
    public static function getDefaultShortcodes(): array
    {
        return [
            ['field' => 'podcast_player_background_color', 'value' => '#151515'],
            ['field' => 'podcast_player_text_color',       'value' => '#ffffff'],
            ['field' => 'podcast_player_play_icon_color',  'value' => '#ffffff'],
            ['field' => 'podcast_player_color',            'value' => '#c7c7c7'],
            ['field' => 'podcast_player_progress_bar_color','value' => '#616161'],
            ['field' => 'podcast_player_selected_color',   'value' => '#7a7a7a'],
            ['field' => 'podcast_player_font',             'value' => 'Roboto'],
            ['field' => 'podcast_player_scrollbar_color',  'value' => '#c7c7c7'],
            ['field' => 'lightbox_playlist_logo',          'value' => ''],
            ['field' => 'lightbox_playlist_border_color',  'value' => '#ffffff'],
            ['field' => 'lightbox_playlist_font',          'value' => 'Roboto']
        ];
    }

    /**
     * Retrieves the stored media items array.
     *
     * Returns an empty array when the option has not been saved yet or when
     * the stored value is not an array (guards against data corruption).
     *
     * @return array<int, array<string,mixed>> List of media item config arrays.
     */
    public static function getMediaItems(): array
    {
        $items = get_option(self::OPTION_MEDIA_ITEMS, []);
        return is_array($items) ? $items : [];
    }

    /**
     * Persists the media items array to the database.
     *
     * @param array<int, array<string,mixed>> $items Sanitized media item configs.
     * @return void
     */
    public static function setMediaItems(array $items): void
    {
        update_option(self::OPTION_MEDIA_ITEMS, $items);
    }

    /**
     * Retrieves the stored shortcode fields merged with the eight defaults.
     *
     * If the option does not exist yet, returns the default set. Otherwise,
     * passes the stored array through {@see self::mergeShortcodesWithDefaults()}
     * so that the eight locked defaults always appear and in the correct order,
     * with user-supplied values preserved.
     *
     * @return array<int, array<string,mixed>> Ordered list of shortcode field arrays.
     */
    public static function getShortcodes(): array
    {
        $items = get_option(self::OPTION_SHORTCODES, []);
        if (!is_array($items)) {
            return self::getDefaultShortcodes();
        }

        return self::mergeShortcodesWithDefaults($items);
    }

    /**
     * Persists the shortcode fields, ensuring the eight defaults are included.
     *
     * Passes the supplied array through {@see self::mergeShortcodesWithDefaults()}
     * before saving so that locked default fields can never be inadvertently
     * removed.
     *
     * @param array<int, array<string,mixed>> $items Shortcode field arrays to store.
     * @return void
     */
    public static function setShortcodes(array $items): void
    {
        update_option(self::OPTION_SHORTCODES, self::mergeShortcodesWithDefaults($items));
    }

    /**
     * Seeds the eight default shortcode fields if they are absent or incomplete.
     *
     * Compares the stored option against the result of merging with defaults.
     * If they differ (e.g. on first activation or after a plugin update adds a
     * new default field), the merged version is written back to the database.
     * This method is safe to call on every request; it only writes when needed.
     *
     * @return void
     */
    public static function maybeSeedDefaultShortcodes(): void
    {
        $stored = get_option(self::OPTION_SHORTCODES, null);
        $normalized = is_array($stored)
            ? self::mergeShortcodesWithDefaults($stored)
            : self::getDefaultShortcodes();

        if ($stored !== $normalized) {
            update_option(self::OPTION_SHORTCODES, $normalized);
        }
    }

    /**
     * Returns whether a field name belongs to the eight locked default fields.
     *
     * Used by the Settings page to render locked fields as read-only and to
     * suppress the delete button for those rows.
     *
     * @param string $field The field name to check (will be sanitized internally).
     * @return bool True if the field name is one of the eight default fields.
     */
    public static function isDefaultShortcodeField(string $field): bool
    {
        $field = sanitize_key($field);
        if ($field === '') {
            return false;
        }

        foreach (self::getDefaultShortcodes() as $shortcode) {
            if ($field === $shortcode['field']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the fixed name of the client-side cache-invalidation cookie.
     *
     * The cookie presence signals that the browser's localStorage already
     * holds fresh playlist data, so the server skips injecting a new data
     * script on that request.
     *
     * @return string Cookie name ('media_api_widget').
     */
    public static function getCookieName(): string
    {
        return 'media_api_widget';
    }

    /**
     * Minimum accepted value for the YouTube maximum-pages-per-refresh setting.
     *
     * @var int
     */
    public const MIN_YOUTUBE_MAX_PAGES = 1;

    /**
     * Maximum accepted value for the YouTube maximum-pages-per-refresh setting.
     *
     * @var int
     */
    public const MAX_YOUTUBE_MAX_PAGES = 100;

    /**
     * Minimum accepted value for the daily YouTube call limit.
     *
     * @var int
     */
    public const MIN_YOUTUBE_DAILY_CALL_LIMIT = 1;

    /**
     * Maximum accepted value for the daily YouTube call limit.
     *
     * @var int
     */
    public const MAX_YOUTUBE_DAILY_CALL_LIMIT = 10000;

    /**
     * Returns the built-in default cache and guard values.
     *
     * TTLs (all in seconds):
     * - media_cache_ttl                  — how long transient and cookie are valid.
     * - youtube_request_in_progress_ttl  — mutex window for parallel API calls.
     * - youtube_error_ttl                — back-off window after a failed call.
     * - youtube_backup_window_seconds    — window within which the backup JSON
     *                                      file is served instead of re-fetching.
     *
     * Guard limits (counts, not seconds):
     * - youtube_max_pages_per_refresh    — hard ceiling on playlistItems pages
     *                                      requested during a single refresh. At
     *                                      50 items per page the default of 20
     *                                      covers roughly 1,000 items.
     * - youtube_daily_call_limit         — circuit breaker on total outbound
     *                                      YouTube requests per quota day. The
     *                                      default of 200 leaves ample headroom
     *                                      for normal refreshes while capping a
     *                                      runaway well below YouTube's quota.
     *
     * @return array<string,int> Map of setting key to integer value.
     */
    public static function getDefaultCacheExpirations(): array
    {
        return [
            'media_cache_ttl'                 => 7200,
            'youtube_request_in_progress_ttl' => 600,
            'youtube_error_ttl'               => 600,
            'youtube_backup_window_seconds'   => 7200,
            'youtube_max_pages_per_refresh'   => 20,
            'youtube_daily_call_limit'        => 200,
        ];
    }

    /**
     * Retrieves stored cache and guard settings merged with the built-in defaults.
     *
     * Absent or non-positive values fall back to the corresponding default, so
     * installations saved before the guard settings existed receive the defaults
     * at read time without an administrator having to resave anything. Returns
     * the defaults if the stored option is not an array.
     *
     * @return array<string,int> Validated map of setting key to integer value.
     */
    public static function getCacheExpirations(): array
    {
        $defaults = self::getDefaultCacheExpirations();
        $stored   = get_option(self::OPTION_CACHE_EXPIRATIONS, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        return self::sanitizeCacheExpirations($stored, $defaults);
    }

    /**
     * Validates and persists cache and guard settings.
     *
     * Each value is run through absint() and clamped to its allowed range to
     * prevent zero, negative, or runaway values from being stored. Missing keys
     * fall back to the built-in defaults.
     *
     * @param array<string,mixed> $expirations Raw values from the admin form.
     * @return void
     */
    public static function setCacheExpirations(array $expirations): void
    {
        update_option(
            self::OPTION_CACHE_EXPIRATIONS,
            self::sanitizeCacheExpirations($expirations, self::getDefaultCacheExpirations())
        );
    }

    /**
     * Normalizes a raw cache/guard settings array against the defaults.
     *
     * Shared by {@see self::getCacheExpirations()} and
     * {@see self::setCacheExpirations()} so a value read back is always
     * validated identically to a value being written.
     *
     * @param array<string,mixed> $source   Raw values (stored option or form input).
     * @param array<string,int>   $defaults Built-in defaults to fall back to.
     * @return array<string,int> Validated settings.
     */
    private static function sanitizeCacheExpirations(array $source, array $defaults): array
    {
        return [
            'media_cache_ttl'                 => max(1, isset($source['media_cache_ttl']) ? absint($source['media_cache_ttl']) : $defaults['media_cache_ttl']),
            'youtube_request_in_progress_ttl' => max(1, isset($source['youtube_request_in_progress_ttl']) ? absint($source['youtube_request_in_progress_ttl']) : $defaults['youtube_request_in_progress_ttl']),
            'youtube_error_ttl'               => max(1, isset($source['youtube_error_ttl']) ? absint($source['youtube_error_ttl']) : $defaults['youtube_error_ttl']),
            'youtube_backup_window_seconds'   => max(1, isset($source['youtube_backup_window_seconds']) ? absint($source['youtube_backup_window_seconds']) : $defaults['youtube_backup_window_seconds']),
            'youtube_max_pages_per_refresh'   => self::clampSetting(
                $source,
                'youtube_max_pages_per_refresh',
                $defaults['youtube_max_pages_per_refresh'],
                self::MIN_YOUTUBE_MAX_PAGES,
                self::MAX_YOUTUBE_MAX_PAGES
            ),
            'youtube_daily_call_limit'        => self::clampSetting(
                $source,
                'youtube_daily_call_limit',
                $defaults['youtube_daily_call_limit'],
                self::MIN_YOUTUBE_DAILY_CALL_LIMIT,
                self::MAX_YOUTUBE_DAILY_CALL_LIMIT
            ),
        ];
    }

    /**
     * Reads one integer setting from a raw array and clamps it to a range.
     *
     * A missing key falls back to $default; a present but empty or non-numeric
     * value becomes 0 via absint() and is then raised to $min.
     *
     * @param array<string,mixed> $source  Raw values.
     * @param string              $key     Key to read.
     * @param int                 $default Value to use when the key is absent.
     * @param int                 $min     Lowest allowed value.
     * @param int                 $max     Highest allowed value.
     * @return int Clamped integer value.
     */
    private static function clampSetting(array $source, string $key, int $default, int $min, int $max): int
    {
        $value = isset($source[$key]) ? absint($source[$key]) : $default;

        return max($min, min($max, $value));
    }

    /**
     * Merges a stored shortcode array with the eight locked defaults.
     *
     * The merge strategy:
     * 1. Defaults always appear first and in their canonical order.
     * 2. If the stored array contains a matching field, that stored value
     *    (including any user edits) replaces the default value.
     * 3. Custom (non-default) fields from the stored array are appended after
     *    the defaults, deduplicated by field name.
     * 4. Items without a non-empty field key are silently discarded.
     *
     * @param array<int, array<string,mixed>> $items Stored shortcode field arrays.
     * @return array<int, array<string,string>> Merged and normalized shortcode array.
     */
    private static function mergeShortcodesWithDefaults(array $items): array
    {
        $defaults = [];
        foreach (self::getDefaultShortcodes() as $shortcode) {
            $field = sanitize_key((string) ($shortcode['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $defaults[$field] = ['field' => $field, 'value' => (string) ($shortcode['value'] ?? '')];
        }

        $matchedDefaults  = [];
        $customShortcodes = [];
        $seenCustomFields = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $field = sanitize_key((string) ($item['field'] ?? ''));
            if ($field === '') {
                continue;
            }

            $normalizedItem = ['field' => $field, 'value' => (string) ($item['value'] ?? '')];

            if (isset($defaults[$field])) {
                if (!isset($matchedDefaults[$field])) {
                    $matchedDefaults[$field] = $normalizedItem;
                }
                continue;
            }

            if (isset($seenCustomFields[$field])) {
                continue;
            }

            $customShortcodes[]       = $normalizedItem;
            $seenCustomFields[$field] = true;
        }

        $merged = [];
        foreach ($defaults as $field => $defaultItem) {
            $merged[] = $matchedDefaults[$field] ?? $defaultItem;
        }
        foreach ($customShortcodes as $item) {
            $merged[] = $item;
        }

        return $merged;
    }
}
