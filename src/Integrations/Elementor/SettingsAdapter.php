<?php
namespace MediaApiWidget\Integrations\Elementor;

if (!defined('ABSPATH')) { exit; }

/**
 * Translates widget settings into existing shortcode calls.
 *
 * This is the only place widget settings are interpreted, and it does no
 * rendering of its own: the result is a *plan* — the shortcode tags to invoke
 * and the exact attribute arrays to give them — which {@see ShortcodeDispatcher}
 * hands to the registered shortcode callbacks. Equivalent settings therefore
 * produce exactly what the equivalent shortcode would.
 *
 * Attribute rules:
 * - A setting whose control is hidden by its visibility rules is ignored, so a
 *   value saved under another output mode can never override the active one.
 * - `inherit` omits the attribute, so the shortcode's built-in default and any
 *   same-named stored field default apply exactly as they do for a shortcode.
 * - `empty` passes an explicit empty string.
 * - `field:{name}` passes `{{name}}`, resolved by the renderer itself.
 * - A free-form `value` with nothing entered is treated as `inherit`.
 *
 * The output mode sets the renderer's mode flags (`mediatitle`,
 * `mediadescription`, `multiplegrid`, `multiplegridusersearch`) explicitly:
 * choosing "Media card" in the editor is an explicit choice, and must not be
 * turned into a grid by a stored field that happens to share a flag's name.
 */
final class SettingsAdapter
{
    public const TAG_FIELD  = 'media-api-widget';
    public const TAG_RENDER = 'media-api-widget-render';
    public const TAG_PLAYER = 'media-api-podcast-player';
    public const TAG_SEARCH = 'media-api-widget-grid-search';

    /** Plan errors: nothing can be rendered. */
    public const ERROR_NO_FIELD         = 'no_field';
    public const ERROR_NO_SOURCE        = 'no_source';
    public const ERROR_PLAYER_PLATFORM  = 'player_needs_podcast';
    public const ERROR_NO_CONNECTION    = 'no_connection';

    /** Plan notices: rendered, but worth explaining in the editor. */
    public const NOTICE_GRID_NO_CONNECTION = 'grid_no_connection';

    /** Mode flags passed to [media-api-widget-render] for each render mode. */
    private const MODE_FLAGS = [
        WidgetSchema::MODE_ITEM              => ['mediatitle' => 'false', 'mediadescription' => 'false', 'multiplegrid' => 'false', 'multiplegridusersearch' => 'false'],
        WidgetSchema::MODE_TITLE             => ['mediatitle' => 'true', 'mediadescription' => 'false', 'multiplegrid' => 'false', 'multiplegridusersearch' => 'false'],
        WidgetSchema::MODE_DESCRIPTION       => ['mediatitle' => 'false', 'mediadescription' => 'true', 'multiplegrid' => 'false', 'multiplegridusersearch' => 'false'],
        WidgetSchema::MODE_DESCRIPTION_TITLE => ['mediatitle' => 'true', 'mediadescription' => 'true', 'multiplegrid' => 'false', 'multiplegridusersearch' => 'false'],
        WidgetSchema::MODE_GRID              => ['mediatitle' => 'false', 'mediadescription' => 'false', 'multiplegrid' => 'true', 'multiplegridusersearch' => 'false'],
        WidgetSchema::MODE_SEARCH_GRID       => ['mediatitle' => 'false', 'mediadescription' => 'false', 'multiplegrid' => 'false', 'multiplegridusersearch' => 'true'],
    ];

    /**
     * Builds the render plan for one widget instance.
     *
     * @param array<string,mixed> $settings   Flat widget settings (classic or atomic).
     * @param string              $instanceId Elementor element ID, used for isolated search grids.
     * @return array{
     *     mode:string,
     *     playlist:string,
     *     platform:string,
     *     calls:array<int,array{tag:string,atts:array<string,string>}>,
     *     grid_id:?string,
     *     podcast_overrides:bool,
     *     error:string,
     *     notices:array<int,string>
     * } Render plan.
     */
    public static function plan(array $settings, string $instanceId): array
    {
        $s    = self::withDefaults($settings);
        $mode = WidgetSchema::scalar($s['output_mode']);

        if (!array_key_exists($mode, WidgetSchema::outputModes())) {
            $mode = WidgetSchema::MODE_ITEM;
        }

        $plan = [
            'mode'              => $mode,
            'playlist'          => '',
            'platform'          => '',
            'calls'             => [],
            'grid_id'           => null,
            'podcast_overrides' => false,
            'error'             => '',
            'notices'           => [],
        ];

        if ($mode === WidgetSchema::MODE_FIELD) {
            $field = sanitize_key(WidgetSchema::scalar($s['field_name']));
            if ($field === '') {
                $plan['error'] = self::ERROR_NO_FIELD;

                return $plan;
            }

            $plan['calls'][] = ['tag' => self::TAG_FIELD, 'atts' => ['field' => $field]];

            return $plan;
        }

        $platform = WidgetSchema::scalar($s['media_platform']) === 'podcast' ? 'podcast' : 'youtube';
        $playlist = sanitize_key(WidgetSchema::scalar($s[$platform === 'podcast' ? 'podcast_playlist' : 'youtube_playlist']));

        $plan['platform'] = $platform;
        $plan['playlist'] = $playlist;

        if ($mode === WidgetSchema::MODE_PODCAST_PLAYER && $platform !== 'podcast') {
            $plan['error'] = self::ERROR_PLAYER_PLATFORM;

            return $plan;
        }

        if ($playlist === '') {
            $plan['error'] = self::ERROR_NO_SOURCE;

            return $plan;
        }

        $source = ['playlist_name' => $playlist, 'media_platform' => $platform];

        if ($mode === WidgetSchema::MODE_PODCAST_PLAYER) {
            $plan['podcast_overrides'] = true;
            $plan['calls'][] = ['tag' => self::TAG_PLAYER, 'atts' => $source + self::attributes('main', $s)];

            return $plan;
        }

        if ($mode === WidgetSchema::MODE_SEARCH_BAR) {
            if (WidgetSchema::scalar($s['bar_link']) !== 'legacy') {
                $connection = sanitize_key(WidgetSchema::scalar($s['bar_connection']));
                if ($connection === '') {
                    $plan['error'] = self::ERROR_NO_CONNECTION;

                    return $plan;
                }

                $plan['grid_id'] = self::connectionGridId($playlist, $platform, $connection);
            }

            $plan['calls'][] = ['tag' => self::TAG_SEARCH, 'atts' => $source + self::attributes('main', $s)];

            return $plan;
        }

        $atts = $source + self::attributes('main', $s);
        foreach (self::MODE_FLAGS[$mode] as $flag => $value) {
            $atts[$flag] = $value;
        }

        if ($mode === WidgetSchema::MODE_SEARCH_GRID) {
            $plan['grid_id'] = self::searchGridId($s, $playlist, $platform, $instanceId, $plan['notices']);

            if (WidgetSchema::scalar($s['grid_show_search']) !== 'no') {
                $plan['calls'][] = ['tag' => self::TAG_SEARCH, 'atts' => $source + self::attributes('bar', $s)];
            }
        }

        $plan['calls'][] = ['tag' => self::TAG_RENDER, 'atts' => $atts];

        return $plan;
    }

    /**
     * Fills unset or null settings with their schema defaults.
     *
     * The atomic editor stores `null` for a value whose dependency is unmet,
     * and Elementor omits defaults when it saves, so both must fall back here.
     *
     * @param array<string,mixed> $settings Raw settings.
     * @return array<string,mixed> Settings with every schema key present.
     */
    public static function withDefaults(array $settings): array
    {
        $out = WidgetSchema::defaults();

        foreach ($settings as $key => $value) {
            if ($value !== null && array_key_exists($key, $out)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Returns the attributes contributed by the visible specs for one call target.
     *
     * @param string              $target   'main' or 'bar'.
     * @param array<string,mixed> $settings Settings with defaults applied.
     * @return array<string,string> Attribute name => value.
     */
    public static function attributes(string $target, array $settings): array
    {
        $atts = [];

        foreach (WidgetSchema::attributeSpecs() as $key => $spec) {
            if ($spec['target'] !== $target || !WidgetSchema::isVisible($spec['when'], $settings)) {
                continue;
            }

            $value = self::attributeValue($key, $spec, $settings);
            if ($value !== null) {
                $atts[$spec['attr']] = $value;
            }
        }

        return $atts;
    }

    /**
     * Resolves one spec to an attribute value, or null to omit the attribute.
     *
     * @param string              $key      Spec setting key.
     * @param array<string,mixed> $spec     Attribute spec.
     * @param array<string,mixed> $settings Settings with defaults applied.
     * @return string|null Attribute value, or null when the attribute is inherited.
     */
    public static function attributeValue(string $key, array $spec, array $settings): ?string
    {
        $source = WidgetSchema::scalar($settings[$key] ?? $spec['default']);

        if ($source === '' || $source === WidgetSchema::SOURCE_INHERIT) {
            return null;
        }

        if (str_starts_with($source, WidgetSchema::FIELD_PREFIX)) {
            $field = sanitize_key(substr($source, strlen(WidgetSchema::FIELD_PREFIX)));

            return $spec['fields'] && $field !== '' ? '{{' . $field . '}}' : null;
        }

        if ($source === WidgetSchema::SOURCE_EMPTY) {
            return $spec['empty'] && $spec['kind'] !== 'bool' ? '' : null;
        }

        if ($spec['kind'] === 'bool') {
            return in_array($source, ['true', 'false'], true) ? $source : null;
        }

        if ($spec['kind'] === 'enum') {
            return array_key_exists($source, (array) $spec['options']) ? $source : null;
        }

        if ($source !== WidgetSchema::SOURCE_VALUE) {
            return null;
        }

        $value = self::literal($settings[WidgetSchema::valueKey($key)] ?? $spec['default_value'], $spec['kind']);

        return trim($value) === '' ? null : $value;
    }

    /**
     * Returns the search id for a "Search bar only" connection.
     *
     * @param string $playlist   Sanitized playlist name.
     * @param string $platform   'youtube' or 'podcast'.
     * @param string $connection Sanitized connection ID.
     * @return string Grid search id.
     */
    public static function connectionGridId(string $playlist, string $platform, string $connection): string
    {
        return $playlist . '_' . $platform . '--c-' . $connection;
    }

    /**
     * Returns the search id for a searchable grid, or null for the shared link.
     *
     * @param array<string,mixed> $settings   Settings with defaults applied.
     * @param string              $playlist   Sanitized playlist name.
     * @param string              $platform   'youtube' or 'podcast'.
     * @param string              $instanceId Elementor element ID.
     * @param array<int,string>   $notices    Editor notices, appended to.
     * @return string|null Grid search id, or null to keep the shortcode default.
     */
    private static function searchGridId(array $settings, string $playlist, string $platform, string $instanceId, array &$notices): ?string
    {
        $link = WidgetSchema::scalar($settings['grid_link']);

        if ($link === 'legacy') {
            return null;
        }

        if ($link === 'connection') {
            $connection = sanitize_key(WidgetSchema::scalar($settings['grid_connection']));
            if ($connection !== '') {
                return self::connectionGridId($playlist, $platform, $connection);
            }

            $notices[] = self::NOTICE_GRID_NO_CONNECTION;
        }

        $slug = sanitize_key($instanceId);

        return $playlist . '_' . $platform . '--w' . ($slug !== '' ? $slug : 'x');
    }

    /**
     * Normalizes a value control's raw value to an attribute string.
     *
     * @param mixed  $raw  Raw control value (classic media controls store arrays).
     * @param string $kind Spec kind.
     * @return string Attribute string ('' when there is no usable value).
     */
    private static function literal($raw, string $kind): string
    {
        if ($kind === 'image' && is_array($raw)) {
            $raw = $raw['url'] ?? '';
        }

        if ($kind === 'number') {
            if (is_int($raw) || is_float($raw)) {
                return (string) $raw;
            }

            $raw = trim(WidgetSchema::scalar($raw));

            return is_numeric($raw) ? $raw : '';
        }

        return WidgetSchema::scalar($raw);
    }
}
