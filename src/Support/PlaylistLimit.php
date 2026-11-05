<?php
namespace MediaApiWidget\Support;

if (!defined('ABSPATH')) { exit; }

/**
 * Resolves and applies a playlist's saved item limit.
 *
 * Each playlist either loads in full or keeps only its first N processed
 * items (default 6). Every loading path — the wp_head YouTube and podcast
 * refreshes and the shortcode podcast warm-up — resolves the limit and applies
 * it here, so they cannot disagree.
 *
 * The limit controls how many items are *saved*: the complete source is still
 * fetched (YouTube pages and quota guards are unchanged) and still records the
 * complete membership for stored posts. It is applied last, after
 * normalization, sorting and de-duplication, immediately before the payload is
 * stored.
 *
 * Settings compatibility:
 * - `playlist_limit` was introduced in 6.2.0, and the settings form writes it on
 *   every row. Its presence is how a row saved by 6.2.0 or later is told apart
 *   from an older one.
 * - YouTube rows behave as before: `load_full_playlist` (strictly `true`) loads
 *   in full, anything else keeps the first `playlist_limit` items (6 when the
 *   row predates the setting or holds an invalid value).
 * - Podcast rows without `playlist_limit` load in full. Before 6.2.0 podcasts
 *   always did, even though the settings form stored `load_full_playlist =
 *   false` on them, so that stored value alone is not a request to limit.
 * - Embed-only podcasts store a URL, not an episode list; no limit applies.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class PlaylistLimit
{
    /** Media item key holding the saved limit. */
    public const KEY = 'playlist_limit';

    /** Limit used for new playlists, older YouTube rows and invalid values. */
    public const DEFAULT = 6;

    /**
     * Validates a limit: a positive whole number, or the default.
     *
     * Accepts an int, or a string of digits with optional surrounding spaces
     * ("12"); anything else ("0", "-3", "1.5", "abc", "", null) yields 6.
     *
     * @param mixed $value Submitted or stored value.
     * @return int Limit, at least 1.
     */
    public static function sanitize($value): int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : self::DEFAULT;
        }

        if (is_string($value) && preg_match('/^\s*(\d{1,9})\s*$/', $value, $m) === 1 && (int) $m[1] >= 1) {
            return (int) $m[1];
        }

        return self::DEFAULT;
    }

    /**
     * Returns the row's saved limit, kept even while the row loads in full.
     *
     * @param array<string,mixed> $config Media item config.
     * @return int Limit.
     */
    public static function savedLimit(array $config): int
    {
        return self::sanitize($config[self::KEY] ?? self::DEFAULT);
    }

    /**
     * Returns whether a limit can apply to the row at all: false only for
     * embed-only podcasts, which have no episode collection.
     *
     * @param array<string,mixed> $config Media item config.
     * @return bool True when a limit can apply.
     */
    public static function applies(array $config): bool
    {
        return !(self::type($config) === 'podcast' && self::platform($config) === 'embed');
    }

    /**
     * Returns the row's saved "Load full playlist" choice, which the settings
     * form shows. An embed-only podcast keeps the choice it had as an RSS
     * playlist, for when it is switched back.
     *
     * @param array<string,mixed> $config Media item config.
     * @return bool True when full loading is selected (see the class docblock).
     */
    public static function fullSelected(array $config): bool
    {
        if (self::type($config) === 'podcast' && !isset($config[self::KEY])) {
            return true;
        }

        // Strict, as YouTube has always been: a legacy MEDIA_CONTENT_DATA value
        // of 1 or '1' keeps the limited behavior it has always had.
        return ($config['load_full_playlist'] ?? false) === true;
    }

    /**
     * Returns whether the row loads its whole processed playlist.
     *
     * @param array<string,mixed> $config Media item config.
     * @return bool True for full loading, and for embed-only podcasts.
     */
    public static function loadsFull(array $config): bool
    {
        return !self::applies($config) || self::fullSelected($config);
    }

    /**
     * Returns the number of items to keep, or null to keep them all.
     *
     * @param array<string,mixed> $config Media item config.
     * @return int|null Limit, or null for full loading.
     */
    public static function resolve(array $config): ?int
    {
        return self::loadsFull($config) ? null : self::savedLimit($config);
    }

    /**
     * Keeps the first `$limit` items of a list, reindexed from 0.
     *
     * Items are counted, not their keys, so a list with gaps left by
     * de-duplication still keeps `$limit` items when it has that many.
     *
     * @param array<int|string,mixed> $items Processed items.
     * @param int|null                $limit Limit, or null for all.
     * @return array<int,mixed> Retained items.
     */
    public static function applyToList(array $items, ?int $limit): array
    {
        $items = array_values($items);

        return $limit === null ? $items : array_slice($items, 0, max(1, $limit));
    }

    /**
     * Limits a normalized podcast tree's `channel.item`, keeping all channel metadata.
     *
     * A single-episode feed (an associative `item`) and a feed without episodes
     * are returned unchanged, since neither can exceed a limit of 1 or more.
     *
     * @param array<string,mixed> $tree  Normalized podcast tree.
     * @param int|null            $limit Limit, or null for all.
     * @return array<string,mixed> Tree with at most `$limit` episodes.
     */
    public static function applyToPodcast(array $tree, ?int $limit): array
    {
        if ($limit === null || !isset($tree['channel']) || !is_array($tree['channel'])) {
            return $tree;
        }

        $items = $tree['channel']['item'] ?? null;
        if (!is_array($items) || $items === []) {
            return $tree;
        }

        // A single episode is the item itself (string keys such as 'title').
        foreach (array_keys($items) as $key) {
            if (is_string($key)) {
                return $tree;
            }
        }

        $tree['channel']['item'] = self::applyToList($items, $limit);

        return $tree;
    }

    /**
     * @param array<string,mixed> $config Media item config.
     * @return string Sanitized media type.
     */
    private static function type(array $config): string
    {
        return sanitize_key((string) ($config['type'] ?? 'youtube'));
    }

    /**
     * @param array<string,mixed> $config Media item config.
     * @return string Sanitized podcast platform (defaults to 'custom', as everywhere else).
     */
    private static function platform(array $config): string
    {
        $platform = sanitize_key((string) ($config['podcast_platform'] ?? ''));

        return $platform !== '' ? $platform : 'custom';
    }
}
