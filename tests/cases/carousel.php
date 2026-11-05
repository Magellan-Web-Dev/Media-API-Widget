<?php
/**
 * Tests the media-card carousel layout.
 *
 * The carousel must be a layout over the existing collection paths, never a
 * second renderer: its cards are compared against the cards the grid renders
 * for the same attributes, across the static, initial searchable, and AJAX
 * paths. The remaining cases pin what is new — defaults, validation, stored
 * fields, the stored AJAX configuration and its cache identity, nostyling,
 * and Elementor equivalence — and that nothing changes when the carousel is
 * not selected.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/wp-shortcodes.php';

use MediaApiWidget\Config\Options;
use MediaApiWidget\Frontend\Shortcode;
use MediaApiWidget\Integrations\Elementor\SettingsAdapter;
use MediaApiWidget\Integrations\Elementor\ShortcodeText;
use MediaApiWidget\Integrations\Elementor\WidgetRenderer;
use MediaApiWidget\Integrations\Elementor\WidgetSchema;
use MediaApiWidget\Support\InlineSvg;

/**
 * Builds stored YouTube items, newest first.
 *
 * @param int $count Number of items.
 * @return array<int,array<string,mixed>> Items.
 */
function maw_car_items(int $count): array
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
 * Registers the shortcodes and seeds a YouTube playlist and a podcast.
 *
 * @param int $youtubeCount Number of YouTube items.
 * @return void
 */
function maw_car_setup(int $youtubeCount = 8): void
{
    MawTestShortcodes::reset();
    (new Shortcode())->register();

    Options::setMediaItems([
        ['type' => 'youtube', 'playlist_name' => 'show', 'media_data' => 'PL1', 'sort_mode' => 'number_in_title'],
        ['type' => 'podcast', 'playlist_name' => 'cast', 'podcast_platform' => 'custom', 'media_data' => 'https://feeds.example.com/cast.xml'],
    ]);

    $episodes = [];
    for ($n = 1; $n <= 4; $n++) {
        $episodes[] = ['title' => 'Cast Episode ' . $n, 'description' => 'Cast description ' . $n, 'guid' => 'cast-' . $n, 'pubDate' => 'Mon, 05 Jan 2026 10:00:00 +0000'];
    }

    set_transient('youtube_show', maw_car_items($youtubeCount), 7200);
    set_transient('podcast_cast', (string) json_encode(['channel' => ['title' => 'The Cast', 'rssUrl' => 'https://feeds.example.com/cast.xml', 'item' => $episodes]]), 7200);
}

/**
 * Renders [media-api-widget-render] for the YouTube playlist unless overridden.
 *
 * @param array<string,string> $atts Attributes.
 * @return string HTML.
 */
function maw_car_render(array $atts): string
{
    return (new Shortcode())->renderMediaShortcode($atts + ['playlist_name' => 'show', 'media_platform' => 'youtube']);
}

/**
 * Returns the cards inside grid markup, concatenated.
 *
 * @param string $html Grid HTML (one grid container).
 * @return string Card HTML.
 */
function maw_car_grid_cards(string $html): string
{
    maw_assert(str_starts_with($html, '<div class="maw-media-items-grid-layout'), 'grid markup starts with the grid container');

    return substr((string) preg_replace('/^<div[^>]*>/', '', $html), 0, -strlen('</div>'));
}

/**
 * Returns the cards inside carousel markup, one entry per slide.
 *
 * @param string $html Carousel HTML (one carousel).
 * @return array<int,string> Card HTML per slide.
 */
function maw_car_slides(string $html): array
{
    $pieces = preg_split('/<div class="maw-carousel-slide" data-maw-carousel-slide="" [^>]*>/', $html) ?: [];
    array_shift($pieces);

    $cards = [];
    $last  = count($pieces) - 1;
    foreach ($pieces as $i => $piece) {
        if ($i === $last) {
            $piece = (string) preg_replace('/<\/div><\/div><\/div><button[^>]*data-maw-carousel-arrow="right".*<\/button><\/div>$/s', '', $piece);
        } else {
            $piece = substr($piece, 0, -strlen('</div>'));
        }
        $cards[] = $piece;
    }

    return $cards;
}

/**
 * Extracts the first value of an HTML attribute.
 *
 * @param string $html      Markup.
 * @param string $attribute Attribute name.
 * @return string Value, or '' when absent.
 */
function maw_car_attr(string $html, string $attribute): string
{
    return preg_match('/\s' . preg_quote($attribute, '/') . '="([^"]*)"/', $html, $m) === 1 ? html_entity_decode($m[1]) : '';
}

/**
 * Returns the carousel wrapper's inline style.
 *
 * @param string $html Markup containing a carousel.
 * @return string Style attribute value.
 */
function maw_car_style(string $html): string
{
    return preg_match('/<div class="maw-carousel[^"]*" data-maw-carousel="" data-maw-carousel-step="[a-z]+" style="([^"]*)"/', $html, $m) === 1 ? html_entity_decode($m[1]) : '';
}

/**
 * Runs the grid search AJAX handler and returns its response data.
 *
 * @param string               $gridKey Grid key.
 * @param array<string,string> $post    Extra POST fields.
 * @return array<string,mixed> Response data (empty on error).
 */
function maw_car_ajax(string $gridKey, array $post = []): array
{
    $_POST = $post + [
        'nonce' => 'n', 'playlist_name' => 'show', 'media_platform' => 'youtube', 'grid_key' => $gridKey,
        'page' => '1', 'per_page' => '3', 'base_url' => 'https://example.test/page/',
    ];

    try {
        (new Shortcode())->handleGridSearchAjax();
    } catch (MawTestJsonResponse $response) {
        return $response->success ? (array) $response->data : [];
    }

    return [];
}

/**
 * Collection-mode flags as the Elementor adapter passes them.
 *
 * @param string $mode 'grid' or 'search_grid'.
 * @return array<string,string> Flags.
 */
function maw_car_flags(string $mode): array
{
    return [
        'mediatitle'             => 'false',
        'mediadescription'       => 'false',
        'multiplegrid'           => $mode === 'grid' ? 'true' : 'false',
        'multiplegridusersearch' => $mode === 'search_grid' ? 'true' : 'false',
    ];
}

/**
 * Writes an SVG (or other) file and registers it as a media-library attachment.
 *
 * @param string $name     File name, unique per fixture.
 * @param string $contents File contents.
 * @param string $mime     Attachment MIME type.
 * @return int Attachment ID.
 */
function maw_car_svg(string $name, string $contents, string $mime = 'image/svg+xml'): int
{
    $dir = sys_get_temp_dir() . '/maw-tests-svg';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . '/' . $name, $contents);

    return maw_test_add_attachment($dir . '/' . $name, $mime, 'https://example.test/wp-content/uploads/' . $name);
}

/**
 * Returns the markup inside one carousel arrow button.
 *
 * @param string $html Carousel HTML.
 * @param string $side 'left' or 'right'.
 * @return string Icon markup.
 */
function maw_car_arrow_icon(string $html, string $side): string
{
    return preg_match('/data-maw-carousel-arrow="' . $side . '"[^>]*>(.*?)<\/button>/s', $html, $m) === 1 ? $m[1] : '';
}

return [

    // -- Grid stays the default ---------------------------------------------

    'grid remains the default and unknown layouts fall back to it' => static function (): void {
        maw_car_setup();

        $base = maw_car_render(['multiplegrid' => 'true', 'multiplegridlimititems' => '3']);

        maw_assert(str_starts_with($base, '<div class="maw-media-items-grid-layout media_items_multiple_grid_layout" style="gap: 48px; grid-template-columns: repeat(auto-fill, minmax(min(100%, 400px), 1fr));"><!-- show youtube item'), 'the grid container markup is unchanged');
        maw_assert(!str_contains($base, 'data-maw-carousel'), 'no carousel markup is rendered by default');

        foreach (['grid', 'Grid', ' GRID ', 'slider', ''] as $value) {
            maw_assert_same($base, maw_car_render(['multiplegrid' => 'true', 'multiplegridlimititems' => '3', 'multiplegridlayout' => $value]), 'layout "' . $value . '" renders the grid');
        }

        maw_assert_same($base, maw_car_render([
            'multiplegrid' => 'true', 'multiplegridlimititems' => '3',
            'carouselstep' => 'group', 'carouselarrowcolor' => '#ff0000', 'carouselarrowleftimgurl' => 'https://example.test/l.svg',
        ]), 'carousel settings have no effect on a grid');
    },

    'single cards and text output ignore the layout' => static function (): void {
        maw_car_setup();

        foreach ([['orderdescending' => '2'], ['orderdescending' => '2', 'mediatitle' => 'true']] as $atts) {
            maw_assert_same(maw_car_render($atts), maw_car_render($atts + ['multiplegridlayout' => 'carousel']), 'a non-collection render is unchanged by the layout');
        }
    },

    // -- Static carousel ----------------------------------------------------

    'a carousel holds exactly the grid cards in the same order' => static function (): void {
        maw_car_setup();

        $cases = [
            'limit'          => ['multiplegridlimititems' => '5'],
            'title filter'   => ['multiplegridsearch' => 'Episode 1 '],
            'episode range'  => ['multiplegridepisoderange' => '2-6', 'multiplegridlimititems' => '1'],
            'show all'       => ['multiplegridshowall' => 'true', 'multiplegridlimititems' => '1'],
            'text below'     => ['multiplegridtext' => 'both'],
            'numbered title' => ['multiplegridtext' => 'numberedtitleanddescription', 'multiplegridlimititems' => '2'],
        ];

        foreach ($cases as $label => $atts) {
            $grid     = maw_car_render(['multiplegrid' => 'true'] + $atts);
            $carousel = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel'] + $atts);
            $slides   = maw_car_slides($carousel);

            maw_assert_same(maw_car_grid_cards($grid), implode('', $slides), $label . ': the carousel cards are the grid cards');
            maw_assert_same(substr_count($grid, 'data-itemclickable="true"'), count($slides), $label . ': one slide per card');
        }

        $carousel = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'multiplegridepisoderange' => '2-6']);
        maw_assert(str_contains($carousel, 'aria-label="1 of 5"') && str_contains($carousel, 'aria-label="5 of 5"'), 'slides are numbered');
        maw_assert_same(5, substr_count($carousel, 'data-maw-carousel-slide=""'), 'the episode range overrides the title filter and limit');
    },

    'carousel markup carries its defaults and structure' => static function (): void {
        maw_car_setup();

        $html = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'multiplegridlimititems' => '4']);

        maw_assert(str_starts_with($html, '<div class="maw-carousel media-carousel" data-maw-carousel="" data-maw-carousel-step="item"'), 'the wrapper carries the hook, styling class and step');
        maw_assert_same(
            '--maw-carousel-gap: 48px; --maw-carousel-min: 400px; --maw-carousel-arrow-size: 48px; --maw-carousel-arrow-icon-size: 24px; --maw-carousel-arrow-offset: 12px; --maw-carousel-arrow-color: #ffffff; --maw-carousel-arrow-background: rgba(0, 0, 0, 0.6); --maw-carousel-transition-duration: 0.25s;',
            maw_car_style($html),
            'every sizing, arrow and transition default is applied'
        );
        maw_assert(str_contains($html, '<button type="button" class="maw-carousel-arrow maw-carousel-arrow-left media-carousel-arrow" data-maw-carousel-arrow="left" aria-label="Previous item" hidden>'), 'the left arrow is a hidden, labelled button until the script needs it');
        maw_assert(str_contains($html, 'data-maw-carousel-arrow="right" aria-label="Next item" hidden>'), 'the right arrow is labelled');
        maw_assert_same(2, substr_count($html, 'stroke="currentColor"'), 'both arrows use the built-in icon');
        maw_assert(str_contains($html, '<div class="maw-carousel-viewport" data-maw-carousel-viewport="" role="group" aria-roledescription="carousel" aria-label="Media items" tabindex="0"><div class="maw-carousel-track" data-maw-carousel-track="">'), 'the viewport is a labelled, focusable group');
        maw_assert(str_contains($html, 'role="group" aria-roledescription="slide" aria-label="4 of 4"'), 'slides are labelled');
        maw_assert(!str_contains($html, 'maw-media-items-grid-layout'), 'no grid container is rendered');
    },

    'explicit sizes keep useful units and invalid values use safe defaults' => static function (): void {
        maw_car_setup();

        $style = static fn (array $atts): string => maw_car_style(maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'multiplegridlimititems' => '3'] + $atts));

        $s = $style(['multiplegridgap' => '1.5rem', 'multiplegridminsize' => '300px', 'carouselarrowsize' => '3em', 'carouselarrowiconsize' => '50%', 'carouselarrowoffset' => '0']);
        maw_assert(str_contains($s, '--maw-carousel-gap: 1.5rem;'), 'rem gap is kept');
        maw_assert(str_contains($s, '--maw-carousel-min: 300px;'), 'px minimum is kept');
        maw_assert(str_contains($s, '--maw-carousel-arrow-size: 3em;'), 'em size is kept');
        maw_assert(str_contains($s, '--maw-carousel-arrow-icon-size: 50%;'), 'percent icon size is kept');
        maw_assert(str_contains($s, '--maw-carousel-arrow-offset: 0px;'), 'a zero offset is usable');

        $s = $style(['multiplegridgap' => '20', 'multiplegridminsize' => 'calc(100% - 2rem)', 'carouselarrowsize' => 'clamp(2rem, 5vw, 4rem)']);
        maw_assert(str_contains($s, '--maw-carousel-gap: 20px;'), 'a bare number is read as pixels');
        maw_assert(str_contains($s, '--maw-carousel-min: calc(100% - 2rem);'), 'calc() is kept');
        maw_assert(str_contains($s, '--maw-carousel-arrow-size: clamp(2rem, 5vw, 4rem);'), 'clamp() is kept');

        $s = $style([
            'multiplegridgap' => '-10px', 'multiplegridminsize' => '0', 'carouselarrowsize' => 'big',
            'carouselarrowiconsize' => '10px; background: url(x)', 'carouselarrowoffset' => '', 'carouselstep' => 'page',
        ]);
        maw_assert(str_contains($s, '--maw-carousel-gap: 48px;'), 'a negative gap uses the default');
        maw_assert(str_contains($s, '--maw-carousel-min: 400px;'), 'a zero minimum uses the default');
        maw_assert(str_contains($s, '--maw-carousel-arrow-size: 48px;'), 'an invalid size uses the default');
        maw_assert(str_contains($s, '--maw-carousel-arrow-icon-size: 24px;'), 'a value that would inject CSS uses the default');
        maw_assert(str_contains($s, '--maw-carousel-arrow-offset: 12px;'), 'an empty offset uses the default');

        $colors = $style(['carouselarrowcolor' => 'ff0000', 'carouselarrowbackground' => 'rgba(10, 20, 30, .5)']);
        maw_assert(str_contains($colors, '--maw-carousel-arrow-color: #ff0000;'), 'hex without # is normalized');
        maw_assert(str_contains($colors, '--maw-carousel-arrow-background: rgba(10, 20, 30, .5);'), 'rgba() is kept');

        $colors = $style(['carouselarrowcolor' => 'var(--e-global-color-primary)', 'carouselarrowbackground' => 'Transparent']);
        maw_assert(str_contains($colors, '--maw-carousel-arrow-color: var(--e-global-color-primary);'), 'a CSS variable (e.g. an Elementor global color) is kept');
        maw_assert(str_contains($colors, '--maw-carousel-arrow-background: transparent;'), 'a color keyword is kept');

        $colors = $style(['carouselarrowcolor' => 'red;x:y', 'carouselarrowbackground' => 'url(x)']);
        maw_assert(str_contains($colors, '--maw-carousel-arrow-color: #ffffff;'), 'an invalid color uses the default');
        maw_assert(str_contains($colors, '--maw-carousel-arrow-background: rgba(0, 0, 0, 0.6);'), 'a non-color uses the default');
    },

    'the transition duration accepts times, CSS variables and math functions' => static function (): void {
        maw_car_setup();

        $duration = static function (string $value): string {
            $style = maw_car_style(maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'multiplegridlimititems' => '3', 'carouseltransitionduration' => $value]));
            return preg_match('/--maw-carousel-transition-duration: (.*);$/', $style, $m) === 1 ? $m[1] : '(missing)';
        };

        $kept = [
            '0.5s'                                    => 'seconds are kept',
            '400ms'                                   => 'milliseconds are kept',
            '.3s'                                     => 'a leading-dot number is kept',
            '0s'                                      => 'zero (an instant move) is usable',
            'var(--slide-speed)'                      => 'a CSS variable is kept',
            'var(--e-global_Speed-1)'                 => 'a variable name keeps its case, digits and underscores',
            'var(--slide-speed, 300ms)'               => 'a variable with a fallback is kept',
            'calc(var(--slide-speed) * 2)'            => 'calc() around a variable is kept',
            'calc(0.2s + 100ms)'                      => 'calc() arithmetic is kept',
            'clamp(150ms, var(--slide-speed), 1s)'    => 'clamp() is kept',
            'min(var(--a, 1s), max(200ms, var(--b)))' => 'nested functions are kept',
        ];
        foreach ($kept as $value => $label) {
            maw_assert_same($value, $duration($value), $label);
        }

        maw_assert_same('250ms', $duration('250'), 'a bare number is read as milliseconds');
        maw_assert_same('0ms', $duration('0'), 'a bare zero is an instant move');
        maw_assert_same('0.5s', $duration(' 0.5S '), 'units are lowercased and whitespace trimmed');

        $rejected = [
            ''                                   => 'an empty value',
            '-1s'                                => 'a negative time',
            'fast'                               => 'a keyword',
            '10px'                               => 'a length',
            '1s; background: red'                => 'a declaration break-out',
            'var(--x); color: red'               => 'a break-out after a variable',
            'var(--x, url(https://evil.test))'   => 'a url() fallback',
            'calc(1s + expression(alert(1)))'    => 'an unknown function',
            'var(--x))('                         => 'unbalanced parentheses',
            'calc((1s)'                          => 'an unclosed parenthesis',
            'attr(data-speed)'                   => 'a function outside the allowlist',
            str_repeat('1', 121)                 => 'an over-long value',
        ];
        foreach ($rejected as $value => $label) {
            maw_assert_same('0.25s', $duration((string) $value), $label . ' uses the default');
        }

        $html = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'carouseltransitionduration' => 'var(--a, 1s)']);
        maw_assert(str_contains($html, 'style="--maw-carousel-gap: 48px;') && str_contains($html, '--maw-carousel-transition-duration: var(--a, 1s);"'), 'the duration is the last inline variable, attribute-escaped');
    },

    'arrow step and labels follow carouselstep' => static function (): void {
        maw_car_setup();

        foreach (['group' => 'group', 'GROUP' => 'group', 'item' => 'item', 'page' => 'item', '' => 'item'] as $value => $expected) {
            $html = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'carouselstep' => $value]);
            maw_assert_same($expected, maw_car_attr($html, 'data-maw-carousel-step'), 'carouselstep "' . $value . '" moves by ' . $expected);
        }

        $html = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'carouselstep' => 'group']);
        maw_assert(str_contains($html, 'aria-label="Previous items"') && str_contains($html, 'aria-label="Next items"'), 'group navigation is labelled in the plural');
    },

    'custom arrow images replace only their own direction' => static function (): void {
        maw_car_setup();

        $html = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'carouselarrowleftimgurl' => 'https://example.test/left.svg?a=1&b=2']);
        maw_assert(str_contains($html, '<img class="maw-carousel-arrow-icon" src="https://example.test/left.svg?a=1&#038;b=2" alt="" draggable="false">'), 'the left image is escaped and decorative');
        maw_assert_same(1, substr_count($html, 'stroke="currentColor"'), 'the right arrow keeps the built-in icon');
        maw_assert(preg_match('/data-maw-carousel-arrow="right"[^>]*><svg/', $html) === 1, 'the built-in icon is on the right arrow');

        $html = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'carouselarrowleftimgurl' => 'https://example.test/l.png', 'carouselarrowrightimgurl' => 'https://example.test/r.png']);
        maw_assert(preg_match('/data-maw-carousel-arrow="left"[^>]*><img class="maw-carousel-arrow-icon" src="https:\/\/example.test\/l.png"/', $html) === 1, 'the left image is on the left arrow');
        maw_assert(preg_match('/data-maw-carousel-arrow="right"[^>]*><img class="maw-carousel-arrow-icon" src="https:\/\/example.test\/r.png"/', $html) === 1, 'the right image is on the right arrow');
        maw_assert(!str_contains($html, '<svg class="maw-carousel-arrow-icon"'), 'no built-in icon remains');
    },

    'zero, one and many items' => static function (): void {
        maw_car_setup();

        $none = ['multiplegrid' => 'true', 'multiplegridsearch' => 'no such title'];
        maw_assert_same(maw_car_render($none), maw_car_render($none + ['multiplegridlayout' => 'carousel']), 'zero matches show the grid message');
        maw_assert(str_contains(maw_car_render($none + ['multiplegridlayout' => 'carousel']), 'No youtube items found in playlist'), 'the message is the existing one, without carousel controls');

        $one = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'multiplegridlimititems' => '1']);
        maw_assert_same(1, count(maw_car_slides($one)), 'a filtered single item is a one-slide carousel');
        maw_assert(str_contains($one, 'aria-label="1 of 1"'), 'the single slide is labelled');

        maw_car_setup(1);
        $grid     = maw_car_render(['multiplegrid' => 'true']);
        $carousel = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel']);
        maw_assert(str_contains($grid, 'No youtube item found in playlist') && !str_contains($grid, 'data-itemclickable'), 'a one-item playlist grid keeps its existing single-card path');
        maw_assert_same(1, count(maw_car_slides($carousel)), 'a one-item playlist still renders a carousel');
        maw_assert(str_contains($carousel, 'data-id="vid00001"'), 'the only item is the slide');

        set_transient('youtube_show', [], 7200);
        maw_assert_same(maw_car_render(['multiplegrid' => 'true']), maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel']), 'an empty playlist renders the same message in both layouts');
    },

    'nostyling drops the carousel appearance class but keeps structure and hooks' => static function (): void {
        maw_car_setup();

        $html = maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'nostyling' => 'true', 'multiplegridlimititems' => '2']);

        maw_assert(!str_contains($html, 'media-carousel'), 'no carousel styling class is emitted');
        maw_assert(!str_contains($html, 'class="maw-media-item media_item'), 'cards are unstyled exactly as in a nostyling grid');
        maw_assert(str_starts_with($html, '<div class="maw-carousel" data-maw-carousel=""'), 'the hook class and functional attributes remain');
        maw_assert(str_contains($html, 'class="maw-carousel-arrow maw-carousel-arrow-left" data-maw-carousel-arrow="left"'), 'arrow hooks remain');
        maw_assert(str_contains($html, '--maw-carousel-arrow-color: #ffffff;'), 'the arrow variables remain available to custom CSS');

        $grid = maw_car_render(['multiplegrid' => 'true', 'nostyling' => 'true', 'multiplegridlimititems' => '2']);
        maw_assert_same(maw_car_grid_cards($grid), implode('', maw_car_slides($html)), 'nostyling cards match the nostyling grid');
    },

    'stored fields resolve in carousel settings and act as defaults' => static function (): void {
        maw_car_setup();
        Options::setShortcodes([
            ['field' => 'brand', 'value' => '#123abc'],
            ['field' => 'arrow_left', 'value' => 'https://example.test/brand-left.svg'],
            ['field' => 'multiplegridlayout', 'value' => 'carousel'],
            ['field' => 'carouselstep', 'value' => 'group'],
        ]);

        $html = maw_car_render(['multiplegrid' => 'true', 'carouselarrowcolor' => '{{brand}}', 'carouselarrowleftimgurl' => '[media-api-widget field="arrow_left"]']);
        maw_assert(str_contains($html, 'data-maw-carousel=""'), 'a stored multiplegridlayout field is the default layout');
        maw_assert_same('group', maw_car_attr($html, 'data-maw-carousel-step'), 'a stored carouselstep field is the default step');
        maw_assert(str_contains(maw_car_style($html), '--maw-carousel-arrow-color: #123abc;'), 'a {{field}} reference resolves');
        maw_assert(str_contains($html, 'src="https://example.test/brand-left.svg"'), 'a [media-api-widget field] reference resolves');

        maw_assert(!str_contains(maw_car_render(['multiplegrid' => 'true', 'multiplegridlayout' => 'grid']), 'data-maw-carousel'), 'an explicit grid overrides the stored default');
    },

    'card behavior attributes are identical in both layouts' => static function (): void {
        maw_car_setup();

        $atts = [
            'multiplegrid' => 'true', 'multiplegridlimititems' => '3', 'showlightbox' => 'false', 'lightboxshowplaylist' => 'true',
            'playbuttoniconimgurl' => 'https://example.test/play.png', 'logo' => 'https://example.test/logo.png', 'lightboxfont' => 'Lato',
            'lightboxthemecolor' => '#abcdef', 'fontfamily' => 'Georgia', 'showtextoverlay' => 'false', 'instructionmessage' => 'Watch',
        ];
        $grid     = maw_car_render($atts);
        $carousel = maw_car_render($atts + ['multiplegridlayout' => 'carousel']);

        maw_assert_same(maw_car_grid_cards($grid), implode('', maw_car_slides($carousel)), 'YouTube cards keep every attribute');
        maw_assert_same(3, substr_count($carousel, 'data-showlightbox="false"'), 'showlightbox="false" reaches every card');
        maw_assert_same(3, substr_count($carousel, 'data-lightboxshowplaylist="true"'), 'the playlist option reaches every card');

        $podcast = [
            'playlist_name' => 'cast', 'media_platform' => 'podcast', 'multiplegrid' => 'true', 'thumbnail' => 'https://example.test/cover.jpg',
            'showplaybar' => 'true', 'playbarcolor' => '#00ff00', 'podcastplayercolor' => '#123456', 'orderdescending' => '2', 'multiplegridtext' => 'title',
        ];
        $grid     = maw_car_render($podcast);
        $carousel = maw_car_render($podcast + ['multiplegridlayout' => 'carousel']);

        maw_assert_same(maw_car_grid_cards($grid), implode('', maw_car_slides($carousel)), 'podcast cards keep every attribute');
        maw_assert_same(4, count(maw_car_slides($carousel)), 'every episode is a slide');
        maw_assert(str_contains($carousel, 'data-podcastplayercolor="123456"') && str_contains($carousel, 'data-trackselect="2"'), 'player settings reach the cards');
        maw_assert(str_contains($carousel, 'src="https://example.test/cover.jpg"'), 'the custom thumbnail is used');
        maw_assert(!str_contains($carousel, 'media-item-multiple-grid-text'), 'text below cards stays YouTube-only');
    },

    // -- Searchable carousel ------------------------------------------------

    'a searchable carousel renders page one, keeps pagination, and stores its configuration' => static function (): void {
        maw_car_setup();

        $atts = ['multiplegridusersearch' => 'true', 'multiplegridperpage' => '3', 'multiplegridlayout' => 'carousel', 'carouselstep' => 'group', 'carouselarrowcolor' => '#00ff00', 'multiplegridminsize' => '18rem'];
        $html = maw_car_render($atts);

        maw_assert(str_contains($html, '<div class="maw-grid-items"><div class="maw-carousel media-carousel" data-maw-carousel=""'), 'the carousel replaces the grid inside the search wrapper');
        maw_assert_same(['vid00008', 'vid00007', 'vid00006'], preg_match_all('/data-id="([^"]+)"/', $html, $m) ? $m[1] : [], 'page one holds the first three items');
        maw_assert(str_contains($html, 'href="https://example.test/page/?maw_page_show_youtube=2"'), 'server pagination links are unchanged');
        maw_assert_same('show_youtube', maw_car_attr($html, 'data-maw-grid-id'), 'the shortcode keeps the shared grid id');

        $key    = maw_car_attr($html, 'data-maw-grid-key');
        $config = get_transient('maw_grid_' . $key);
        maw_assert_same(
            ['settings', 'gap', 'minsize', 'multiplegridtext', 'episodeRange', 'showall', 'noresults', 'noStyling', 'maxPages', 'maxDisplay', 'carousel'],
            array_keys((array) $config),
            'the stored configuration adds only the carousel entry'
        );
        maw_assert_same([
            'layout' => 'carousel', 'gap' => '48px', 'minsize' => '18rem', 'step' => 'group', 'arrowleft' => '', 'arrowright' => '', 'arrowleftsvg' => '', 'arrowrightsvg' => '',
            'arrowsize' => '48px', 'arrowiconsize' => '24px', 'arrowcolor' => '#00ff00', 'arrowbackground' => 'rgba(0, 0, 0, 0.6)', 'arrowoffset' => '12px', 'transition' => '0.25s',
        ], $config['carousel'] ?? null, 'the stored carousel configuration is the validated one');

        maw_assert_same($key, maw_car_attr(maw_car_render($atts), 'data-maw-grid-key'), 'the key is stable for the same settings');

        $grid = maw_car_render(['multiplegridlayout' => 'grid'] + $atts);
        maw_assert(maw_car_attr($grid, 'data-maw-grid-key') !== $key, 'a grid with the same settings has its own key');
        maw_assert(!isset(get_transient('maw_grid_' . maw_car_attr($grid, 'data-maw-grid-key'))['carousel']), 'the grid stores no carousel entry');

        foreach (['carouselarrowcolor' => '#0000ff', 'carouselstep' => 'item', 'carouselarrowrightimgurl' => 'https://example.test/r.svg', 'carouselarrowoffset' => '4px', 'carouseltransitionduration' => '0.5s'] as $attr => $value) {
            maw_assert(maw_car_attr(maw_car_render([$attr => $value] + $atts), 'data-maw-grid-key') !== $key, 'changing ' . $attr . ' changes the key');
        }
    },

    'AJAX search and pagination return carousel markup with the stored settings' => static function (): void {
        maw_car_setup();

        $atts     = ['multiplegridusersearch' => 'true', 'multiplegridperpage' => '3', 'multiplegridtext' => 'title', 'noresults' => 'Nothing here'];
        $carousel = maw_car_render($atts + ['multiplegridlayout' => 'carousel', 'carouselstep' => 'group', 'carouselarrowcolor' => '#00ff00', 'carouselarrowleftimgurl' => 'https://example.test/l.svg']);
        $grid     = maw_car_render($atts);

        $page2 = maw_car_ajax(maw_car_attr($carousel, 'data-maw-grid-key'), ['page' => '2']);
        $html  = (string) ($page2['html'] ?? '');
        maw_assert(str_starts_with($html, '<div class="maw-carousel media-carousel" data-maw-carousel="" data-maw-carousel-step="group"'), 'page two is a carousel with the stored step');
        maw_assert(str_contains(maw_car_style($html), '--maw-carousel-arrow-color: #00ff00;'), 'page two keeps the stored arrow color');
        maw_assert(str_contains($html, 'src="https://example.test/l.svg"'), 'page two keeps the stored arrow image');
        maw_assert_same(['vid00005', 'vid00004', 'vid00003'], preg_match_all('/data-id="([^"]+)"/', $html, $m) ? $m[1] : [], 'page two holds the next three items');
        maw_assert(str_contains((string) ($page2['pagination'] ?? ''), 'maw_page_show_youtube=3'), 'pagination still loads other result pages');

        $gridPage2 = maw_car_ajax(maw_car_attr($grid, 'data-maw-grid-key'), ['page' => '2']);
        maw_assert_same(maw_car_grid_cards((string) $gridPage2['html']), implode('', maw_car_slides($html)), 'AJAX carousel cards are the AJAX grid cards');
        maw_assert_same($gridPage2['pagination'], $page2['pagination'], 'pagination markup is identical');

        $search = maw_car_ajax(maw_car_attr($carousel, 'data-maw-grid-key'), ['search_term' => 'Episode 7', 'search_by' => 'title']);
        maw_assert_same(1, count(maw_car_slides((string) $search['html'])), 'a search result page is a carousel too');

        $none = maw_car_ajax(maw_car_attr($carousel, 'data-maw-grid-key'), ['search_term' => 'zzz']);
        maw_assert_same('<p class="maw-grid-noresults">Nothing here</p>', $none['html'] ?? null, 'no results shows the message without carousel controls');

        $gridNone = maw_car_ajax(maw_car_attr($grid, 'data-maw-grid-key'), ['search_term' => 'zzz']);
        maw_assert(str_starts_with((string) $gridNone['html'], '<div class="maw-media-items-grid-layout media_items_multiple_grid_layout" style="gap: 48px;') && str_ends_with((string) $gridNone['html'], '<p class="maw-grid-noresults">Nothing here</p></div>'), 'the grid keeps its message inside the grid container');
    },

    'stored carousel configuration is validated again when read' => static function (): void {
        maw_car_setup();

        $html = maw_car_render(['multiplegridusersearch' => 'true', 'multiplegridperpage' => '3', 'multiplegridlayout' => 'carousel']);
        $key  = maw_car_attr($html, 'data-maw-grid-key');

        $config = get_transient('maw_grid_' . $key);
        $config['carousel'] = ['gap' => '1px;}body{display:none', 'minsize' => 'x', 'step' => 'group', 'arrowcolor' => 'expression(alert(1))', 'transition' => '1s;}body{display:none'];
        set_transient('maw_grid_' . $key, $config, 86400);

        $style = maw_car_style((string) (maw_car_ajax($key)['html'] ?? ''));
        maw_assert(str_contains($style, '--maw-carousel-gap: 48px;') && str_contains($style, '--maw-carousel-min: 400px;'), 'invalid stored lengths fall back to defaults');
        maw_assert(str_contains($style, '--maw-carousel-arrow-color: #ffffff;'), 'an invalid stored color falls back to the default');
        maw_assert(str_contains($style, '--maw-carousel-transition-duration: 0.25s;'), 'an invalid stored duration falls back to the default');

        // Configurations stored before the duration setting existed have no
        // 'transition' entry and render with the default.
        $config['carousel'] = ['step' => 'group', 'arrowcolor' => '#00ff00'];
        set_transient('maw_grid_' . $key, $config, 86400);
        $style = maw_car_style((string) (maw_car_ajax($key)['html'] ?? ''));
        maw_assert(str_contains($style, '--maw-carousel-transition-duration: 0.25s;') && str_contains($style, '--maw-carousel-arrow-color: #00ff00;'), 'a configuration stored without a duration uses the default');

        $config['carousel'] = ['transition' => 'var(--slide-speed, 600ms)'];
        set_transient('maw_grid_' . $key, $config, 86400);
        maw_assert(str_contains(maw_car_style((string) (maw_car_ajax($key)['html'] ?? '')), '--maw-carousel-transition-duration: var(--slide-speed, 600ms);'), 'a stored CSS-variable duration is kept on AJAX pages');

        unset($config['carousel']);
        set_transient('maw_grid_' . $key, $config, 86400);
        maw_assert(str_starts_with((string) (maw_car_ajax($key)['html'] ?? ''), '<div class="maw-media-items-grid-layout'), 'a configuration without a carousel entry renders the grid');
    },

    // -- Elementor ----------------------------------------------------------

    'carousel widgets render exactly what the carousel shortcode renders' => static function (): void {
        maw_car_setup();

        $classic = [
            'media_platform' => 'youtube', 'youtube_playlist' => 'show', 'output_mode' => 'grid',
            'multiplegridlayout' => 'carousel', 'carouselstep' => 'group',
            'multiplegridminsize' => 'value', 'multiplegridminsize_value' => '300px',
            'multiplegridgap' => 'value', 'multiplegridgap_value' => '24px',
            'multiplegridlimititems' => 'value', 'multiplegridlimititems_value' => '5',
            'carouselarrowleftimgurl' => 'value', 'carouselarrowleftimgurl_value' => ['url' => 'https://example.test/l.svg', 'id' => 9],
            'carouselarrowcolor' => 'value', 'carouselarrowcolor_value' => '#FF0000',
            'carouselarrowsize' => 'value', 'carouselarrowsize_value' => '3rem',
            'carouseltransitionduration' => 'value', 'carouseltransitionduration_value' => 'var(--slide-speed, 400ms)',
        ];
        $atomic = array_merge($classic, ['carouselarrowleftimgurl_value' => 'https://example.test/l.svg', 'multiplegridlimititems_value' => 5, 'carouselarrowoffset' => null, 'carouselarrowoffset_value' => null]);

        $shortcode = maw_car_render([
            'multiplegridlayout' => 'carousel', 'carouselstep' => 'group', 'multiplegridminsize' => '300px', 'multiplegridgap' => '24px',
            'multiplegridlimititems' => '5', 'carouselarrowleftimgurl' => 'https://example.test/l.svg', 'carouselarrowcolor' => '#FF0000', 'carouselarrowsize' => '3rem',
            'carouseltransitionduration' => 'var(--slide-speed, 400ms)',
        ] + maw_car_flags('grid'));

        maw_assert(str_contains($shortcode, 'data-maw-carousel=""'), 'the shortcode renders a carousel');
        maw_assert(str_contains(maw_car_style($shortcode), '--maw-carousel-transition-duration: var(--slide-speed, 400ms);'), 'the widget duration reaches the carousel');
        maw_assert_same($shortcode, WidgetRenderer::render($classic, 'abc1234', false), 'the classic widget is byte-identical to the shortcode');
        maw_assert_same($shortcode, WidgetRenderer::render($atomic, 'abc1234', false), 'the atomic widget is byte-identical to the shortcode');
    },

    'searchable carousel widgets match the shortcode and keep isolated ids' => static function (): void {
        maw_car_setup();

        $settings = [
            'media_platform' => 'youtube', 'youtube_playlist' => 'show', 'output_mode' => 'search_grid', 'grid_link' => 'legacy',
            'multiplegridlayout' => 'carousel', 'multiplegridperpage' => 'value', 'multiplegridperpage_value' => '3', 'carouselarrowbackground' => 'value', 'carouselarrowbackground_value' => 'rgba(0,0,0,0.9)',
        ];

        $expected = (new Shortcode())->renderGridSearchShortcode(['playlist_name' => 'show', 'media_platform' => 'youtube'])
            . maw_car_render(['multiplegridlayout' => 'carousel', 'multiplegridperpage' => '3', 'carouselarrowbackground' => 'rgba(0,0,0,0.9)'] + maw_car_flags('search_grid'));
        maw_assert_same($expected, WidgetRenderer::render($settings, 'abc1234', false), 'a shared-link searchable carousel widget matches the shortcodes');

        $first  = WidgetRenderer::render(['grid_link' => 'isolated'] + $settings, 'aaa1111', false);
        $second = WidgetRenderer::render(['grid_link' => 'isolated'] + $settings, 'bbb2222', false);
        maw_assert_same('show_youtube--waaa1111', maw_car_attr($first, 'data-maw-grid-id'), 'an isolated searchable carousel has its own id');
        maw_assert(maw_car_attr($first, 'data-maw-grid-key') !== maw_car_attr($second, 'data-maw-grid-key'), 'two isolated carousels never share stored settings');

        $config = get_transient('maw_grid_' . maw_car_attr($second, 'data-maw-grid-key'));
        maw_assert_same('show_youtube--wbbb2222', $config['gridId'] ?? null, 'the instance id is stored');
        maw_assert_same('rgba(0,0,0,0.9)', $config['carousel']['arrowbackground'] ?? null, 'the widget carousel settings are stored');

        $page2 = maw_car_ajax(maw_car_attr($second, 'data-maw-grid-key'), ['page' => '2']);
        maw_assert(str_contains((string) $page2['html'], 'data-maw-carousel=""'), 'AJAX keeps the widget carousel');
        maw_assert(str_contains((string) $page2['pagination'], 'maw_page_show_youtube--wbbb2222=3'), 'AJAX keeps the widget page parameter');
    },

    'hidden carousel controls never reach the renderer' => static function (): void {
        maw_car_setup();

        $carouselSettings = [
            'media_platform' => 'youtube', 'youtube_playlist' => 'show',
            'multiplegridlayout' => 'carousel', 'carouselstep' => 'group', 'carouselarrowcolor' => 'value', 'carouselarrowcolor_value' => '#ff0000',
            'carouselarrowrightimgurl' => 'value', 'carouselarrowrightimgurl_value' => 'https://example.test/r.svg',
            'carouseltransitionduration' => 'value', 'carouseltransitionduration_value' => '0.6s',
        ];
        $atts = static function (array $settings): array {
            $calls = SettingsAdapter::plan($settings, 'abc1234')['calls'];
            return end($calls)['atts'] ?? [];
        };

        $item = $atts(['output_mode' => 'item'] + $carouselSettings);
        foreach (['multiplegridlayout', 'carouselstep', 'carouselarrowcolor', 'carouselarrowrightimgurl'] as $attr) {
            maw_assert(!array_key_exists($attr, $item), $attr . ' is ignored for a media card');
        }

        foreach (['inherit', 'grid'] as $layout) {
            $grid = $atts(['output_mode' => 'grid', 'multiplegridlayout' => $layout] + $carouselSettings);
            foreach (['carouselstep', 'carouselarrowcolor', 'carouselarrowrightimgurl', 'carouseltransitionduration'] as $attr) {
                maw_assert(!array_key_exists($attr, $grid), $attr . ' is ignored while the layout is ' . $layout);
            }
        }
        maw_assert(!array_key_exists('multiplegridlayout', $atts(['output_mode' => 'grid', 'multiplegridlayout' => 'inherit'] + $carouselSettings)), 'a default layout is omitted so the shortcode default applies');

        $field = $atts(['output_mode' => 'search_grid', 'multiplegridlayout' => 'field:site_layout'] + $carouselSettings);
        maw_assert_same('{{site_layout}}', $field['multiplegridlayout'] ?? null, 'a stored-field layout is passed as a reference');
        maw_assert_same('#ff0000', $field['carouselarrowcolor'] ?? null, 'carousel controls apply when the layout comes from a stored field');

        $carousel = $atts(['output_mode' => 'grid'] + $carouselSettings);
        maw_assert_same(
            ['carousel', 'group', 'https://example.test/r.svg', '#ff0000'],
            [$carousel['multiplegridlayout'] ?? null, $carousel['carouselstep'] ?? null, $carousel['carouselarrowrightimgurl'] ?? null, $carousel['carouselarrowcolor'] ?? null],
            'visible carousel controls are passed'
        );
    },

    'the schema exposes every carousel attribute in both collection modes' => static function (): void {
        maw_car_setup();

        $specs = WidgetSchema::attributeSpecs();
        $attrs = array_column($specs, 'attr');
        foreach (array_merge(['multiplegridlayout', 'carouselarrowleftimgurl', 'carouselarrowrightimgurl'], array_keys(Shortcode::CAROUSEL_DEFAULTS)) as $attr) {
            maw_assert(in_array($attr, $attrs, true), $attr . ' has a widget setting');
        }

        foreach (['grid', 'search_grid'] as $mode) {
            $settings = SettingsAdapter::withDefaults(['output_mode' => $mode, 'multiplegridlayout' => 'carousel']);
            foreach ($specs as $key => $spec) {
                if (in_array($spec['attr'], $attrs, true) && ($spec['section'] === 'carousel' || $key === 'multiplegridlayout')) {
                    maw_assert(WidgetSchema::isVisible($spec['when'], $settings), $key . ' is visible for ' . $mode);
                }
            }
        }

        maw_assert_same('image', $specs['carouselarrowleftimgurl']['kind'], 'arrow images use the media control (classic) / URL control (atomic)');
        maw_assert_same(Shortcode::CAROUSEL_DEFAULTS['carouselarrowsize'], $specs['carouselarrowsize']['placeholder'], 'the size placeholder shows the default');
        maw_assert_same(Shortcode::CAROUSEL_DEFAULTS['carouselarrowoffset'], $specs['carouselarrowoffset']['placeholder'], 'the offset placeholder shows the default');
        maw_assert_same(Shortcode::CAROUSEL_DEFAULTS['carouseltransitionduration'], $specs['carouseltransitionduration']['placeholder'], 'the duration placeholder shows the default');
        maw_assert_same('Default (0.25s)', WidgetSchema::sourceOptions($specs['carouseltransitionduration'])['inherit'], 'the duration source names its default');
        maw_assert_same('text', $specs['carouseltransitionduration']['kind'], 'the duration is free text, so CSS variables can be entered');
        maw_assert_same(false, $specs['multiplegridlayout']['empty'], 'the layout offers no empty value');
    },

    'plain content carries the carousel attributes' => static function (): void {
        maw_car_setup();

        $text = ShortcodeText::forSettings([
            'media_platform' => 'youtube', 'youtube_playlist' => 'show', 'output_mode' => 'grid',
            'multiplegridlayout' => 'carousel', 'carouselstep' => 'group', 'carouselarrowleftimgurl' => 'value', 'carouselarrowleftimgurl_value' => ['url' => 'https://example.test/l.svg'],
        ], 'x');

        maw_assert_same(
            '[media-api-widget-render playlist_name="show" media_platform="youtube" multiplegridlayout="carousel" carouselstep="group" carouselarrowleftimgurl="https://example.test/l.svg" mediatitle="false" mediadescription="false" multiplegrid="true" multiplegridusersearch="false"]',
            $text,
            'the saved shortcode renders the same carousel without Elementor'
        );
    },
    // -- Arrow SVGs ---------------------------------------------------------

    'arrow SVGs are rebuilt from an allowlist before they are inlined' => static function (): void {
        $clean = InlineSvg::sanitize('<?xml version="1.0"?><!-- made by hand --><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><title>Next &amp; more</title><path d="M9 5l7 7-7 7"/></svg>', 'icon');
        maw_assert_same(
            '<svg class="icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="currentColor"><title>Next &amp; more</title><path d="M9 5l7 7-7 7"></path></svg>',
            $clean,
            'a plain icon keeps its drawing, is decorative, and takes the text color'
        );

        $stroke = InlineSvg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>');
        maw_assert(str_contains($stroke, 'fill="none" stroke="currentColor" stroke-width="2"') && substr_count($stroke, 'fill=') === 1, 'a stroke icon keeps fill="none" (it is not filled in)');

        $colored = InlineSvg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" fill="#ff0000"><rect width="10" height="10"/></svg>');
        maw_assert(str_contains($colored, 'fill="#ff0000"') && !str_contains($colored, 'currentColor'), 'an SVG that sets its own fill keeps it');

        $sized = InlineSvg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" width="32px" height="16"><rect width="32" height="16"/></svg>');
        maw_assert(str_contains($sized, 'viewBox="0 0 32 16"'), 'a viewBox is derived from width and height so the icon scales');

        $gradient = InlineSvg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><defs><linearGradient id="g" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#000"/></linearGradient></defs><rect fill="url(#g)" width="2" height="2"/></svg>');
        maw_assert(str_contains($gradient, '<linearGradient id="g" gradientUnits="userSpaceOnUse">') && str_contains($gradient, 'fill="url(#g)"'), 'gradients and same-document url(#id) references are kept, in canonical case');

        $hostile = InlineSvg::sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 24 24" onload="alert(1)">'
            . '<script>alert(2)</script><style>body{display:none}</style>'
            . '<a href="javascript:alert(3)"><path d="M0 0"/></a>'
            . '<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>'
            . '<use xlink:href="https://evil.test/x.svg#a"/><image href="https://evil.test/t.png"/>'
            . '<rect fill="url(https://evil.test/p.svg#x)" stroke="javascript:alert(4)" width="1" height="1" onclick="alert(5)"/>'
            . '<circle r="1" style="fill:red" class="x" xml:space="preserve"/>'
            . '<svg><path d="M1 1"/></svg><?php echo 1; ?><![CDATA[<script>alert(6)</script>]]>'
            . '</svg>'
        );
        foreach (['script', 'alert', 'style', 'onload', 'onclick', 'href', 'evil', 'foreignObject', 'xhtml', '<a', '<use', '<image', 'class="x"', 'xml:space', '<?', 'CDATA', 'javascript'] as $needle) {
            maw_assert(!str_contains($hostile, $needle), 'hostile content is removed: ' . $needle);
        }
        maw_assert(str_contains($hostile, '<rect width="1" height="1"></rect>') && str_contains($hostile, '<circle r="1"></circle>'), 'safe shapes beside hostile content survive without their unsafe attributes');
        maw_assert_same(1, substr_count($hostile, '<svg'), 'a nested svg is dropped');

        $rejected = [
            'entity declarations' => '<!DOCTYPE svg [<!ENTITY a "aaaa">]><svg xmlns="http://www.w3.org/2000/svg">&a;</svg>',
            'external entities'   => '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>',
            'malformed XML'       => '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"></svg>',
            'a non-SVG root'      => '<html><body><svg/></body></html>',
            'an XHTML root'       => '<svg xmlns="http://www.w3.org/1999/xhtml"/>',
            'an empty file'       => '',
            'an oversized file'   => '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat('<g></g>', 40000) . '</svg>',
        ];
        foreach ($rejected as $label => $source) {
            maw_assert_same('', InlineSvg::sanitize($source), $label . ' are rejected');
        }
    },

    'arrow SVGs from the media library render inline and take precedence over images' => static function (): void {
        maw_car_setup();
        $left  = maw_car_svg('left-a.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>');
        $right = maw_car_svg('right-a.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg>');
        $base  = ['multiplegrid' => 'true', 'multiplegridlayout' => 'carousel', 'multiplegridlimititems' => '3'];

        $html = maw_car_render($base + ['carouselarrowleftsvg' => (string) $left]);
        maw_assert_same(
            '<svg class="maw-carousel-arrow-icon maw-carousel-arrow-svg" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="currentColor"><path d="M15 5l-7 7 7 7"></path></svg>',
            maw_car_arrow_icon($html, 'left'),
            'an attachment ID renders the SVG code inline in the left arrow'
        );
        maw_assert(str_contains(maw_car_arrow_icon($html, 'right'), 'stroke="currentColor"'), 'the right arrow keeps the built-in icon');

        $byUrl = maw_car_render($base + ['carouselarrowrightsvg' => 'https://example.test/wp-content/uploads/right-a.svg']);
        maw_assert(str_contains(maw_car_arrow_icon($byUrl, 'right'), '<path d="M9 5l7 7-7 7"></path>'), 'a media-library URL resolves to the attachment');

        $both = maw_car_render($base + ['carouselarrowleftsvg' => (string) $left, 'carouselarrowleftimgurl' => 'https://example.test/l.png']);
        maw_assert(str_contains(maw_car_arrow_icon($both, 'left'), 'maw-carousel-arrow-svg') && !str_contains($both, 'l.png'), 'an SVG takes precedence over an arrow image');

        $png      = maw_car_svg('arrow.png', 'not an svg', 'image/png');
        $missing  = maw_car_svg('gone.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        @unlink(sys_get_temp_dir() . '/maw-tests-svg/gone.svg');
        $script   = maw_car_svg('only-script.svg', '<script>alert(1)</script>');
        $fallback = [
            'a non-SVG attachment'      => (string) $png,
            'a deleted file'            => (string) $missing,
            'an unusable file'          => (string) $script,
            'an unknown ID'             => '999999',
            'a remote URL'              => 'https://evil.test/a.svg',
            'a relative path'           => '../../wp-config.php',
        ];
        foreach ($fallback as $label => $value) {
            $out = maw_car_render($base + ['carouselarrowleftsvg' => $value, 'carouselarrowleftimgurl' => 'https://example.test/l.png']);
            maw_assert(str_contains(maw_car_arrow_icon($out, 'left'), '<img class="maw-carousel-arrow-icon" src="https://example.test/l.png"'), $label . ' falls back to the arrow image');
        }

        $nostyling = maw_car_render($base + ['carouselarrowleftsvg' => (string) $left, 'nostyling' => 'true']);
        maw_assert(str_contains(maw_car_arrow_icon($nostyling, 'left'), 'maw-carousel-arrow-svg'), 'nostyling keeps the inline SVG');

        Options::setShortcodes([['field' => 'brand_arrow', 'value' => (string) $right]]);
        maw_assert(str_contains(maw_car_arrow_icon(maw_car_render($base + ['carouselarrowrightsvg' => '{{brand_arrow}}']), 'right'), 'maw-carousel-arrow-svg'), 'a {{field}} reference resolves to the SVG');
    },

    'searchable carousels store the arrow SVG and keep it on AJAX pages' => static function (): void {
        maw_car_setup();
        $left = maw_car_svg('left-b.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>');
        $atts = ['multiplegridusersearch' => 'true', 'multiplegridperpage' => '3', 'multiplegridlayout' => 'carousel'];

        $html = maw_car_render($atts + ['carouselarrowleftsvg' => 'https://example.test/wp-content/uploads/left-b.svg']);
        $key  = maw_car_attr($html, 'data-maw-grid-key');
        maw_assert_same((string) $left, get_transient('maw_grid_' . $key)['carousel']['arrowleftsvg'] ?? null, 'the stored configuration holds the attachment ID');
        maw_assert_same($key, maw_car_attr(maw_car_render($atts + ['carouselarrowleftsvg' => (string) $left]), 'data-maw-grid-key'), 'the URL and the ID of the same SVG share one configuration');
        maw_assert($key !== maw_car_attr(maw_car_render($atts), 'data-maw-grid-key'), 'adding an SVG changes the key');

        maw_assert(str_contains(maw_car_arrow_icon((string) maw_car_ajax($key, ['page' => '2'])['html'], 'left'), '<circle cx="4" cy="4" r="3"></circle>'), 'AJAX pages render the stored SVG');

        $config = get_transient('maw_grid_' . $key);
        $config['carousel']['arrowleftsvg'] = '<svg onload="alert(1)">';
        set_transient('maw_grid_' . $key, $config, 86400);
        maw_assert(str_contains(maw_car_arrow_icon((string) maw_car_ajax($key)['html'], 'left'), 'stroke="currentColor"'), 'a tampered stored value is validated again and falls back');
    },

    'the arrow SVG pickers render exactly what the shortcode renders' => static function (): void {
        maw_car_setup();
        $left = maw_car_svg('left-c.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>');
        $url  = 'https://example.test/wp-content/uploads/left-c.svg';
        $base = ['media_platform' => 'youtube', 'youtube_playlist' => 'show', 'output_mode' => 'grid', 'multiplegridlayout' => 'carousel', 'carouselarrowleftsvg' => 'value'];

        $expected = maw_car_render(['multiplegridlayout' => 'carousel', 'carouselarrowleftsvg' => (string) $left] + maw_car_flags('grid'));
        maw_assert(str_contains($expected, 'maw-carousel-arrow-svg'), 'the shortcode inlines the SVG');

        $values = [
            'the classic Upload SVG value'        => ['value' => ['url' => $url, 'id' => $left], 'library' => 'svg'],
            'the atomic value as stored'          => ['$$type' => 'svg-src', 'value' => ['id' => ['$$type' => 'image-attachment-id', 'value' => $left], 'url' => null]],
            'the atomic value as resolved'        => ['html' => '<svg></svg>', 'url' => $url],
            'a plain attachment ID'               => (string) $left,
        ];
        foreach ($values as $label => $value) {
            maw_assert_same($expected, WidgetRenderer::render($base + ['carouselarrowleftsvg_value' => $value], 'abc1234', false), $label . ' renders like the shortcode');
        }

        $plan = static function ($value): array {
            $calls = SettingsAdapter::plan(['carouselarrowleftsvg_value' => $value] + ['media_platform' => 'youtube', 'youtube_playlist' => 'show', 'output_mode' => 'grid', 'multiplegridlayout' => 'carousel', 'carouselarrowleftsvg' => 'value'], 'abc1234')['calls'];
            return end($calls)['atts'] ?? [];
        };
        maw_assert(!array_key_exists('carouselarrowleftsvg', $plan(['value' => 'fas fa-chevron-left', 'library' => 'fa-solid'])), 'an icon-library value (not offered) is ignored');
        maw_assert(!array_key_exists('carouselarrowleftsvg', $plan(['value' => '', 'library' => ''])), 'an empty picker omits the attribute');
        maw_assert(!array_key_exists('carouselarrowleftsvg', $plan(null)), 'an unset atomic value omits the attribute');

        $grid = SettingsAdapter::plan(['multiplegridlayout' => 'grid', 'carouselarrowleftsvg_value' => (string) $left] + $base, 'abc1234')['calls'];
        maw_assert(!array_key_exists('carouselarrowleftsvg', end($grid)['atts'] ?? []), 'the SVG never reaches a grid');

        $text = ShortcodeText::forSettings($base + ['carouselarrowleftsvg_value' => ['value' => ['url' => $url, 'id' => $left], 'library' => 'svg']], 'x');
        maw_assert(str_contains($text, 'carouselarrowleftsvg="' . $left . '"'), 'plain content carries the attachment ID');

        $specs = WidgetSchema::attributeSpecs();
        maw_assert_same(['svg', 'svg'], [$specs['carouselarrowleftsvg']['kind'], $specs['carouselarrowrightsvg']['kind']], 'both arrows have an SVG picker');
        maw_assert(WidgetSchema::hasValueControl($specs['carouselarrowleftsvg']), 'the picker is the value control');
        $keys = array_keys($specs);
        maw_assert(array_search('carouselarrowleftsvg', $keys, true) < array_search('carouselarrowleftimgurl', $keys, true), 'the SVG pickers come before the image pickers they override');
    },
];
