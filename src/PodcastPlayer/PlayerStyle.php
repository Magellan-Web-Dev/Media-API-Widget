<?php
namespace MediaApiWidget\PodcastPlayer;

use MediaApiWidget\Config\ShortcodeFields;

if (!defined('ABSPATH')) { exit; }

/**
 * Resolves the custom podcast player's styling.
 *
 * The eight styling attributes default to the stored `podcast_player_*`
 * shortcode fields. This class is the one place that mapping and its
 * reference resolution live, so the `[media-api-podcast-player]` shortcode
 * (and the Elementor widget that renders it) and the "Media API Click Action"
 * dynamic tag open the player with the same styling.
 *
 * Values are turned into `/podcast/player` query values here; their
 * validation (and the fallbacks for missing or empty values) is
 * {@see DataParams::build()}'s job.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class PlayerStyle
{
    /** Styling attribute => stored shortcode field that supplies its default. */
    public const FIELDS = [
        'podcastplayermode'             => 'podcast_player_background_color',
        'podcastplayertextcolor'        => 'podcast_player_text_color',
        'podcastplayerbuttoncolor'      => 'podcast_player_play_icon_color',
        'podcastplayercolor'            => 'podcast_player_color',
        'podcastprogressplayerbarcolor' => 'podcast_player_progress_bar_color',
        'podcastplayerhighlightcolor'   => 'podcast_player_selected_color',
        'podcastplayerfont'             => 'podcast_player_font',
        'podcastplayerscrollcolor'      => 'podcast_player_scrollbar_color',
    ];

    /** Styling attribute => `/podcast/player` query parameter, in URL order. */
    public const QUERY = [
        'podcastplayermode'             => 'mode',
        'podcastplayerbuttoncolor'      => 'buttoncolor',
        'podcastplayercolor'            => 'color1',
        'podcastprogressplayerbarcolor' => 'progressbarcolor',
        'podcastplayerhighlightcolor'   => 'highlightcolor',
        'podcastplayerfont'             => 'font',
        'podcastplayerscrollcolor'      => 'scrollcolor',
        'podcastplayertextcolor'        => 'textcolor',
    ];

    /** The one styling attribute that is not a color. */
    private const FONT = 'podcastplayerfont';

    /**
     * Applies the full resolution `[media-api-podcast-player]` uses.
     *
     * Resolves field references in every attribute, fills empty or unresolved
     * styling attributes from the `podcast_player_*` fields, then resolves
     * again so a stored field may itself hold a reference.
     *
     * Which caller-supplied styling attributes survive is decided before this
     * runs: `shortcode_atts()` drops them for the bare shortcode (its
     * restricted contract), and only the Elementor widget's scoped passthrough
     * filter keeps them. Every styling attribute absent or empty here is filled
     * from its field.
     *
     * @param array<string,mixed>  $atts   Attributes.
     * @param array<string,string> $values Field map from {@see ShortcodeFields::values()}.
     * @return array<string,mixed> Resolved attributes.
     */
    public static function resolve(array $atts, array $values): array
    {
        $atts = ShortcodeFields::resolveAll($atts, $values);
        $atts = self::applyFieldDefaults($atts, $values);

        return ShortcodeFields::resolveAll($atts, $values);
    }

    /**
     * Fills styling attributes from their `podcast_player_*` fields.
     *
     * An attribute that resolves to a field value takes it. One that is empty,
     * or still an unresolvable reference, takes the stored field when that
     * field is non-empty; otherwise it is left as it is.
     *
     * @param array<string,mixed>  $atts   Attributes.
     * @param array<string,string> $values Field map from {@see ShortcodeFields::values()}.
     * @return array<string,mixed> Attributes with field defaults applied.
     */
    public static function applyFieldDefaults(array $atts, array $values): array
    {
        foreach (self::FIELDS as $attribute => $field) {
            $current = isset($atts[$attribute]) ? trim((string) $atts[$attribute]) : '';

            if ($current !== '') {
                $resolved = ShortcodeFields::resolve($current, $values);
                if ($resolved !== $current) {
                    $atts[$attribute] = $resolved;
                    continue;
                }
            }

            if (($current === '' || ShortcodeFields::isReference($current))
                && isset($values[$field]) && $values[$field] !== '') {
                $atts[$attribute] = $values[$field];
            }
        }

        return $atts;
    }

    /**
     * Returns the eight styling query parameters for resolved attributes.
     *
     * Keys follow {@see self::QUERY}'s order. A missing attribute yields an
     * empty value, which the player treats as "use the default".
     *
     * @param array<string,mixed> $atts Resolved attributes.
     * @return array<string,string> Query parameter => value.
     */
    public static function queryArgs(array $atts): array
    {
        $args = [];

        foreach (self::QUERY as $attribute => $param) {
            $args[$param] = self::queryValue($attribute, (string) ($atts[$attribute] ?? ''));
        }

        return $args;
    }

    /**
     * Normalizes one styling value for the player URL.
     *
     * Colors lose a leading '#' only when they have one, so `dark`, `light`,
     * `151515` and `#151515` all survive; the font is kept as written,
     * spaces included.
     *
     * @param string $attribute Styling attribute.
     * @param string $value     Resolved value.
     * @return string Query value.
     */
    public static function queryValue(string $attribute, string $value): string
    {
        $value = trim($value);

        return $attribute === self::FONT ? $value : ltrim($value, '#');
    }

    /**
     * Returns the saved site-wide styling: what a bare `[media-api-podcast-player]`
     * opens with, keyed by attribute (which is also the data attribute the
     * front-end player script reads, e.g. `data-podcastplayermode`).
     *
     * An empty value means the field is blank and the player's own default applies.
     *
     * @param array<string,string>|null $values Field map; read from the options when null.
     * @return array<string,string> Attribute => query-ready value.
     */
    public static function savedDefaults(?array $values = null): array
    {
        $values = $values ?? ShortcodeFields::values();
        $atts   = self::resolve(array_fill_keys(array_keys(self::FIELDS), ''), $values);

        $defaults = [];
        foreach (array_keys(self::FIELDS) as $attribute) {
            $defaults[$attribute] = self::queryValue($attribute, (string) $atts[$attribute]);
        }

        return $defaults;
    }
}
