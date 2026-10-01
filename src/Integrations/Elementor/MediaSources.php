<?php
namespace MediaApiWidget\Integrations\Elementor;

use MediaApiWidget\Config\Options;

if (!defined('ABSPATH')) { exit; }

/**
 * Read-only view of the configured media sources and stored shortcode fields.
 *
 * Feeds the Elementor widget selectors. Only the identifying, non-secret parts
 * of each media item are exposed — playlist name, media type, and podcast
 * platform. The API key and the playlist ID / feed URL held in `media_data`
 * never leave this class, so nothing sensitive reaches the editor config.
 *
 * Items are read from {@see Options::getMediaItems()} plus the legacy
 * MEDIA_CONTENT_DATA constant, in the same order the shortcode renderer uses
 * to find a config, so "first match wins" agrees with what will render.
 */
final class MediaSources
{
    /**
     * Returns every configured source as a safe summary row, deduplicated.
     *
     * @return array<int,array{playlist_name:string,type:string,podcast_platform:string}> Source rows.
     */
    public static function all(): array
    {
        $rows = [];
        $seen = [];

        foreach (self::rawItems() as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = sanitize_key((string) ($item['playlist_name'] ?? ''));
            $type = sanitize_key((string) ($item['type'] ?? ''));

            if ($name === '' || !in_array($type, ['youtube', 'podcast'], true)) {
                continue;
            }

            if (isset($seen[$type . '|' . $name])) {
                continue;
            }

            $seen[$type . '|' . $name] = true;
            $rows[] = [
                'playlist_name'    => $name,
                'type'             => $type,
                'podcast_platform' => $type === 'podcast'
                    ? sanitize_key((string) ($item['podcast_platform'] ?? 'custom'))
                    : '',
            ];
        }

        return $rows;
    }

    /**
     * Returns `playlist_name => label` select options for one media type.
     *
     * @param string $type 'youtube' or 'podcast'.
     * @return array<string,string> Select options.
     */
    public static function optionsFor(string $type): array
    {
        $options = [];

        foreach (self::all() as $row) {
            if ($row['type'] !== $type) {
                continue;
            }

            $options[$row['playlist_name']] = $type === 'podcast' && $row['podcast_platform'] !== ''
                ? $row['playlist_name'] . ' (' . $row['podcast_platform'] . ')'
                : $row['playlist_name'];
        }

        return $options;
    }

    /**
     * Returns the configured podcast platform for a playlist, or '' when unknown.
     *
     * @param string $playlistName Sanitized playlist name.
     * @return string Platform key.
     */
    public static function podcastPlatform(string $playlistName): string
    {
        foreach (self::all() as $row) {
            if ($row['type'] === 'podcast' && $row['playlist_name'] === $playlistName) {
                return $row['podcast_platform'];
            }
        }

        return '';
    }

    /**
     * Returns whether the matching config carries a non-empty `media_data`.
     *
     * Used only to predict whether rendering a podcast with a cold cache would
     * trigger the renderer's remote warm-up; the value itself is not returned.
     *
     * @param string $playlistName Sanitized playlist name.
     * @param string $type         'youtube' or 'podcast'.
     * @return bool True when a fetchable source is configured.
     */
    public static function hasMediaData(string $playlistName, string $type): bool
    {
        foreach (self::rawItems() as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (sanitize_key((string) ($item['playlist_name'] ?? '')) === $playlistName
                && sanitize_key((string) ($item['type'] ?? '')) === $type) {
                return trim((string) ($item['media_data'] ?? '')) !== '';
            }
        }

        return false;
    }

    /**
     * Returns the sanitized names of every stored shortcode field.
     *
     * Values are deliberately not returned; only names are needed to build
     * `{{field_name}}` references.
     *
     * @return array<int,string> Field names in admin order.
     */
    public static function fieldNames(): array
    {
        $names = [];

        foreach (Options::getShortcodes() as $shortcode) {
            if (!is_array($shortcode)) {
                continue;
            }

            $field = sanitize_key((string) ($shortcode['field'] ?? ''));
            if ($field !== '' && !in_array($field, $names, true)) {
                $names[] = $field;
            }
        }

        return $names;
    }

    /**
     * Returns admin media items merged with the legacy constant, in renderer order.
     *
     * @return array<int,mixed> Raw config rows.
     */
    private static function rawItems(): array
    {
        $items = Options::getMediaItems();

        if (defined('MEDIA_CONTENT_DATA') && is_array(constant('MEDIA_CONTENT_DATA'))) {
            $items = array_merge($items, constant('MEDIA_CONTENT_DATA'));
        }

        return $items;
    }
}
