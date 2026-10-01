<?php
/**
 * Tests the Elementor integration's compatibility guarantees.
 *
 * The integration must be a thin translation layer over the existing shortcode
 * renderers, so most cases here compare a widget render against a *direct*
 * call of the matching public shortcode method with hand-written attributes —
 * never against the adapter's own output. If the widget ever produced markup
 * of its own, or interpreted a setting differently from the shortcode, these
 * comparisons are what would catch it.
 *
 * The remaining cases pin the backward-compatibility edges: a shortcode grid's
 * id, grid key and settings transient are unchanged; the podcast player's
 * attribute contract is unchanged for shortcodes; scoped filters never leak
 * past the widget that attached them; and an editor render cannot reach a
 * podcast feed.
 *
 * Elementor itself is not loaded. The widget classes are thin wrappers that
 * hand their settings to {@see WidgetRenderer}; their behavior inside a real
 * Elementor install is verified separately in an isolated WordPress instance.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/wp-shortcodes.php';

use MediaApiWidget\Config\Options;
use MediaApiWidget\Frontend\Shortcode;
use MediaApiWidget\Integrations\Elementor\Integration;
use MediaApiWidget\Integrations\Elementor\MediaSources;
use MediaApiWidget\Integrations\Elementor\SettingsAdapter;
use MediaApiWidget\Integrations\Elementor\ShortcodeText;
use MediaApiWidget\Integrations\Elementor\WidgetRenderer;
use MediaApiWidget\Integrations\Elementor\WidgetSchema;

/**
 * Builds a list of stored YouTube items in the plugin's normalized shape.
 *
 * @param int $count Number of items.
 * @return array<int,array<string,mixed>> Items, newest first.
 */
function maw_el_youtube_items(int $count): array
{
    $items = [];

    for ($n = $count; $n >= 1; $n--) {
        $items[] = [
            'episode'       => $n,
            'title'         => 'Show Episode ' . $n . ' Title',
            'id'            => sprintf('vid%05d', $n),
            'thumbnail'     => ['url' => 'https://i.ytimg.com/vi/' . $n . '/maxres.jpg', 'width' => 1280, 'height' => 720],
            'publishedDate' => '2026-01-0' . min($n, 9) . 'T12:00:00Z',
            'description'   => 'Description for episode ' . $n,
        ];
    }

    return $items;
}

/**
 * Builds a stored podcast payload (decoded form).
 *
 * @param int $count Number of episodes.
 * @return array<string,mixed> Feed tree.
 */
function maw_el_podcast_feed(int $count): array
{
    $items = [];

    for ($n = 1; $n <= $count; $n++) {
        $items[] = ['title' => 'Cast Episode ' . $n, 'description' => 'Cast description ' . $n, 'guid' => 'cast-' . $n, 'pubDate' => 'Mon, 05 Jan 2026 10:00:00 +0000'];
    }

    return ['channel' => ['title' => 'The Cast', 'rssUrl' => 'https://feeds.example.com/cast.xml', 'item' => $items]];
}

/**
 * Registers the real shortcodes and seeds configured sources with warm caches.
 *
 * @return void
 */
function maw_el_setup(): void
{
    MawTestShortcodes::reset();
    (new Shortcode())->register();

    Options::setMediaItems([
        ['type' => 'youtube', 'playlist_name' => 'show', 'api_key' => 'SECRET-API-KEY-DO-NOT-LEAK', 'media_data' => 'PLSECRETPLAYLIST', 'sort_mode' => 'number_in_title'],
        ['type' => 'podcast', 'playlist_name' => 'cast', 'podcast_platform' => 'custom', 'media_data' => 'https://feeds.example.com/cast.xml'],
        ['type' => 'podcast', 'playlist_name' => 'cold', 'podcast_platform' => 'custom', 'media_data' => 'https://feeds.example.com/cold.xml'],
    ]);

    set_transient('youtube_show', maw_el_youtube_items(6), 7200);
    set_transient('podcast_cast', (string) json_encode(maw_el_podcast_feed(3)), 7200);
}

/**
 * Returns widget settings for a YouTube widget, merged over overrides.
 *
 * @param array<string,mixed> $overrides Settings.
 * @return array<string,mixed> Settings.
 */
function maw_el_youtube(array $overrides = []): array
{
    return array_merge(['media_platform' => 'youtube', 'youtube_playlist' => 'show'], $overrides);
}

/**
 * Returns widget settings for the warm podcast, merged over overrides.
 *
 * @param array<string,mixed> $overrides Settings.
 * @return array<string,mixed> Settings.
 */
function maw_el_podcast(array $overrides = []): array
{
    return array_merge(['media_platform' => 'podcast', 'podcast_playlist' => 'cast'], $overrides);
}

/**
 * Renders a widget as on a published page.
 *
 * @param array<string,mixed> $settings   Widget settings.
 * @param string              $instanceId Element ID.
 * @return string HTML.
 */
function maw_el_render(array $settings, string $instanceId = 'abc1234'): string
{
    return WidgetRenderer::render($settings, $instanceId, false);
}

/**
 * Returns the main render call's attributes for a set of settings.
 *
 * @param array<string,mixed> $settings Widget settings.
 * @return array<string,string> Attributes.
 */
function maw_el_atts(array $settings): array
{
    $plan  = SettingsAdapter::plan($settings, 'abc1234');
    $calls = $plan['calls'];

    return end($calls)['atts'] ?? [];
}

/**
 * Extracts the first value of an HTML attribute from markup.
 *
 * @param string $html      Markup.
 * @param string $attribute Attribute name.
 * @return string Value, or '' when absent.
 */
function maw_el_attr(string $html, string $attribute): string
{
    return preg_match('/\s' . preg_quote($attribute, '/') . '="([^"]*)"/', $html, $m) === 1 ? html_entity_decode($m[1]) : '';
}

/**
 * Item-mode mode flags, as the adapter passes them.
 *
 * @return array<string,string> Flags.
 */
function maw_el_item_flags(): array
{
    return ['mediatitle' => 'false', 'mediadescription' => 'false', 'multiplegrid' => 'false', 'multiplegridusersearch' => 'false'];
}

return [

    // -- Schema invariants --------------------------------------------------

    'no two visible settings ever feed the same attribute' => static function (): void {
        maw_el_setup();
        $conflicts = [];

        foreach (array_keys(WidgetSchema::outputModes()) as $mode) {
            foreach (['youtube', 'podcast'] as $platform) {
                foreach (['position', 'episode', 'title', 'advanced'] as $selectBy) {
                    foreach (['yes', 'no'] as $gridSearch) {
                        foreach (['inherit', 'true'] as $showAll) {
                            foreach (['inherit', 'custom', 'omny'] as $podcastPlatform) {
                                $settings = SettingsAdapter::withDefaults([
                                    'output_mode'         => $mode,
                                    'media_platform'      => $platform,
                                    'item_select_by'      => $selectBy,
                                    'grid_show_search'    => $gridSearch,
                                    'multiplegridshowall' => $showAll,
                                    'podcast_platform'    => $podcastPlatform,
                                ]);

                                $seen = [];
                                foreach (WidgetSchema::attributeSpecs() as $key => $spec) {
                                    if (!WidgetSchema::isVisible($spec['when'], $settings)) {
                                        continue;
                                    }
                                    $slot = $spec['target'] . ':' . $spec['attr'];
                                    if (isset($seen[$slot])) {
                                        $conflicts[] = "{$mode}/{$platform}/{$selectBy}: {$seen[$slot]} and {$key} both feed {$slot}";
                                    }
                                    $seen[$slot] = $key;
                                }
                            }
                        }
                    }
                }
            }
        }

        maw_assert_same([], array_values(array_unique($conflicts)), 'visibility rules for the same attribute are mutually exclusive');
    },

    'every attribute setting is scoped to output modes and references real settings' => static function (): void {
        maw_el_setup();
        $known = array_keys(WidgetSchema::defaults());

        foreach (WidgetSchema::attributeSpecs() as $key => $spec) {
            $keys = array_column($spec['when'], 0);
            maw_assert(in_array('output_mode', $keys, true), $key . ' has an output_mode rule');

            foreach ($keys as $ruleKey) {
                maw_assert(in_array($ruleKey, $known, true), $key . ' rule references existing setting ' . $ruleKey);
            }
        }
    },

    // -- Equivalence with the shortcode renderers ---------------------------

    'a media card renders exactly what the shortcode renders' => static function (): void {
        maw_el_setup();

        $widget = maw_el_render(maw_el_youtube([
            'position_value'        => '2',
            'showtextoverlay'       => 'false',
            'playbuttonstyling'     => 'value',
            'playbuttonstyling_value' => 'width: 20%;',
        ]));

        $shortcode = (new Shortcode())->renderMediaShortcode([
            'playlist_name'     => 'show',
            'media_platform'    => 'youtube',
            'orderdescending'   => '2',
            'showtextoverlay'   => 'false',
            'playbuttonstyling' => 'width: 20%;',
        ] + maw_el_item_flags());

        maw_assert(str_contains($widget, 'data-itemclickableplaylist="show_youtube"'), 'the widget renders a media card');
        maw_assert_same($shortcode, $widget, 'widget output is byte-identical to the shortcode');
    },

    'grid, title text and field output match their shortcodes' => static function (): void {
        maw_el_setup();
        Options::setShortcodes([['field' => 'tagline', 'value' => 'New episodes <weekly>']]);

        $grid = maw_el_render(maw_el_youtube([
            'output_mode'            => 'grid',
            'multiplegridgap'        => 'value',
            'multiplegridgap_value'  => '12px',
            'multiplegridtext'       => 'both',
            'multiplegridlimititems' => 'value',
            'multiplegridlimititems_value' => '3',
        ]));
        maw_assert_same((new Shortcode())->renderMediaShortcode([
            'playlist_name' => 'show', 'media_platform' => 'youtube', 'multiplegrid' => 'true',
            'multiplegridgap' => '12px', 'multiplegridtext' => 'both', 'multiplegridlimititems' => '3',
            'mediatitle' => 'false', 'mediadescription' => 'false', 'multiplegridusersearch' => 'false',
        ]), $grid, 'grid output matches');
        maw_assert_same(3, substr_count($grid, 'data-itemclickable="true"'), 'the limit is applied by the renderer');

        $title = maw_el_render(maw_el_youtube(['output_mode' => 'title', 'position_value' => '3']));
        maw_assert_same((new Shortcode())->renderMediaShortcode([
            'playlist_name' => 'show', 'media_platform' => 'youtube', 'orderdescending' => '3', 'mediatitle' => 'true',
            'mediadescription' => 'false', 'multiplegrid' => 'false', 'multiplegridusersearch' => 'false',
        ]), $title, 'title text matches');
        maw_assert(str_contains($title, '<p class="maw-media-description-text media-description-text">Show Episode 4 Title</p>'), 'the title paragraph is rendered');

        $field = maw_el_render(['output_mode' => 'field', 'field_name' => 'tagline']);
        maw_assert_same((new Shortcode())->renderFieldShortcode(['field' => 'tagline']), $field, 'field output matches');
        maw_assert_same('New episodes &lt;weekly&gt;', $field, 'the renderer escapes the stored value');
    },

    'a podcast card renders exactly what the shortcode renders' => static function (): void {
        maw_el_setup();

        $widget = maw_el_render(maw_el_podcast([
            'thumbnail'                => 'value',
            'thumbnail_value'          => ['url' => 'https://example.test/cover.jpg', 'id' => 5],
            'lightboxshowplaylist'     => 'true',
            'podcastplayercolor'       => 'value',
            'podcastplayercolor_value' => '#123456',
        ]));

        $shortcode = (new Shortcode())->renderMediaShortcode([
            'playlist_name'        => 'cast',
            'media_platform'       => 'podcast',
            'orderdescending'      => '1',
            'thumbnail'            => 'https://example.test/cover.jpg',
            'lightboxshowplaylist' => 'true',
            'podcastplayercolor'   => '#123456',
        ] + maw_el_item_flags());

        maw_assert_same($shortcode, $widget, 'podcast card output is byte-identical to the shortcode');
        maw_assert_same('123456', maw_el_attr($widget, 'data-podcastplayercolor'), 'the custom player color reaches the card');
    },

    // -- Inherit / explicit / empty / references ----------------------------

    'inherited settings omit attributes so shortcode defaults apply' => static function (): void {
        maw_el_setup();

        maw_assert_same(
            ['playlist_name' => 'show', 'media_platform' => 'youtube', 'orderdescending' => '1'] + maw_el_item_flags(),
            maw_el_atts(maw_el_youtube()),
            'a new card widget passes only the source, its default position, and the mode flags'
        );

        $html = maw_el_render(maw_el_youtube());
        maw_assert(str_contains($html, 'width: 35%; height: 35%; opacity: 0.3;'), 'the shortcode default play button styling applies');
        maw_assert(str_contains($html, 'Click Here To Watch'), 'the shortcode default instruction message applies');
    },

    'explicit false, explicit empty, literals and field references reach the renderer' => static function (): void {
        maw_el_setup();
        Options::setShortcodes([['field' => 'brand_color', 'value' => '#ff0000'], ['field' => 'cta', 'value' => 'Listen now']]);

        $atts = maw_el_atts(maw_el_podcast([
            'showplaybutton'           => 'false',
            'showtextoverlay'          => 'true',
            'instructionmessage'       => 'field:cta',
            'fontfamily'               => 'empty',
            'showplaybar'              => 'true',
            'playbarcolor'             => 'field:brand_color',
        ]));

        maw_assert_same('false', $atts['showplaybutton'] ?? null, 'explicit false is passed as "false"');
        maw_assert_same('', $atts['fontfamily'] ?? null, 'explicit empty is passed as an empty string');
        maw_assert_same('{{cta}}', $atts['instructionmessage'] ?? null, 'a stored field is passed as a reference');
        maw_assert_same('{{brand_color}}', $atts['playbarcolor'] ?? null, 'a color can reference a stored field');

        $html = maw_el_render(maw_el_podcast([
            'showtextoverlay'    => 'true',
            'instructionmessage' => 'field:cta',
            'showplaybar'        => 'true',
            'playbarcolor'       => 'field:brand_color',
        ]));
        maw_assert(str_contains($html, '<p>Listen now</p>'), 'the renderer resolves the text reference');
        maw_assert(str_contains($html, 'style="fill:ff0000"'), 'the renderer resolves the color reference');
    },

    'a same-named stored field still acts as the default for inherited settings' => static function (): void {
        maw_el_setup();
        Options::setShortcodes([['field' => 'instructionmessage', 'value' => 'Global CTA']]);

        maw_assert(str_contains(maw_el_render(maw_el_youtube()), '<p>Global CTA</p>'), 'inherit uses the stored-field default');

        maw_assert(str_contains(maw_el_render(maw_el_youtube([
            'instructionmessage' => 'value', 'instructionmessage_value' => 'Local CTA',
        ])), '<p>Local CTA</p>'), 'a literal overrides the stored-field default');

        maw_assert(str_contains(maw_el_render(maw_el_youtube([
            'instructionmessage' => 'empty',
        ])), '<p>Click Here To Watch</p>'), 'explicit empty overrides it with the renderer\'s own fallback');
    },

    'a custom value with nothing entered inherits' => static function (): void {
        maw_el_setup();

        $atts = maw_el_atts(maw_el_youtube(['playbuttonstyling' => 'value', 'playbuttonstyling_value' => '   ']));

        maw_assert(!array_key_exists('playbuttonstyling', $atts), 'a blank custom value is not passed');
    },

    'hidden settings never override the active output mode' => static function (): void {
        maw_el_setup();

        $atts = maw_el_atts(maw_el_youtube([
            'output_mode'             => 'item',
            'multiplegridgap'         => 'value',
            'multiplegridgap_value'   => '1px',
            'multiplegridperpage'     => 'value',
            'multiplegridperpage_value' => '2',
            'mediadescriptiontextcolor' => 'value',
            'mediadescriptiontextcolor_value' => '#000000',
            'thumbnail'               => 'value',
            'thumbnail_value'         => 'https://example.test/podcast-only.jpg',
            'showplaybutton'          => 'false',
            'playbuttonstyling'       => 'value',
            'playbuttonstyling_value' => 'display:none',
            'title_keyword'           => 'value',
            'title_keyword_value'     => 'Episode 3',
        ]));

        foreach (['multiplegridgap', 'multiplegridperpage', 'mediadescriptiontextcolor', 'thumbnail', 'playbuttonstyling', 'nameselect'] as $attr) {
            maw_assert(!array_key_exists($attr, $atts), $attr . ' saved under another mode or condition is ignored');
        }

        maw_assert_same('false', $atts['showplaybutton'] ?? null, 'the visible setting still applies');
    },

    'the search bar renderer is never handed field references' => static function (): void {
        maw_el_setup();
        Options::setShortcodes([['field' => 'search_label', 'value' => 'Find']]);

        $atts = maw_el_atts(maw_el_youtube([
            'output_mode' => 'search_bar', 'bar_link' => 'legacy', 'bar_placeholder' => 'field:search_label',
        ]));

        maw_assert(!array_key_exists('placeholder', $atts), 'the unsupported reference is not passed');
    },

    'atomic-style values (ints and nulls) map like classic strings' => static function (): void {
        maw_el_setup();

        $classic = maw_el_atts(maw_el_youtube(['position_value' => '4', 'showplaybutton' => 'false']));
        $atomic  = maw_el_atts(maw_el_youtube([
            'position_value' => 4, 'showplaybutton' => 'false', 'multiplegridgap' => null, 'multiplegridgap_value' => null,
        ]));

        maw_assert_same($classic, $atomic, 'both editors produce the same attributes');
    },

    // -- Dispatch through the registered shortcode --------------------------

    'widgets dispatch through the registered callback and its tag filters' => static function (): void {
        maw_el_setup();

        maw_on('do_shortcode_tag', static fn ($output, $tag) => $tag === 'media-api-widget-render' ? '<div class="wrapped">' . $output . '</div>' : $output);
        maw_assert(str_starts_with(maw_el_render(maw_el_youtube()), '<div class="wrapped"><!-- show youtube item'), 'do_shortcode_tag filters the widget output');

        maw_on('pre_do_shortcode_tag', static fn ($pre, $tag) => $tag === 'media-api-widget-render' ? 'short-circuited' : $pre);
        maw_assert_same('short-circuited', maw_el_render(maw_el_youtube()), 'pre_do_shortcode_tag can short-circuit the widget');

        remove_all_filters('pre_do_shortcode_tag');
        remove_all_filters('do_shortcode_tag');
        add_shortcode('media-api-widget-render', static fn ($atts) => 'replacement:' . ($atts['playlist_name'] ?? ''));
        maw_assert_same('replacement:show', maw_el_render(maw_el_youtube()), 'a replaced shortcode callback is honored');

        remove_shortcode('media-api-widget-render');
        maw_assert_same('', maw_el_render(maw_el_youtube()), 'a removed shortcode renders nothing');
    },

    'the shortcode tags and aliases are unchanged' => static function (): void {
        global $shortcode_tags;
        maw_el_setup();

        maw_assert_same(
            ['media-api-widget', 'media-api-widget-render', 'media-api-widget-item', 'media-api-podcast-player', 'media-api-widget-grid-search'],
            array_keys($shortcode_tags),
            'every tag is still registered'
        );
        maw_assert_same($shortcode_tags['media-api-widget-render'][1], $shortcode_tags['media-api-widget-item'][1], 'the alias still shares the render handler');
    },

    // -- Search grid instance isolation -------------------------------------

    'isolated searchable grids for one playlist stay independent' => static function (): void {
        maw_el_setup();

        $settings = maw_el_youtube(['output_mode' => 'search_grid', 'multiplegridperpage' => 'value', 'multiplegridperpage_value' => '2']);
        $first    = maw_el_render($settings, 'aaa1111');
        $second   = maw_el_render($settings, 'bbb2222');

        maw_assert_same('show_youtube--waaa1111', maw_el_attr($first, 'data-maw-grid-id'), 'the first grid gets its own search id');
        maw_assert_same('show_youtube--wbbb2222', maw_el_attr($second, 'data-maw-grid-id'), 'the second grid gets its own search id');
        maw_assert_same('show_youtube--waaa1111', maw_el_attr($first, 'data-maw-for'), 'the built-in search bar targets its own grid');
        maw_assert_same('maw_page_show_youtube--wbbb2222', maw_el_attr($second, 'data-maw-page-param'), 'each grid paginates with its own query parameter');
        maw_assert(maw_el_attr($first, 'data-maw-grid-key') !== maw_el_attr($second, 'data-maw-grid-key'), 'each grid stores its own settings transient');

        $config = get_transient('maw_grid_' . maw_el_attr($second, 'data-maw-grid-key'));
        maw_assert_same('show_youtube--wbbb2222', $config['gridId'] ?? null, 'the transient records the instance id for AJAX');

        $_GET['maw_page_show_youtube--wbbb2222'] = '2';
        maw_assert(str_contains(maw_el_render($settings, 'bbb2222'), 'aria-current="page">2<'), 'the second grid reads its own page parameter');
        maw_assert(str_contains(maw_el_render($settings, 'aaa1111'), 'aria-current="page">1<'), 'the first grid is unaffected');
    },

    'shortcode grids keep their id, grid key and transient exactly' => static function (): void {
        maw_el_setup();

        // Render a widget first: its scoped filter must not leak.
        maw_el_render(maw_el_youtube(['output_mode' => 'search_grid']), 'aaa1111');

        $atts = ['playlist_name' => 'show', 'media_platform' => 'youtube', 'multiplegridusersearch' => 'true', 'multiplegridperpage' => '2'];
        $html = (new Shortcode())->renderMediaShortcode($atts);
        $bar  = (new Shortcode())->renderGridSearchShortcode(['playlist_name' => 'show', 'media_platform' => 'youtube']);

        maw_assert_same('show_youtube', maw_el_attr($html, 'data-maw-grid-id'), 'the shortcode grid keeps the shared id');
        maw_assert_same('maw_page_show_youtube', maw_el_attr($html, 'data-maw-page-param'), 'the shortcode grid keeps its page parameter');
        maw_assert_same('show_youtube', maw_el_attr($bar, 'data-maw-for'), 'the shortcode search bar keeps the shared id');
        maw_assert_same('maw-search-input-show_youtube', maw_el_attr($bar, 'id'), 'the search input id is unchanged');

        // Recompute the key with the original formula.
        $settings = [
            'showPlayButton' => true, 'playButtonIconImgUrl' => '', 'playButtonStyling' => 'width: 35%; height: 35%; opacity: 0.3;',
            'showTextOverlay' => true, 'instructionMessage' => 'Click Here To Watch', 'fontFamily' => '', 'lightboxshowlogoimgurl' => '',
            'lightboxfont' => '', 'lightboxthemecolor' => '', 'lightboxshowplaylist' => false, 'showPlaybar' => false, 'playbarColor' => 'fff',
            'podcastplayermode' => '', 'podcastplayerbuttoncolor' => '', 'podcastplayercolor' => '', 'podcastprogressplayerbarcolor' => '',
            'podcastplayerhighlightcolor' => '', 'podcastplayerfont' => '', 'podcastplayerscrollcolor' => '', 'podcastplayertextcolor' => '',
            'showepisodedateaftertitle' => '', 'noStyling' => false, 'showLightbox' => true,
        ];
        $expectedKey = substr(md5(json_encode($settings) . 'show' . 'youtube' . '48px' . '400px' . '' . '2' . '0' . '0'), 0, 16);
        maw_assert_same($expectedKey, maw_el_attr($html, 'data-maw-grid-key'), 'the grid key uses the original formula');

        $config = get_transient('maw_grid_' . $expectedKey);
        maw_assert_same(
            ['settings', 'gap', 'minsize', 'multiplegridtext', 'episodeRange', 'showall', 'noresults', 'noStyling', 'maxPages', 'maxDisplay'],
            array_keys((array) $config),
            'the settings transient has exactly the original keys'
        );
        maw_assert(!has_filter('media_api_widget_grid_search_id'), 'no grid id filter remains attached');
    },

    'a connection id links a separate search bar widget to its grid' => static function (): void {
        maw_el_setup();

        $grid = maw_el_render(maw_el_youtube(['output_mode' => 'search_grid', 'grid_link' => 'connection', 'grid_connection' => 'Episodes', 'grid_show_search' => 'no']), 'aaa1111');
        $bar  = maw_el_render(maw_el_youtube(['output_mode' => 'search_bar', 'bar_connection' => 'Episodes']), 'ccc3333');

        maw_assert(!str_contains($grid, 'maw-grid-search-bar'), 'the grid renders without its own bar');
        maw_assert_same('show_youtube--c-episodes', maw_el_attr($grid, 'data-maw-grid-id'), 'the grid uses the connection id');
        maw_assert_same(maw_el_attr($grid, 'data-maw-grid-id'), maw_el_attr($bar, 'data-maw-for'), 'the separate bar targets the grid');

        $legacyBar = maw_el_render(maw_el_youtube(['output_mode' => 'search_bar', 'bar_link' => 'legacy']));
        maw_assert_same('show_youtube', maw_el_attr($legacyBar, 'data-maw-for'), 'the shared link targets shortcode grids');
        maw_assert_same((new Shortcode())->renderGridSearchShortcode(['playlist_name' => 'show', 'media_platform' => 'youtube']), $legacyBar, 'a shared-link bar is byte-identical to the shortcode');
    },

    'AJAX pagination keeps the page parameter of the grid that was rendered' => static function (): void {
        maw_el_setup();

        $run = static function (string $gridKey): array {
            $_POST = ['nonce' => 'n', 'playlist_name' => 'show', 'media_platform' => 'youtube', 'grid_key' => $gridKey, 'page' => '1', 'per_page' => '2', 'base_url' => 'https://example.test/page/'];
            try {
                (new Shortcode())->handleGridSearchAjax();
            } catch (MawTestJsonResponse $response) {
                return (array) $response->data;
            }

            return [];
        };

        $widget = maw_el_render(maw_el_youtube(['output_mode' => 'search_grid', 'multiplegridperpage' => 'value', 'multiplegridperpage_value' => '2']), 'aaa1111');
        $legacy = (new Shortcode())->renderMediaShortcode(['playlist_name' => 'show', 'media_platform' => 'youtube', 'multiplegridusersearch' => 'true', 'multiplegridperpage' => '2']);

        maw_assert(str_contains((string) ($run(maw_el_attr($widget, 'data-maw-grid-key'))['pagination'] ?? ''), 'maw_page_show_youtube--waaa1111=2'), 'widget grid AJAX links use the widget parameter');
        maw_assert(str_contains((string) ($run(maw_el_attr($legacy, 'data-maw-grid-key'))['pagination'] ?? ''), 'maw_page_show_youtube=2'), 'shortcode grid AJAX links are unchanged');
    },

    // -- Podcast player -----------------------------------------------------

    'player styling applies to the widget call only and the shortcode contract is unchanged' => static function (): void {
        maw_el_setup();

        $widget = maw_el_render(maw_el_podcast([
            'output_mode' => 'podcast_player', 'player_color' => 'value', 'player_color_value' => '#123456', 'player_showdate' => 'true',
        ]));
        $src = maw_el_attr($widget, 'src');
        maw_assert(str_contains($src, 'color1=123456'), 'the widget override reaches the player');
        maw_assert(str_contains($src, 'adddatetotitle=true'), 'the widget date option reaches the player');
        maw_assert(str_contains($src, 'textcolor=ffffff'), 'other styling still comes from the podcast_player_* fields');
        maw_assert(!has_filter('shortcode_atts_media-api-podcast-player'), 'the passthrough filter is removed after the widget renders');

        $shortcodeSrc = maw_el_attr((new Shortcode())->renderPodcastPlayerShortcode([
            'playlist_name' => 'cast', 'podcastplayercolor' => '#123456', 'showepisodedateaftertitle' => 'true',
        ]), 'src');
        maw_assert(str_contains($shortcodeSrc, 'color1=c7c7c7'), 'the shortcode still ignores the attribute and uses the stored field');
        maw_assert(!str_contains($shortcodeSrc, 'adddatetotitle'), 'the shortcode still ignores showepisodedateaftertitle');

        $inherited = maw_el_render(maw_el_podcast(['output_mode' => 'podcast_player']));
        maw_assert_same((new Shortcode())->renderPodcastPlayerShortcode(['playlist_name' => 'cast', 'media_platform' => 'podcast']), $inherited, 'an all-default player matches the shortcode exactly');
    },

    'the podcast player refuses a YouTube source' => static function (): void {
        maw_el_setup();

        maw_assert_same('', maw_el_render(maw_el_youtube(['output_mode' => 'podcast_player'])), 'nothing renders on the front end');
        maw_assert(str_contains(WidgetRenderer::render(maw_el_youtube(['output_mode' => 'podcast_player']), 'x', true), 'needs a podcast source'), 'the editor explains why');
    },

    // -- Editor safety ------------------------------------------------------

    'an editor render never requests a podcast feed' => static function (): void {
        maw_el_setup();
        maw_queue_raw('<rss><channel><title>Cold</title><item><title>Cold 1</title><guid>c1</guid></item></channel></rss>');

        $editor = WidgetRenderer::render(maw_el_podcast(['podcast_playlist' => 'cold']), 'x', true);
        maw_assert(str_contains($editor, 'not cached yet'), 'the editor shows a notice');
        maw_assert_same(0, count(MawTestState::$httpRequests), 'no request is made from the editor');

        $player = WidgetRenderer::render(maw_el_podcast(['podcast_playlist' => 'cold', 'output_mode' => 'podcast_player']), 'x', true);
        maw_assert(str_contains($player, 'not cached yet'), 'the player is guarded too');
        maw_assert_same(0, count(MawTestState::$httpRequests), 'still no request');

        maw_el_render(maw_el_podcast(['podcast_playlist' => 'cold']));
        maw_assert_same(1, count(MawTestState::$httpRequests), 'a published page warms the cache exactly as the shortcode does');
    },

    'editor notices never reach published pages' => static function (): void {
        maw_el_setup();

        maw_assert_same('', maw_el_render(['media_platform' => 'youtube']), 'a widget without a source renders nothing');
        maw_assert(str_contains(WidgetRenderer::render(['media_platform' => 'youtube'], 'x', true), 'maw-elementor-notice'), 'the editor explains the missing source');
        maw_assert_same('', maw_el_render(maw_el_youtube(['output_mode' => 'search_bar'])), 'a search bar without a connection renders nothing');
    },

    // -- Plain content, sources, Elementor absence --------------------------

    'plain content is the equivalent shortcode text' => static function (): void {
        maw_el_setup();

        maw_assert_same(
            '[media-api-widget-render playlist_name="show" media_platform="youtube" orderdescending="1" instructionmessage=\'Say "hi"\' mediatitle="false" mediadescription="false" multiplegrid="false" multiplegridusersearch="false"]',
            ShortcodeText::forSettings(maw_el_youtube(['instructionmessage' => 'value', 'instructionmessage_value' => 'Say "hi"', 'fontfamily' => 'value', 'fontfamily_value' => 'a[b]']), 'x'),
            'plain content mirrors the shortcode and drops a value a shortcode cannot hold'
        );
        maw_assert_same('', ShortcodeText::forSettings(['media_platform' => 'youtube'], 'x'), 'an incomplete widget writes nothing');
    },

    'media source options never expose credentials' => static function (): void {
        maw_el_setup();

        $encoded = (string) json_encode([MediaSources::all(), MediaSources::optionsFor('youtube'), WidgetSchema::structuralControls()]);
        maw_assert(!str_contains($encoded, 'SECRET-API-KEY-DO-NOT-LEAK'), 'the API key is not exposed');
        maw_assert(!str_contains($encoded, 'PLSECRETPLAYLIST'), 'the playlist id is not exposed');
        maw_assert(!str_contains($encoded, 'cast.xml'), 'the feed URL is not exposed');
        maw_assert_same(['show' => 'show'], MediaSources::optionsFor('youtube'), 'playlists are listed by name');
    },

    'without Elementor the integration registers nothing and cannot fatal' => static function (): void {
        maw_el_setup();

        (new Integration())->register();
        maw_assert(has_filter('elementor/widgets/register'), 'the integration only hooks Elementor events');

        $manager = new class {
            /** @var array<int,object> */
            public array $registered = [];

            public function register(object $widget): void
            {
                $this->registered[] = $widget;
            }
        };

        (new Integration())->registerWidgets($manager);
        maw_assert_same([], $manager->registered, 'no widget is registered while Widget_Base is undefined');
        maw_assert_same(false, Integration::atomicAvailable(), 'the atomic widget reports unavailable');
        maw_assert(!class_exists('MediaApiWidget\\Integrations\\Elementor\\ClassicWidget', false), 'the classic widget class was never loaded');
    },
];
