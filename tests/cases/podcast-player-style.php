<?php
/**
 * Tests the podcast player styling shared by the shortcode and the
 * "Media API Click Action" dynamic tag.
 *
 * A Click Action link carries no styling of its own. The front-end script
 * gives a podcast link the values in `mawPodcastPlayerStyle` (localized on
 * every front-end page from {@see PlayerStyle::savedDefaults()}) before the
 * playlist's own click handler builds the `/podcast/player` URL. These cases
 * pin the PHP half of that chain:
 *
 * - the exposed values are exactly the styling query values a bare
 *   `[media-api-podcast-player]` sends, for default, edited, referenced and
 *   blank fields;
 * - the player falls back to its own defaults for empty parameters, keeps
 *   `dark` / `light`, hex with or without '#', and fonts with spaces;
 * - the shortcode's restricted attribute contract and the Elementor widget's
 *   scoped overrides are unchanged by the shared resolution.
 *
 * The JavaScript half (the click handler applying these values, keeping the
 * episode GUID, autoplay and event contract) is covered by
 * tests/js/click-action-player-style.test.mjs.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/wp-shortcodes.php';
require_once dirname(__DIR__) . '/support/wp-scripts.php';

use MediaApiWidget\Config\Options;
use MediaApiWidget\Frontend\Assets;
use MediaApiWidget\Frontend\Shortcode;
use MediaApiWidget\Integrations\Elementor\WidgetRenderer;
use MediaApiWidget\PodcastPlayer\DataParams;
use MediaApiWidget\PodcastPlayer\PlayerStyle;

/**
 * Configures one custom RSS podcast with a warm cache and registers the shortcodes.
 *
 * @param array<int,array<string,string>> $fields Shortcode fields to store, or [] for the seeded defaults.
 * @return void
 */
function maw_pps_setup(array $fields = []): void
{
    MawTestShortcodes::reset();
    MawTestScripts::reset();
    (new Shortcode())->register();

    Options::setMediaItems([
        ['type' => 'podcast', 'playlist_name' => 'cast', 'podcast_platform' => 'custom', 'media_data' => 'https://feeds.example.com/cast.xml'],
    ]);
    set_transient('podcast_cast', (string) json_encode(['channel' => ['title' => 'The Cast', 'rssUrl' => 'https://feeds.example.com/cast.xml', 'item' => [
        ['title' => 'Cast Episode 1', 'guid' => 'cast-1', 'pubDate' => 'Mon, 05 Jan 2026 10:00:00 +0000'],
    ]]]), 7200);

    if ($fields !== []) {
        Options::setShortcodes($fields);
    }
}

/**
 * Returns the stored shortcode fields with some values replaced.
 *
 * @param array<string,string> $values Field => value.
 * @return array<int,array<string,string>> Field rows.
 */
function maw_pps_fields(array $values): array
{
    $rows = [];
    foreach (Options::getDefaultShortcodes() as $row) {
        $rows[] = ['field' => $row['field'], 'value' => $values[$row['field']] ?? $row['value']];
        unset($values[$row['field']]);
    }
    foreach ($values as $field => $value) {
        $rows[] = ['field' => $field, 'value' => $value];
    }

    return $rows;
}

/**
 * Returns the query parameters of the first iframe src in markup.
 *
 * @param string $html Markup.
 * @return array<string,string> Query parameters.
 */
function maw_pps_src_query(string $html): array
{
    if (preg_match('/src="([^"]*)"/', $html, $m) !== 1) {
        return [];
    }

    parse_str((string) parse_url(html_entity_decode($m[1], ENT_QUOTES), PHP_URL_QUERY), $query);

    return array_map('strval', $query);
}

/**
 * Re-keys styling query parameters by attribute, as savedDefaults() returns them.
 *
 * @param array<string,string> $query Query parameters.
 * @return array<string,string> Attribute => value, in FIELDS order.
 */
function maw_pps_by_attribute(array $query): array
{
    $out = [];
    foreach (array_keys(PlayerStyle::FIELDS) as $attribute) {
        $out[$attribute] = $query[PlayerStyle::QUERY[$attribute]] ?? '(missing)';
    }

    return $out;
}

/**
 * Builds the player's style for a query string, as /podcast/player does.
 *
 * @param string $query Query string after "?".
 * @return array<string,string> DataParams style.
 */
function maw_pps_player_style(string $query): array
{
    $previous = $_SERVER['REQUEST_URI'] ?? null;

    try {
        $_SERVER['REQUEST_URI'] = '/podcast/player?' . $query;
        maw_queue_raw('<rss><channel><title>Cast</title><item><title>One</title><guid>cast-1</guid></item></channel></rss>');

        return (new DataParams())->build()['style'];
    } finally {
        if ($previous === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $previous;
        }
    }
}

return [

    'the click action styling equals the bare shortcode styling for the seeded fields' => static function (): void {
        maw_pps_setup();

        $saved = PlayerStyle::savedDefaults();
        maw_assert_same([
            'podcastplayermode'             => '151515',
            'podcastplayertextcolor'        => 'ffffff',
            'podcastplayerbuttoncolor'      => 'ffffff',
            'podcastplayercolor'            => 'c7c7c7',
            'podcastprogressplayerbarcolor' => '616161',
            'podcastplayerhighlightcolor'   => '7a7a7a',
            'podcastplayerfont'             => 'Roboto',
            'podcastplayerscrollcolor'      => 'c7c7c7',
        ], $saved, 'all eight podcast_player_* fields are exposed, colors without "#"');

        $shortcode = maw_pps_src_query((new Shortcode())->renderPodcastPlayerShortcode(['playlist_name' => 'cast']));
        maw_assert_same($saved, maw_pps_by_attribute($shortcode), 'the values are exactly the ones the bare shortcode sends');
    },

    'edited, referenced and blank fields resolve the same way for both' => static function (): void {
        maw_pps_setup(maw_pps_fields([
            'podcast_player_background_color'   => 'light',
            'podcast_player_text_color'         => 'ABCDEF',
            'podcast_player_play_icon_color'    => '#fff',
            'podcast_player_color'              => '{{brand_accent}}',
            'podcast_player_progress_bar_color' => '[media-api-widget field="brand_accent"]',
            'podcast_player_selected_color'     => '  #7A7A7A ',
            'podcast_player_font'               => 'Open Sans',
            'podcast_player_scrollbar_color'    => '',
            'brand_accent'                      => '#123456',
        ]));

        $saved = PlayerStyle::savedDefaults();
        maw_assert_same([
            'podcastplayermode'             => 'light',
            'podcastplayertextcolor'        => 'ABCDEF',
            'podcastplayerbuttoncolor'      => 'fff',
            'podcastplayercolor'            => '123456',
            'podcastprogressplayerbarcolor' => '123456',
            'podcastplayerhighlightcolor'   => '7A7A7A',
            'podcastplayerfont'             => 'Open Sans',
            'podcastplayerscrollcolor'      => '',
        ], $saved, 'light mode, bare hex, both reference syntaxes, a font with a space, and a blank field');

        $shortcode = maw_pps_src_query((new Shortcode())->renderPodcastPlayerShortcode(['playlist_name' => 'cast']));
        maw_assert_same($saved, maw_pps_by_attribute($shortcode), 'the bare shortcode resolves them identically');

        $style = maw_pps_player_style(http_build_query(['url' => 'https://feeds.example.com/cast.xml'] + PlayerStyle::queryArgs($saved)));
        maw_assert_same('#fff', $style['mode'], 'light mode reaches the player');
        maw_assert_same('#abcdef', $style['textColor'], 'a hex without "#" is kept whole');
        maw_assert_same('#fff', $style['playButton'], 'a short hex with "#" is kept');
        maw_assert_same('#123456', $style['color1'], 'a referenced field reaches the player');
        maw_assert_same('#123456', $style['progressBarColor'], 'the shortcode-style reference too');
        maw_assert_same('#7a7a7a', $style['highlight'], 'surrounding spaces are ignored');
        maw_assert_same('Open Sans', $style['font'], 'a font name with a space survives the URL');
        maw_assert_same('#bbbbbb', $style['scrollbar'], 'a blank field falls back to the player default, not white');
    },

    'a field that references a missing field matches the shortcode' => static function (): void {
        maw_pps_setup(maw_pps_fields(['podcast_player_color' => '{{nope}}']));

        $saved     = PlayerStyle::savedDefaults();
        $shortcode = maw_pps_src_query((new Shortcode())->renderPodcastPlayerShortcode(['playlist_name' => 'cast']));

        maw_assert_same('{{nope}}', $saved['podcastplayercolor'], 'the unresolvable reference is passed on unchanged');
        maw_assert_same($saved, maw_pps_by_attribute($shortcode), 'exactly as the shortcode passes it');
    },

    'empty styling parameters use the player defaults' => static function (): void {
        $defaults = maw_pps_player_style('url=' . rawurlencode('https://feeds.example.com/cast.xml'));
        maw_assert_same([
            'mode' => '#000', 'color1' => '#bbbbbb', 'progressBarColor' => '#616161', 'playButton' => '#ffffff',
            'highlight' => '#888888', 'scrollbar' => '#bbbbbb', 'textColor' => '#ffffff', 'font' => 'Poppins',
        ], $defaults, 'the documented defaults when no styling is sent');

        $empty = maw_pps_player_style('url=' . rawurlencode('https://feeds.example.com/cast.xml')
            . '&track=&mode=&buttoncolor=&color1=&progressbarcolor=&highlightcolor=&font=&scrollcolor=&textcolor=%20&autoplay=1');
        maw_assert_same($defaults, $empty, 'empty and blank parameters do not defeat the defaults');

        maw_assert_same($defaults, maw_pps_player_style('url=' . rawurlencode('https://feeds.example.com/cast.xml') . '&mode[]=light&font[]=x'), 'a non-scalar parameter falls back too');

        $dark = maw_pps_player_style('url=' . rawurlencode('https://feeds.example.com/cast.xml') . '&mode=DARK&color1=%23C7C7C7&font=%3Cb%3E%3C%2Fb%3E');
        maw_assert_same('#000', $dark['mode'], 'dark mode is case-insensitive');
        maw_assert_same('#c7c7c7', $dark['color1'], 'a hex with "#" is accepted');
        maw_assert_same('Poppins', $dark['font'], 'a font that sanitizes to nothing falls back');
    },

    'every front-end page exposes the saved styling for click action links' => static function (): void {
        maw_pps_setup(maw_pps_fields(['podcast_player_background_color' => 'light', 'podcast_player_font' => 'Open Sans']));

        (new Assets())->enqueue();
        $exposed = MawTestScripts::$localized['maw-media-api-widget']['mawPodcastPlayerStyle'] ?? null;

        maw_assert_same(PlayerStyle::savedDefaults(), $exposed, 'mawPodcastPlayerStyle is the saved styling, with no shortcode or widget on the page');
        maw_assert_same('light', $exposed['podcastplayermode'] ?? null, 'and reflects the current fields');
        maw_assert(isset(MawTestScripts::$localized['maw-media-api-widget']['mawGridSearchData']), 'the existing grid search data is still localized');

        MawTestScripts::reset();
        MawTestPosts::$isAdmin = true;
        (new Assets())->enqueue();
        maw_assert_same([], MawTestScripts::$localized, 'nothing is exposed in wp-admin');
    },

    'the shortcode attribute contract and widget overrides are unchanged' => static function (): void {
        maw_pps_setup(maw_pps_fields(['brand_accent' => '#123456']));

        $bare = maw_pps_src_query((new Shortcode())->renderPodcastPlayerShortcode([
            'playlist_name' => 'cast', 'podcastplayermode' => 'light', 'podcastplayerfont' => 'Lato', 'podcastplayercolor' => '{{brand_accent}}',
        ]));
        maw_assert_same('151515', $bare['mode'] ?? null, 'the bare shortcode still ignores a mode attribute');
        maw_assert_same('Roboto', $bare['font'] ?? null, 'and a font attribute');
        maw_assert_same('c7c7c7', $bare['color1'] ?? null, 'and a referenced color attribute');
        maw_assert_same('1', $bare['track'] ?? null, 'the starting track default is unchanged');
        maw_assert(!isset($bare['adddatetotitle']), 'no date parameter by default');

        $widget = maw_pps_src_query(WidgetRenderer::render([
            'media_platform' => 'podcast', 'podcast_playlist' => 'cast', 'output_mode' => 'podcast_player',
            'player_mode' => 'value', 'player_mode_value' => 'light',
            'player_color' => 'value', 'player_color_value' => '{{brand_accent}}',
            'player_font' => 'value', 'player_font_value' => 'Open Sans',
            'player_showdate' => 'true',
        ], 'abc1234', false));
        maw_assert_same('light', $widget['mode'] ?? null, 'a widget mode override still applies');
        maw_assert_same('123456', $widget['color1'] ?? null, 'a widget override may reference a field');
        maw_assert_same('Open Sans', $widget['font'] ?? null, 'a widget font override keeps its space');
        maw_assert_same('ffffff', $widget['textcolor'] ?? null, 'the rest still comes from the podcast_player_* fields');
        maw_assert_same('true', $widget['adddatetotitle'] ?? null, 'the widget date option still applies');
        maw_assert(!has_filter('shortcode_atts_media-api-podcast-player'), 'the passthrough filter is removed after the widget renders');
    },
];
