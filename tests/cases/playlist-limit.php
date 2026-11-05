<?php
/**
 * Tests the per-playlist item limit ("Load full playlist" / "Playlist item limit").
 *
 * The limit decides how many processed items a playlist saves. These cases pin:
 *
 * - validation (positive whole numbers, else 6) and the resolution rules,
 *   including the legacy rows: YouTube keeps its full/limited choice (limited
 *   meaning 6), and a podcast saved before 6.2.0 stays full even though its
 *   row stores load_full_playlist = false;
 * - that every loading path applies it identically, after sorting and
 *   de-duplication, counting items rather than keys: the YouTube refresh, a
 *   direct RSS feed, an Apple-resolved feed and the shortcode warm-up, while an
 *   embed-only podcast is never touched;
 * - that the returned data, the transient, the backup file and the browser
 *   payload agree, and that playlists are limited independently;
 * - that stored posts are built from the limited payload but pruned against
 *   the complete source, so a lower limit removes nothing, a genuine removal
 *   still does, and a failed refresh authorizes nothing;
 * - the settings form and its sanitizer, and that the settings-save cache
 *   invalidation makes a new limit take effect on the next refresh, with the
 *   backup still served when that refresh fails.
 */

declare(strict_types=1);

use MediaApiWidget\Admin\SettingsPage;
use MediaApiWidget\Config\Options;
use MediaApiWidget\Frontend\MediaBootstrap;
use MediaApiWidget\PostIndex\PostIndexSync;
use MediaApiWidget\PostIndex\RefreshManifest;
use MediaApiWidget\Support\PlaylistLimit;

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules(bool $hard = true): void
    {
    }
}

/**
 * Builds an RSS feed with one item per GUID, in the order given.
 *
 * @param array<int,string> $guids Episode GUIDs; titles are "Episode {guid}".
 * @return string RSS XML.
 */
function maw_pl_rss(array $guids): string
{
    $items = '';
    foreach ($guids as $guid) {
        $items .= '<item><title>Episode ' . $guid . '</title><description>About ' . $guid . '</description>'
            . '<guid>ep-' . $guid . '</guid><pubDate>Mon, 05 Jan 2026 10:00:00 +0000</pubDate></item>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Limit Cast</title>'
        . '<description>Channel description</description><image><url>https://img.example.com/cast.jpg</url></image>'
        . $items . '</channel></rss>';
}

/**
 * @param int $count Episodes, numbered 1..$count.
 * @return array<int,string> GUID suffixes.
 */
function maw_pl_numbers(int $count): array
{
    return array_map('strval', range(1, $count));
}

/**
 * Returns a podcast loader config.
 *
 * @param array<string,mixed> $overrides Values to merge.
 * @return array<string,mixed> Config.
 */
function maw_pl_podcast(array $overrides = []): array
{
    return array_merge(maw_youtube_config(), [
        'type'               => 'podcast',
        'podcast_platform'   => 'custom',
        'playlist_name'      => 'testpod',
        'media_data'         => 'https://feeds.example.com/limit.xml',
        'load_full_playlist' => false,
    ], $overrides);
}

/**
 * @param mixed $tree Podcast tree.
 * @return array<int,string> Episode GUIDs in stored order.
 */
function maw_pl_guids($tree): array
{
    $items = is_array($tree) ? ($tree['channel']['item'] ?? []) : [];

    return array_map(static fn (array $item): string => (string) $item['guid'], is_array($items) ? array_values($items) : []);
}

/**
 * @param mixed $list YouTube item list.
 * @return array<int,string> Video ids in stored order.
 */
function maw_pl_ids($list): array
{
    return is_array($list) ? array_map(static fn (array $item): string => (string) $item['id'], $list) : [];
}

/**
 * @param string $playlist Playlist slug.
 * @param string $type     Media type.
 * @return mixed The backup file's data, or null.
 */
function maw_pl_backup(string $playlist, string $type)
{
    $path = $type === 'youtube' ? maw_backup_path($playlist) : maw_podcast_backup_path($playlist);
    if (!is_readable($path)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? ($decoded['data'] ?? null) : null;
}

/**
 * @param string $playlist Playlist slug.
 * @return mixed The decoded podcast transient.
 */
function maw_pl_podcast_transient(string $playlist)
{
    $raw = get_transient('podcast_' . $playlist);

    return is_string($raw) ? json_decode($raw, true) : null;
}

/**
 * Calls a private SettingsPage method.
 *
 * @param string            $method Method name.
 * @param array<int,mixed>  $args   Arguments.
 * @return mixed Result.
 */
function maw_pl_settings(string $method, array $args)
{
    $page   = new SettingsPage();
    $invoke = Closure::bind(static fn (SettingsPage $page, string $method, array $args) => $page->$method(...$args), null, SettingsPage::class);

    return $invoke($page, $method, $args);
}

/**
 * Renders one settings row and returns an XPath over it.
 *
 * @param int|string          $index Row index.
 * @param array<string,mixed> $item  Saved row.
 * @param bool                $template True for the add-row template.
 * @return DOMXPath XPath.
 */
function maw_pl_row($index, array $item, bool $template = false): DOMXPath
{
    ob_start();
    maw_pl_settings('renderRow', [$index, $item, $template]);
    $html = (string) ob_get_clean();

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><table>' . $html . '</table>');
    libxml_clear_errors();

    return new DOMXPath($dom);
}

/**
 * Reads the limit controls of one field group of a rendered row.
 *
 * @param DOMXPath $xp    Row.
 * @param string   $group 'youtube' or 'podcast'.
 * @return array<string,mixed> checked, disabled, limit, limit_hidden, block_hidden, note_hidden.
 */
function maw_pl_controls(DOMXPath $xp, string $group): array
{
    $box   = '//div[@data-maw-fields="' . $group . '"]';
    $full  = $xp->query($box . '//input[@data-maw-load-full]')->item(0);
    $limit = $xp->query($box . '//input[@data-maw-limit-input]')->item(0);
    $wrap  = $xp->query($box . '//*[@data-maw-limit-wrap]')->item(0);
    $block = $xp->query($box . '//*[@data-maw-limit]')->item(0);
    $note  = $xp->query($box . '//*[@data-maw-limit-embed-note]')->item(0);
    $hidden = static fn ($el): bool => $el instanceof DOMElement && str_contains((string) $el->getAttribute('style'), 'display:none');

    return [
        'checked'      => $full instanceof DOMElement && $full->hasAttribute('checked'),
        'disabled'     => $full instanceof DOMElement && $full->hasAttribute('disabled') && $limit instanceof DOMElement && $limit->hasAttribute('disabled'),
        'limit'        => $limit instanceof DOMElement ? $limit->getAttribute('value') : null,
        'limit_name'   => $limit instanceof DOMElement ? $limit->getAttribute('name') : null,
        'limit_hidden' => $hidden($wrap),
        'block_hidden' => $hidden($block),
        'note_hidden'  => $hidden($note),
    ];
}

return [

    // -- Resolution -------------------------------------------------------

    'limits are positive whole numbers, anything else is 6' => static function (): void {
        foreach ([[1, 1], [12, 12], ['1', 1], ['12', 12], [' 24 ', 24], ['500', 500]] as [$in, $out]) {
            maw_assert_same($out, PlaylistLimit::sanitize($in), var_export($in, true) . ' is accepted');
        }
        foreach ([0, -3, '0', '-3', '1.5', '1e3', 'abc', '', '  ', null, true, 2.0, ['6'], '12abc'] as $in) {
            maw_assert_same(6, PlaylistLimit::sanitize($in), var_export($in, true) . ' falls back to 6');
        }
    },

    'resolution keeps legacy YouTube choices and legacy podcasts full' => static function (): void {
        $cases = [
            'youtube full (legacy)'                    => [['type' => 'youtube', 'load_full_playlist' => true], null],
            'youtube limited (legacy) keeps 6'         => [['type' => 'youtube', 'load_full_playlist' => false], 6],
            'youtube row with no flag keeps 6'         => [['type' => 'youtube'], 6],
            'youtube legacy constant "1" stays 6'      => [['type' => 'youtube', 'load_full_playlist' => '1'], 6],
            'youtube limited 12'                       => [['type' => 'youtube', 'load_full_playlist' => false, 'playlist_limit' => 12], 12],
            'youtube full ignores a saved 1'           => [['type' => 'youtube', 'load_full_playlist' => true, 'playlist_limit' => 1], null],
            'youtube invalid saved limit is 6'         => [['type' => 'youtube', 'load_full_playlist' => false, 'playlist_limit' => 'abc'], 6],
            'podcast saved before 6.2.0 stays full'    => [['type' => 'podcast', 'podcast_platform' => 'custom', 'load_full_playlist' => false], null],
            'podcast forwarded with a null limit'      => [['type' => 'podcast', 'load_full_playlist' => false, 'playlist_limit' => null], null],
            'podcast limited 3'                        => [['type' => 'podcast', 'podcast_platform' => 'other', 'load_full_playlist' => false, 'playlist_limit' => 3], 3],
            'podcast full'                             => [['type' => 'podcast', 'load_full_playlist' => true, 'playlist_limit' => 3], null],
            'embed podcast never limits'               => [['type' => 'podcast', 'podcast_platform' => 'embed', 'load_full_playlist' => false, 'playlist_limit' => 1], null],
        ];

        foreach ($cases as $label => [$config, $expected]) {
            maw_assert_same($expected, PlaylistLimit::resolve($config), $label);
        }

        maw_assert_same(true, PlaylistLimit::fullSelected(['type' => 'youtube', 'load_full_playlist' => true]), 'a checked YouTube box stays checked');
        maw_assert_same(6, PlaylistLimit::savedLimit(['type' => 'youtube', 'load_full_playlist' => true]), 'and its limit field defaults to 6');
        maw_assert_same(false, PlaylistLimit::fullSelected(['type' => 'podcast', 'podcast_platform' => 'embed', 'load_full_playlist' => false, 'playlist_limit' => 4]), 'an embed row keeps its saved choice for a switch back');
    },

    // -- YouTube ----------------------------------------------------------

    'youtube saves the first N items for 1, 6 and 12, or all of them' => static function (): void {
        foreach (['default' => [[], 6], 'one' => [['playlist_limit' => 1], 1], 'six' => [['playlist_limit' => 6], 6], 'twelve' => [['playlist_limit' => 12], 12], 'invalid' => [['playlist_limit' => '0'], 6]] as $label => [$extra, $expected]) {
            maw_test_reset();
            maw_queue_json(maw_youtube_page(20, null, 20));
            $state = maw_run_youtube_load(maw_youtube_config(['load_full_playlist' => false] + $extra));

            $ids = maw_pl_ids($state['parsedData']);
            maw_assert_same($expected, count($ids), $label . ': the limited count');
            maw_assert_same(array_map(static fn (int $n): string => sprintf('vid%05d', $n), range(1, $expected)), $ids, $label . ': the first items, reindexed');
            maw_assert_same(1, count(MawTestState::$httpRequests), $label . ': the playlist is still fetched once, in full');
        }

        maw_test_reset();
        maw_queue_json(maw_youtube_page(20, null, 20));
        $state = maw_run_youtube_load(maw_youtube_config(['load_full_playlist' => true, 'playlist_limit' => 1]));
        maw_assert_same(20, count($state['parsedData']), 'full mode ignores the saved limit');
    },

    'youtube shorter and empty playlists keep what they have' => static function (): void {
        maw_queue_json(maw_youtube_page(3, null, 3));
        $state = maw_run_youtube_load(maw_youtube_config(['load_full_playlist' => false, 'playlist_limit' => 6]));
        maw_assert_same(['vid00001', 'vid00002', 'vid00003'], maw_pl_ids($state['parsedData']), 'fewer items than the limit are all kept');

        maw_test_reset();
        maw_queue_json(maw_youtube_page(0, null, 0));
        $state = maw_run_youtube_load(maw_youtube_config(['load_full_playlist' => false, 'playlist_limit' => 6]));
        maw_assert_same(false, $state['errorLoadingData'], 'an empty playlist still refreshes');
        maw_assert_same([], get_transient('youtube_testshow'), 'and stores an empty list');
    },

    'youtube sorts and de-duplicates before limiting, counting items not keys' => static function (): void {
        maw_queue_json(maw_youtube_page(12, null, 12));
        $state = maw_run_youtube_load(maw_youtube_config(['sort_mode' => 'number_in_title', 'load_full_playlist' => false, 'playlist_limit' => 3]));
        maw_assert_same(['Episode 12', 'Episode 11', 'Episode 10'], array_column($state['parsedData'], 'title'), 'the highest episodes are kept, not the first fetched');

        // Item 2 repeats item 1's title, so de-duplication leaves a gap at key 1.
        // Counting keys (as the old six-item trim did) would keep only five.
        maw_test_reset();
        $page = maw_youtube_page(8, null, 8);
        $page['items'][1]['snippet']['title'] = $page['items'][0]['snippet']['title'];
        maw_queue_json($page);
        $state = maw_run_youtube_load(maw_youtube_config(['load_full_playlist' => false, 'playlist_limit' => 6]));
        maw_assert_same(['vid00001', 'vid00003', 'vid00004', 'vid00005', 'vid00006', 'vid00007'], maw_pl_ids($state['parsedData']), 'six items survive the duplicate');
        maw_assert_same(range(0, 5), array_keys($state['parsedData']), 'with contiguous keys');
    },

    'youtube returned data, transient, backup and browser payload agree' => static function (): void {
        maw_queue_json(maw_youtube_page(10, null, 10));
        $out = maw_run_media_content(maw_youtube_config(['load_full_playlist' => false, 'playlist_limit' => 4]));

        $transient = get_transient('youtube_testshow');
        maw_assert_same(4, count($transient), 'the transient holds four');
        maw_assert_same($transient, maw_pl_backup('testshow', 'youtube'), 'the backup file holds the same four');
        maw_assert_same(4, substr_count($out, '"id":"vid'), 'the browser receives the same four');
        maw_assert(str_contains($out, 'localStorage.setItem("testshow_youtube_playlist"'), 'in the playlist\'s local storage key');
    },

    // -- Podcasts ---------------------------------------------------------

    'a direct RSS podcast keeps N episodes and every channel field' => static function (): void {
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(9)));
        $state = maw_run_podcast_load(maw_pl_podcast(['playlist_limit' => 3]));

        maw_assert_same(['ep-1', 'ep-2', 'ep-3'], maw_pl_guids($state['parsedData']), 'the first three episodes');
        maw_assert_same([0, 1, 2], array_keys($state['parsedData']['channel']['item']), 'reindexed');
        maw_assert_same('Limit Cast', $state['parsedData']['channel']['title'], 'the channel title is kept');
        maw_assert_same('Channel description', $state['parsedData']['channel']['description'], 'and its description');
        maw_assert_same('https://img.example.com/cast.jpg', $state['parsedData']['channel']['image']['url'] ?? null, 'and its image');
        maw_assert_same('https://feeds.example.com/limit.xml', $state['parsedData']['channel']['rssUrl'], 'and its feed URL');
        maw_assert_same($state['parsedData'], maw_pl_podcast_transient('testpod'), 'the transient matches');
        maw_assert_same($state['parsedData'], maw_pl_backup('testpod', 'podcast'), 'the backup matches');

        foreach (['one' => [1, 1], 'twelve with nine available' => [12, 9]] as $label => [$limit, $expected]) {
            maw_test_reset();
            maw_queue_raw(maw_pl_rss(maw_pl_numbers(9)));
            $state = maw_run_podcast_load(maw_pl_podcast(['playlist_limit' => $limit]));
            maw_assert_same($expected, count(maw_pl_guids($state['parsedData'])), $label);
        }

        maw_test_reset();
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(9)));
        $state = maw_run_podcast_load(maw_pl_podcast(['load_full_playlist' => true, 'playlist_limit' => 1]));
        maw_assert_same(9, count(maw_pl_guids($state['parsedData'])), 'full mode ignores the saved limit');
    },

    'a podcast is numbered and sorted before it is limited' => static function (): void {
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(8)));
        $state = maw_run_podcast_load(maw_pl_podcast([
            'playlist_limit' => 3, 'season_episode_regex_enabled' => true, 'season_episode_regex' => 'Episode (\d+)', 'podcast_sort_mode' => 'season_episode',
        ]));

        maw_assert_same(['ep-8', 'ep-7', 'ep-6'], maw_pl_guids($state['parsedData']), 'the newest numbered episodes are kept');
    },

    'single-episode and empty feeds are stored as they are' => static function (): void {
        maw_queue_raw(maw_pl_rss(['1']));
        $state = maw_run_podcast_load(maw_pl_podcast(['playlist_limit' => 1]));
        maw_assert_same('ep-1', $state['parsedData']['channel']['item']['guid'] ?? null, 'a single episode keeps its shape');

        maw_test_reset();
        maw_queue_raw(maw_pl_rss([]));
        $state = maw_run_podcast_load(maw_pl_podcast(['playlist_limit' => 1]));
        maw_assert_same(false, $state['errorLoadingData'], 'a feed without episodes still refreshes');
        maw_assert_same('Limit Cast', $state['parsedData']['channel']['title'], 'with its channel');
    },

    'an Apple-resolved podcast is limited the same way' => static function (): void {
        maw_queue_json(['resultCount' => 1, 'results' => [['feedUrl' => 'https://feeds.example.com/apple.xml', 'collectionViewUrl' => 'https://podcasts.apple.com/x/id1']]]);
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(7)));
        $state = maw_run_podcast_load(maw_pl_podcast(['podcast_platform' => 'other', 'media_data' => '123456', 'playlist_limit' => 2]));

        maw_assert_same(['ep-1', 'ep-2'], maw_pl_guids($state['parsedData']), 'two episodes are kept');
        maw_assert_same('https://feeds.example.com/apple.xml', $state['parsedData']['channel']['rssUrl'], 'the resolved feed URL is kept');
        maw_assert_same($state['parsedData'], maw_pl_backup('testpod', 'podcast'), 'the backup matches');
    },

    'the shortcode warm-up applies the same limit as the wp_head refresh' => static function (): void {
        $config = ['type' => 'podcast', 'playlist_name' => 'testpod', 'podcast_platform' => 'custom', 'media_data' => 'https://feeds.example.com/limit.xml', 'load_full_playlist' => false, 'playlist_limit' => 4];

        maw_queue_raw(maw_pl_rss(maw_pl_numbers(9)));
        $warm = maw_run_podcast_warmup('testpod', $config);
        maw_assert_same(['ep-1', 'ep-2', 'ep-3', 'ep-4'], maw_pl_guids($warm), 'the warm-up returns four');
        maw_assert_same($warm, maw_pl_podcast_transient('testpod'), 'and stores the same four');
        maw_assert_same($warm, maw_pl_backup('testpod', 'podcast'), 'in the backup too');
        maw_assert_same('Limit Cast', $warm['channel']['title'], 'with the channel');

        maw_test_reset();
        maw_queue_json(['resultCount' => 1, 'results' => [['feedUrl' => 'https://feeds.example.com/apple.xml']]]);
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(9)));
        maw_assert_same(['ep-1', 'ep-2'], maw_pl_guids(maw_run_podcast_warmup('testpod', ['podcast_platform' => 'other', 'media_data' => '123'] + ['playlist_limit' => 2] + $config)), 'an Apple-resolved warm-up too');

        maw_test_reset();
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(9)));
        $legacy = $config;
        unset($legacy['playlist_limit']);
        maw_assert_same(9, count(maw_pl_guids(maw_run_podcast_warmup('testpod', $legacy))), 'a podcast row saved before 6.2.0 warms up in full');
    },

    'an embed-only podcast is never limited' => static function (): void {
        $state = maw_run_podcast_load(maw_pl_podcast(['podcast_platform' => 'embed', 'media_data' => 'https://player.example.com/embed/1', 'playlist_limit' => 1]));

        maw_assert_same('https://player.example.com/embed/1', $state['parsedData'], 'the embed URL is stored as it is');
        maw_assert_same(0, count(MawTestState::$httpRequests), 'nothing is fetched');
        maw_assert_same([], maw_run_podcast_warmup('testpod', ['podcast_platform' => 'embed', 'media_data' => 'https://player.example.com/embed/1', 'playlist_limit' => 1]), 'the warm-up still returns no episodes');
    },

    'a podcast saved before 6.2.0 keeps loading in full' => static function (): void {
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(9)));
        $config = maw_pl_podcast();
        unset($config['playlist_limit']);
        $state = maw_run_podcast_load($config);

        maw_assert_same(false, $config['load_full_playlist'], 'the row carries the old sanitizer\'s load_full_playlist = false');
        maw_assert_same(9, count(maw_pl_guids($state['parsedData'])), 'yet every episode is stored');
    },

    // -- Configuration paths ---------------------------------------------

    'wp_head forwards each playlist its own setting' => static function (): void {
        Options::setMediaItems([
            ['type' => 'youtube', 'playlist_name' => 'alpha', 'api_key' => 'k', 'media_data' => 'PLA', 'load_full_playlist' => false, 'playlist_limit' => 2],
            ['type' => 'youtube', 'playlist_name' => 'beta', 'api_key' => 'k', 'media_data' => 'PLB', 'load_full_playlist' => true, 'playlist_limit' => 2],
            ['type' => 'youtube', 'playlist_name' => 'gamma', 'api_key' => 'k', 'media_data' => 'PLC', 'load_full_playlist' => false],
            ['type' => 'podcast', 'playlist_name' => 'oldpod', 'podcast_platform' => 'custom', 'media_data' => 'https://feeds.example.com/old.xml', 'load_full_playlist' => false],
            ['type' => 'podcast', 'playlist_name' => 'newpod', 'podcast_platform' => 'custom', 'media_data' => 'https://feeds.example.com/new.xml', 'load_full_playlist' => false, 'playlist_limit' => 3],
        ]);
        maw_queue_json(maw_youtube_page(9, null, 9));
        maw_queue_json(maw_youtube_page(9, null, 9));
        maw_queue_json(maw_youtube_page(9, null, 9));
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(8)));
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(8)));

        unset($GLOBALS['maw_cookie_expired'], $GLOBALS['maw_cookie_name']);
        ob_start();
        (new MediaBootstrap())->renderMedia();
        ob_end_clean();

        maw_assert_same(2, count(get_transient('youtube_alpha')), 'alpha keeps two');
        maw_assert_same(9, count(get_transient('youtube_beta')), 'beta loads in full and ignores its saved two');
        maw_assert_same(6, count(get_transient('youtube_gamma')), 'gamma, saved before 6.2.0 and limited, keeps six');
        maw_assert_same(8, count(maw_pl_guids(maw_pl_podcast_transient('oldpod'))), 'the podcast saved before 6.2.0 stays full');
        maw_assert_same(3, count(maw_pl_guids(maw_pl_podcast_transient('newpod'))), 'the limited podcast keeps three');
    },

    // -- Stored posts -----------------------------------------------------

    'a lower podcast limit keeps posts, a real removal is still detected, a failure removes nothing' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_enable('testpod', 'podcast', true, ['podcast_platform' => 'custom', 'media_data' => 'https://feeds.example.com/limit.xml']);
        $sync = static fn (): array => PostIndexSync::sync('testpod', 'podcast', PostIndexSync::SOURCE_MANUAL);

        maw_queue_raw(maw_pl_rss(maw_pl_numbers(8)));
        maw_run_podcast_load(maw_pl_podcast(['load_full_playlist' => true]));
        $sync();
        maw_assert_same(8, count(maw_mpi_posts()), 'the full feed is stored as posts');

        maw_queue_raw(maw_pl_rss(maw_pl_numbers(8)));
        $state = maw_run_podcast_load(maw_pl_podcast(['playlist_limit' => 3]));
        maw_assert_same(3, count(maw_pl_guids($state['parsedData'])), 'three episodes are saved for the player');
        maw_assert_same(8, count(RefreshManifest::get('testpod', 'podcast')['keys']), 'but the refresh records all eight');
        maw_assert_same(true, RefreshManifest::get('testpod', 'podcast')['complete'], 'as complete membership');
        maw_assert_same(0, $sync()['pruned'], 'nothing is removed');
        maw_assert_same(8, count(maw_mpi_posts()), 'all eight posts are kept');

        // Episode 8 leaves the feed.
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(7)));
        maw_run_podcast_load(maw_pl_podcast(['playlist_limit' => 3]));
        maw_assert_same(1, $sync()['pruned'], 'the departed episode is removed');
        maw_assert_same(7, count(maw_mpi_posts()), 'the episodes outside the limit stay');

        // A failed refresh records nothing and removes nothing.
        $token = RefreshManifest::get('testpod', 'podcast')['token'];
        maw_queue_raw('', 500);
        $state = maw_run_podcast_load(maw_pl_podcast(['playlist_limit' => 1]));
        maw_assert_same(true, $state['errorLoadingData'], 'the refresh fails');
        maw_assert_same($token, RefreshManifest::get('testpod', 'podcast')['token'], 'no refresh is recorded');
        maw_assert_same(3, count(maw_pl_guids(maw_pl_podcast_transient('testpod'))), 'the last good data is kept');
        maw_assert_same(0, $sync()['pruned'], 'nothing is removed');
        maw_assert_same(7, count(maw_mpi_posts()), 'every post is kept');
    },

    'a lower YouTube limit keeps posts, through the shortcode warm-up too' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_enable('testshow', 'youtube');
        maw_queue_json(maw_youtube_page(10, null, 10));
        maw_run_youtube_load(maw_youtube_config(['load_full_playlist' => true]));
        PostIndexSync::sync('testshow', 'youtube', PostIndexSync::SOURCE_MANUAL);
        maw_assert_same(10, count(maw_mpi_posts()), 'ten posts');

        delete_option('maw_yt_last_fetched_testshow');
        maw_queue_json(maw_youtube_page(10, null, 10));
        maw_run_youtube_load(maw_youtube_config(['load_full_playlist' => false, 'playlist_limit' => 2]));
        maw_assert_same(2, count(get_transient('youtube_testshow')), 'two are saved');
        maw_assert_same(10, count(RefreshManifest::get('testshow', 'youtube')['keys']), 'ten are recorded');
        maw_assert_same(0, PostIndexSync::sync('testshow', 'youtube', PostIndexSync::SOURCE_MANUAL)['pruned'], 'nothing is removed');

        maw_mpi_enable('cast', 'podcast', true, ['podcast_platform' => 'custom']);
        maw_queue_raw(maw_pl_rss(maw_pl_numbers(5)));
        maw_run_podcast_warmup('cast', ['type' => 'podcast', 'playlist_name' => 'cast', 'podcast_platform' => 'custom', 'media_data' => 'https://feeds.example.com/limit.xml', 'load_full_playlist' => false, 'playlist_limit' => 1]);
        maw_assert_same(5, count(RefreshManifest::get('cast', 'podcast')['keys']), 'the warm-up records the complete feed as well');
        maw_assert_same(1, count(maw_pl_guids(maw_pl_podcast_transient('cast'))), 'while saving one episode');
    },

    // -- Settings ---------------------------------------------------------

    'the settings sanitizer validates and keeps the limit' => static function (): void {
        $rows = maw_pl_settings('sanitizeItems', [[
            ['type' => 'youtube', 'playlist_name' => 'a', 'playlist_limit' => '12'],
            ['type' => 'youtube', 'playlist_name' => 'b', 'load_full_playlist' => '1', 'playlist_limit' => '3'],
            ['type' => 'youtube', 'playlist_name' => 'c', 'playlist_limit' => '0'],
            ['type' => 'podcast', 'playlist_name' => 'd', 'podcast_platform' => 'custom', 'playlist_limit' => 'abc'],
            ['type' => 'podcast', 'playlist_name' => 'e', 'podcast_platform' => 'custom', 'load_full_playlist' => '1'],
            ['type' => 'podcast', 'playlist_name' => 'f', 'podcast_platform' => 'embed', 'playlist_limit' => '2'],
        ]]);

        maw_assert_same([12, 3, 6, 6, 6, 2], array_column($rows, 'playlist_limit'), 'limits are validated, with 6 for invalid or missing values');
        maw_assert_same([false, true, false, false, true, false], array_column($rows, 'load_full_playlist'), 'the checkbox is a boolean');
        maw_assert_same([12, null, 6, 6, null, null], array_map([PlaylistLimit::class, 'resolve'], $rows), 'full mode keeps its saved limit but ignores it; embed ignores both');
    },

    'the settings form shows each saved choice' => static function (): void {
        $legacyFull = maw_pl_controls(maw_pl_row(0, ['type' => 'youtube', 'playlist_name' => '', 'load_full_playlist' => true]), 'youtube');
        maw_assert_same([true, '6', true], [$legacyFull['checked'], $legacyFull['limit'], $legacyFull['limit_hidden']], 'a YouTube playlist that loaded in full stays checked, its hidden limit defaulting to 6');

        $legacyLimited = maw_pl_controls(maw_pl_row(0, ['type' => 'youtube', 'playlist_name' => '', 'load_full_playlist' => false]), 'youtube');
        maw_assert_same([false, '6', false], [$legacyLimited['checked'], $legacyLimited['limit'], $legacyLimited['limit_hidden']], 'a limited one shows its six');

        $xp = maw_pl_row(1, ['type' => 'youtube', 'playlist_name' => '', 'load_full_playlist' => false, 'playlist_limit' => 12]);
        $yt = maw_pl_controls($xp, 'youtube');
        maw_assert_same(['12', 'maw_items[1][playlist_limit]', false], [$yt['limit'], $yt['limit_name'], $yt['disabled']], 'a saved limit is shown under the row\'s index');
        maw_assert_same(true, maw_pl_controls($xp, 'podcast')['disabled'], 'the podcast copy of the controls is disabled, so one value is submitted');

        $oldPod = maw_pl_controls(maw_pl_row(0, ['type' => 'podcast', 'playlist_name' => '', 'podcast_platform' => 'custom', 'load_full_playlist' => false]), 'podcast');
        maw_assert_same([true, '6', false], [$oldPod['checked'], $oldPod['limit'], $oldPod['disabled']], 'a podcast saved before 6.2.0 shows as full');

        $embed = maw_pl_controls(maw_pl_row(0, ['type' => 'podcast', 'playlist_name' => '', 'podcast_platform' => 'embed', 'load_full_playlist' => false, 'playlist_limit' => 4]), 'podcast');
        maw_assert_same([true, false, '4', false], [$embed['block_hidden'], $embed['note_hidden'], $embed['limit'], $embed['disabled']], 'an embed podcast hides the controls, explains why, and keeps their values');

        $template = maw_pl_row('__INDEX__', ['type' => '__TYPE__'], true);
        foreach (['youtube', 'podcast'] as $group) {
            $new = maw_pl_controls($template, $group);
            maw_assert_same([false, '6', false], [$new['checked'], $new['limit'], $new['limit_hidden']], 'a new ' . $group . ' row starts limited to 6');
        }

        $legacyRoundTrip = maw_pl_settings('sanitizeItems', [[['type' => 'podcast', 'playlist_name' => 'old', 'podcast_platform' => 'custom', 'load_full_playlist' => '1', 'playlist_limit' => '6']]]);
        maw_assert_same(null, PlaylistLimit::resolve($legacyRoundTrip[0]), 'saving that podcast unchanged keeps it full');
    },

    // -- Caching ----------------------------------------------------------

    'saving settings makes a new limit take effect, and a failed refresh serves the backup' => static function (): void {
        $before = ['type' => 'youtube', 'playlist_name' => 'testshow', 'load_full_playlist' => false, 'playlist_limit' => 3];
        $after  = ['playlist_limit' => 8] + $before;
        $clear  = static function (array $items): void {
            maw_capture_php_errors(static fn () => maw_pl_settings('clearCache', [$items, ['media_api_widget']]));
        };

        maw_queue_json(maw_youtube_page(10, null, 10));
        maw_run_media_content(maw_youtube_config($before));
        maw_assert_same(3, count(get_transient('youtube_testshow')), 'three are stored');

        maw_assert_same(3, substr_count(maw_run_media_content(maw_youtube_config($after)), '"id":"vid'), 'without invalidation the cached three are still served');

        $clear([$before, $after]);
        maw_assert_same(false, get_transient('youtube_testshow'), 'the settings save clears the transient');
        maw_assert_same(0, (int) get_option('maw_yt_last_fetched_testshow', 0), 'and the refresh window, so the next view refetches');

        maw_queue_json(maw_youtube_page(10, null, 10));
        $out = maw_run_media_content(maw_youtube_config($after));
        maw_assert_same(8, count(get_transient('youtube_testshow')), 'the next refresh stores eight');
        maw_assert_same(8, count((array) maw_pl_backup('testshow', 'youtube')), 'the backup holds eight');
        maw_assert_same(8, substr_count($out, '"id":"vid'), 'the browser receives eight');

        $clear([$after, ['playlist_limit' => 2] + $after]);
        maw_queue_error();
        $out = maw_run_media_content(maw_youtube_config(['playlist_limit' => 2] + $after));
        maw_assert_same(8, count((array) maw_pl_backup('testshow', 'youtube')), 'a failed refresh keeps the last good backup');
        maw_assert_same(8, substr_count($out, '"id":"vid'), 'and the browser is given that backup until a refresh succeeds');
    },
];
