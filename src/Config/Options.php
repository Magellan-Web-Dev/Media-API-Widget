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
     * Returns the built-in default cache TTL values (all in seconds).
     *
     * - media_cache_ttl                  — how long transient and cookie are valid.
     * - youtube_request_in_progress_ttl  — mutex window for parallel API calls.
     * - youtube_error_ttl                — back-off window after a failed call.
     * - youtube_backup_window_seconds    — window within which the backup JSON
     *                                      file is served instead of re-fetching.
     *
     * @return array<string,int> Map of setting key to integer TTL in seconds.
     */
    public static function getDefaultCacheExpirations(): array
    {
        return [
            'media_cache_ttl'                 => 7200,
            'youtube_request_in_progress_ttl' => 600,
            'youtube_error_ttl'               => 600,
            'youtube_backup_window_seconds'   => 7200,
        ];
    }

    /**
     * Retrieves stored cache TTL settings merged with the built-in defaults.
     *
     * Absent or non-positive values fall back to the corresponding default.
     * Returns the defaults if the stored option is not an array.
     *
     * @return array<string,int> Validated map of setting key to integer TTL in seconds.
     */
    public static function getCacheExpirations(): array
    {
        $defaults = self::getDefaultCacheExpirations();
        $stored   = get_option(self::OPTION_CACHE_EXPIRATIONS, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        return [
            'media_cache_ttl'                 => max(1, isset($stored['media_cache_ttl']) ? absint($stored['media_cache_ttl']) : $defaults['media_cache_ttl']),
            'youtube_request_in_progress_ttl' => max(1, isset($stored['youtube_request_in_progress_ttl']) ? absint($stored['youtube_request_in_progress_ttl']) : $defaults['youtube_request_in_progress_ttl']),
            'youtube_error_ttl'               => max(1, isset($stored['youtube_error_ttl']) ? absint($stored['youtube_error_ttl']) : $defaults['youtube_error_ttl']),
            'youtube_backup_window_seconds'   => max(1, isset($stored['youtube_backup_window_seconds']) ? absint($stored['youtube_backup_window_seconds']) : $defaults['youtube_backup_window_seconds']),
        ];
    }

    /**
     * Validates and persists cache TTL settings.
     *
     * Each value is run through absint() and clamped to a minimum of 1 to
     * prevent zero or negative TTLs from being stored. Missing keys fall
     * back to the built-in defaults.
     *
     * @param array<string,mixed> $expirations Raw TTL values from the admin form.
     * @return void
     */
    public static function setCacheExpirations(array $expirations): void
    {
        $defaults = self::getDefaultCacheExpirations();

        $sanitized = [
            'media_cache_ttl'                 => max(1, isset($expirations['media_cache_ttl']) ? absint($expirations['media_cache_ttl']) : $defaults['media_cache_ttl']),
            'youtube_request_in_progress_ttl' => max(1, isset($expirations['youtube_request_in_progress_ttl']) ? absint($expirations['youtube_request_in_progress_ttl']) : $defaults['youtube_request_in_progress_ttl']),
            'youtube_error_ttl'               => max(1, isset($expirations['youtube_error_ttl']) ? absint($expirations['youtube_error_ttl']) : $defaults['youtube_error_ttl']),
            'youtube_backup_window_seconds'   => max(1, isset($expirations['youtube_backup_window_seconds']) ? absint($expirations['youtube_backup_window_seconds']) : $defaults['youtube_backup_window_seconds']),
        ];

        update_option(self::OPTION_CACHE_EXPIRATIONS, $sanitized);
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
