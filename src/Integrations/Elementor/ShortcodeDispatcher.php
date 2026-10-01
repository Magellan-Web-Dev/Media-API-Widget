<?php
namespace MediaApiWidget\Integrations\Elementor;

if (!defined('ABSPATH')) { exit; }

/**
 * Invokes the registered shortcode callbacks for a render plan.
 *
 * Calls go through `$shortcode_tags[$tag]` — the very callback WordPress runs
 * for the shortcode, including any replacement a site registered — and are
 * wrapped in the same `pre_do_shortcode_tag` / `do_shortcode_tag` filters
 * `do_shortcode_tag()` applies. The `shortcode_atts_{$tag}` filter fires
 * inside the renderer's own `shortcode_atts()` call, as it always does. No
 * markup is produced here.
 *
 * Two narrowly scoped filters are attached for the duration of one widget's
 * calls only, then removed, so shortcodes elsewhere on the page are untouched:
 *
 * - `media_api_widget_grid_search_id` gives a searchable grid (and the search
 *   bar linked to it) the widget's own search id instead of the shared
 *   `{playlist}_{platform}` id.
 * - `shortcode_atts_media-api-podcast-player` keeps the podcast player styling
 *   attributes the widget passed. The player shortcode's attribute defaults do
 *   not list them, so `shortcode_atts()` drops them for shortcodes — that
 *   contract is left exactly as it is; only the widget's own call keeps them,
 *   after which the renderer resolves references and applies the
 *   `podcast_player_*` stored-field defaults as usual.
 */
final class ShortcodeDispatcher
{
    /** Filter the Shortcode renderer applies to a grid's search id. */
    public const GRID_ID_FILTER = 'media_api_widget_grid_search_id';

    /** Player styling attributes the widget may pass to [media-api-podcast-player]. */
    public const PLAYER_PASSTHROUGH = [
        'podcastplayermode',
        'podcastplayertextcolor',
        'podcastplayerbuttoncolor',
        'podcastplayercolor',
        'podcastprogressplayerbarcolor',
        'podcastplayerhighlightcolor',
        'podcastplayerfont',
        'podcastplayerscrollcolor',
        'showepisodedateaftertitle',
    ];

    /**
     * Runs every call in a plan and concatenates the output.
     *
     * @param array<string,mixed> $plan Plan from {@see SettingsAdapter::plan()}.
     * @return string Combined shortcode output.
     */
    public static function render(array $plan): string
    {
        $restore = self::scope($plan);
        $html    = '';

        try {
            foreach ((array) $plan['calls'] as $call) {
                $html .= self::dispatch((string) $call['tag'], (array) $call['atts']);
            }
        } finally {
            $restore();
        }

        return $html;
    }

    /**
     * Invokes one registered shortcode callback the way do_shortcode_tag() does.
     *
     * Returns '' when the tag is not registered (a site that removed the
     * shortcode gets no output from the widget either).
     *
     * @param string               $tag  Shortcode tag.
     * @param array<string,string> $atts Attribute array.
     * @return string Shortcode output.
     */
    public static function dispatch(string $tag, array $atts): string
    {
        global $shortcode_tags;

        if (!isset($shortcode_tags[$tag]) || !is_callable($shortcode_tags[$tag])) {
            return '';
        }

        $text = ShortcodeText::build($tag, $atts);
        $m    = [$text, '', $tag, substr($text, strlen($tag) + 1, -1), '', '', ''];

        $pre = apply_filters('pre_do_shortcode_tag', false, $tag, $atts, $m);
        if ($pre !== false) {
            return (string) $pre;
        }

        $output = call_user_func($shortcode_tags[$tag], $atts, '', $tag);

        return (string) apply_filters('do_shortcode_tag', $output, $tag, $atts, $m);
    }

    /**
     * Attaches the plan's scoped filters and returns a callback removing them.
     *
     * @param array<string,mixed> $plan Render plan.
     * @return callable(): void Restore callback.
     */
    private static function scope(array $plan): callable
    {
        $removals = [];

        $gridId = $plan['grid_id'] ?? null;
        if (is_string($gridId) && $gridId !== '') {
            $playlist = (string) $plan['playlist'];
            $platform = (string) $plan['platform'];

            $gridFilter = static function ($id, $playlistName = '', $mediaType = '') use ($gridId, $playlist, $platform) {
                return $playlistName === $playlist && $mediaType === $platform ? $gridId : $id;
            };

            add_filter(self::GRID_ID_FILTER, $gridFilter, 1000, 3);
            $removals[] = [self::GRID_ID_FILTER, $gridFilter, 1000];
        }

        if (!empty($plan['podcast_overrides'])) {
            $attsFilter = static function ($out, $pairs = [], $atts = []) {
                if (!is_array($out) || !is_array($atts)) {
                    return $out;
                }

                foreach (self::PLAYER_PASSTHROUGH as $key) {
                    if (array_key_exists($key, $atts) && is_string($atts[$key])) {
                        $out[$key] = $atts[$key];
                    }
                }

                return $out;
            };

            add_filter('shortcode_atts_' . SettingsAdapter::TAG_PLAYER, $attsFilter, 10, 3);
            $removals[] = ['shortcode_atts_' . SettingsAdapter::TAG_PLAYER, $attsFilter, 10];
        }

        return static function () use ($removals): void {
            foreach ($removals as [$tag, $callback, $priority]) {
                remove_filter($tag, $callback, $priority);
            }
        };
    }
}
