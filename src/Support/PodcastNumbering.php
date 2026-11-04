<?php

namespace MediaApiWidget\Support;

if (!defined('ABSPATH')) { exit; }

/**
 * Optional season/episode numbering and ordering for podcast feeds.
 *
 * Podcasts number nothing by default: their stored episodes carry no season or
 * episode, exactly as before. When a podcast's media item enables "Use
 * season/episode regex", every episode title is matched against the pattern —
 * written without delimiters and matched case-insensitively, as in the YouTube
 * regex mode — and the results are stored on each episode as `season` and
 * `episode` (-1 when unknown, the plugin's existing sentinel, which post
 * storage leaves blank).
 *
 * Capture groups: named groups `season` and `episode` win; otherwise two groups
 * are season then episode (the YouTube convention) and a single group is the
 * episode, so `Episode (\d+)` works as written. Unlike the YouTube mode there
 * is no fallback that guesses an episode from any number in a title that does
 * not match: free-form podcast titles ("Top 10 …", "… 2026") would be numbered
 * wrongly, so a title that does not match is simply left unnumbered.
 *
 * The podcast sort mode (`podcast_sort_mode`) applies only while the regex is
 * enabled:
 * - `season_episode` (default) orders by season then episode, newest first,
 *   keeping feed order between episodes that share both numbers (or have
 *   none). This mirrors YouTube's "Number in title" order, so stored positions
 *   — and a Loop Grid ordered by them — list episodes in numbered order.
 * - `feed` keeps the feed's own order.
 *
 * A dedicated key is used rather than YouTube's `sort_mode`, whose values
 * ('normal', 'number_in_title') mean something else and are already saved on
 * existing podcast rows.
 *
 * Sorting changes the plugin's stored order — and therefore card, grid,
 * carousel and post-storage positions. Players that select an episode by its
 * position in the original feed (the SoundCloud start track, the custom
 * player's `track`) keep using the feed's own order.
 *
 * Applied once, at refresh time, before the data is stored. Never adds or drops
 * an episode.
 */
final class PodcastNumbering
{
    /** Sort mode keeping the feed's own order. */
    public const SORT_FEED = 'feed';

    /** Sort mode ordering by season, then episode, newest first. */
    public const SORT_SEASON_EPISODE = 'season_episode';

    /**
     * Returns whether a media item config enables podcast numbering.
     *
     * @param array<string,mixed> $config Media item config.
     * @return bool True when the regex checkbox is on and a pattern is set.
     */
    public static function isEnabled(array $config): bool
    {
        return !empty($config['season_episode_regex_enabled'])
            && trim((string) ($config['season_episode_regex'] ?? '')) !== '';
    }

    /**
     * Numbers (and optionally sorts) the episodes of a normalized feed tree.
     *
     * @param array<string,mixed> $tree   Normalized podcast feed tree.
     * @param array<string,mixed> $config Media item config.
     * @return array<string,mixed> The tree, numbered when enabled; unchanged otherwise.
     */
    public static function apply(array $tree, array $config): array
    {
        if (!self::isEnabled($config) || !isset($tree['channel']) || !is_array($tree['channel'])) {
            return $tree;
        }

        $items = $tree['channel']['item'] ?? null;
        if (!is_array($items) || $items === []) {
            return $tree;
        }

        $regex  = trim((string) $config['season_episode_regex']);
        $single = !array_is_list($items);
        $list   = $single ? [$items] : $items;

        foreach ($list as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $parsed = self::parse(XmlText::flatten($item['title'] ?? ''), $regex);
            $list[$index]['season']  = $parsed['season'];
            $list[$index]['episode'] = $parsed['episode'];
        }

        if (!$single && self::sortMode($config) === self::SORT_SEASON_EPISODE) {
            $list = self::sortBySeasonEpisode($list);
        }

        $tree['channel']['item'] = $single ? $list[0] : $list;

        return $tree;
    }

    /**
     * Parses one title. See the class docblock for the group rules.
     *
     * @param string $title Episode title.
     * @param string $regex Pattern without delimiters.
     * @return array{season:int,episode:int} -1 for anything not determined.
     */
    public static function parse(string $title, string $regex): array
    {
        $none = ['season' => -1, 'episode' => -1];

        if ($regex === '' || $title === '') {
            return $none;
        }

        // Same delimiting as MediaContent::seasonEpisodeGenerator(); an invalid
        // pattern makes preg_match() return false and numbers nothing. Its
        // compile warning is swallowed by a temporary handler rather than "@",
        // which still hands the warning to any custom error handler (debug
        // loggers, Query Monitor) once per episode on every refresh.
        $delimited = '~' . str_replace('~', '\~', $regex) . '~i';
        set_error_handler(static fn (): bool => true);
        try {
            $matched = preg_match($delimited, $title, $matches);
        } finally {
            restore_error_handler();
        }

        if ($matched !== 1) {
            return $none;
        }

        $named      = array_key_exists('season', $matches) || array_key_exists('episode', $matches);
        $positional = array_values(array_filter($matches, 'is_int', ARRAY_FILTER_USE_KEY));
        array_shift($positional); // the whole match

        if ($named) {
            $season  = $matches['season'] ?? '';
            $episode = $matches['episode'] ?? '';
        } elseif (count($positional) >= 2) {
            [$season, $episode] = $positional;
        } else {
            $season  = '';
            $episode = $positional[0] ?? '';
        }

        $toInt = static fn ($v): int => is_string($v) && ctype_digit($v) ? (int) $v : -1;

        return ['season' => $toInt($season), 'episode' => $toInt($episode)];
    }

    /**
     * Returns a config's podcast sort mode, defaulting to season/episode order.
     *
     * @param array<string,mixed> $config Media item config.
     * @return string self::SORT_SEASON_EPISODE or self::SORT_FEED.
     */
    public static function sortMode(array $config): string
    {
        return ($config['podcast_sort_mode'] ?? '') === self::SORT_FEED ? self::SORT_FEED : self::SORT_SEASON_EPISODE;
    }

    /**
     * Stable sort: season descending, episode descending, then feed order.
     *
     * @param array<int,mixed> $list Episodes.
     * @return array<int,mixed> Sorted episodes.
     */
    private static function sortBySeasonEpisode(array $list): array
    {
        $keyed = [];
        foreach ($list as $index => $item) {
            $keyed[] = [
                'season'  => is_array($item) ? (int) ($item['season'] ?? -1) : -1,
                'episode' => is_array($item) ? (int) ($item['episode'] ?? -1) : -1,
                'index'   => $index,
                'item'    => $item,
            ];
        }

        usort($keyed, static function (array $a, array $b): int {
            return [$b['season'], $b['episode'], $a['index']] <=> [$a['season'], $a['episode'], $b['index']];
        });

        return array_column($keyed, 'item');
    }
}
