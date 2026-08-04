<?php
/**
 * Tests the developer extensibility API added in 5.0.0.
 *
 * The contract these cases defend is about *timing* as much as behavior: the
 * pre-store filter must fire exactly once per complete, successful logical
 * refresh, and never for a cache hit, a backup read, a partial refresh, or a
 * failed request. A hook that fires on a partial refresh would let an
 * integration act on truncated data; a hook that fires per YouTube page would
 * call a paid transcription API once per 50 episodes instead of once per
 * playlist.
 *
 * They also pin the storage rules: the filtered value is what gets written and
 * what the current response renders, storage formats existing readers depend on
 * are unchanged, a refused or unencodable payload leaves the old data
 * byte-for-byte intact, and every lock is released on every exit path.
 */

declare(strict_types=1);

use MediaApiWidget\Support\BackupFiles;
use MediaApiWidget\Support\MediaStore;
use MediaApiWidget\Support\OptionLock;
use MediaApiWidget\Support\YoutubeGuard;

/**
 * Seeds a known-good YouTube backup file and transient for the test playlist.
 *
 * Returns the exact JSON written so a test can assert the file is unchanged
 * byte-for-byte rather than merely "still valid".
 *
 * @param string $playlistName Playlist slug.
 * @return string The JSON that was written.
 */
function maw_ext_seed_youtube(string $playlistName = 'testshow'): string
{
    $data = [
        [
            'title'         => 'Stored Episode',
            'episode'       => 1,
            'id'            => 'storedvid',
            'thumbnail'     => ['url' => 'https://example.com/stored.jpg'],
            'publishedDate' => '2025-01-01T00:00:00Z',
            'description'   => 'stored',
        ],
    ];

    $json = (string) json_encode(['time_stored' => 1700000000, 'data' => $data]);

    file_put_contents(maw_backup_path($playlistName), $json);
    set_transient('youtube_' . $playlistName, $data, 7200);
    MediaApiWidget\Stats\BackupInventory::flushCache();

    return $json;
}

/**
 * Asserts that no extension hook fired at all.
 *
 * @param string $why Why this situation must not reach the store.
 * @return void
 */
function maw_ext_assert_no_hooks(string $why): void
{
    maw_assert_same(0, maw_hook_count(MediaStore::FILTER_BEFORE_STORE), 'no pre-store filter fires ' . $why);
    maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'no stored action fires ' . $why);
}

/**
 * Asserts the seeded YouTube backup and transient are exactly as they were.
 *
 * @param string $good The JSON returned by {@see maw_ext_seed_youtube()}.
 * @return void
 */
function maw_ext_assert_youtube_untouched(string $good): void
{
    maw_assert_same(
        $good,
        (string) file_get_contents(maw_backup_path('testshow')),
        'the backup file is byte-for-byte unchanged'
    );
    maw_assert_same(
        'storedvid',
        get_transient('youtube_testshow')[0]['id'] ?? null,
        'the transient still holds the previously stored playlist'
    );
    maw_assert_same(0, get_option('maw_yt_last_fetched_testshow', 0), 'the last-fetched timestamp is not set');
}

/**
 * Returns a minimal but valid podcast RSS document.
 *
 * Case files are required one at a time in alphabetical order, so this group
 * cannot borrow the fixture from the later `podcast` group and keeps its own.
 *
 * @return string RSS XML.
 */
function maw_ext_podcast_rss(): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Test Podcast</title>
    <description>A test feed.</description>
    <item>
      <title>Podcast Episode 1</title>
      <description>&lt;p&gt;First episode.&lt;/p&gt;</description>
      <guid>ep-1</guid>
      <pubDate>Mon, 06 Jan 2025 00:00:00 +0000</pubDate>
    </item>
    <item>
      <title>Podcast Episode 2</title>
      <description>&lt;p&gt;Second episode.&lt;/p&gt;</description>
      <guid>ep-2</guid>
      <pubDate>Mon, 13 Jan 2025 00:00:00 +0000</pubDate>
    </item>
  </channel>
</rss>
XML;
}

/**
 * Returns a podcast media config for the extension tests.
 *
 * Defined separately from maw_podcast_config() in the podcast group so the two
 * groups cannot drift into each other's expectations.
 *
 * @param array<string,mixed> $overrides Values to merge over the defaults.
 * @return array<string,mixed> Resolved config.
 */
function maw_ext_podcast_config(array $overrides = []): array
{
    return array_merge(maw_youtube_config(), [
        'type'             => 'podcast',
        'podcast_platform' => 'custom',
        'playlist_name'    => 'testpod',
        'media_data'       => 'https://feeds.example.com/podcast.xml',
    ], $overrides);
}

/**
 * Returns the admin media config array the shortcode warm-up path expects.
 *
 * @param array<string,mixed> $overrides Values to merge over the defaults.
 * @return array<string,mixed> Media config.
 */
function maw_ext_warmup_config(array $overrides = []): array
{
    return array_merge([
        'type'             => 'podcast',
        'playlist_name'    => 'testpod',
        'podcast_platform' => 'custom',
        'media_data'       => 'https://feeds.example.com/podcast.xml',
    ], $overrides);
}

return [

    // -----------------------------------------------------------------------
    // Filter timing
    // -----------------------------------------------------------------------

    'a multipage youtube refresh fires the filter exactly once' => static function (): void {
        maw_queue_json(maw_youtube_page(50, 'TOKEN-1', 61, 0));
        maw_queue_json(maw_youtube_page(11, null, 61, 50));

        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(2, count(MawTestState::$httpRequests), 'both pages were fetched');
        maw_assert_same(
            1,
            maw_hook_count(MediaStore::FILTER_BEFORE_STORE),
            'the filter fires once for the whole playlist, not once per page'
        );
        maw_assert_same(1, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action fires once');
    },

    'the youtube filter receives the playlist name and youtube media type' => static function (): void {
        maw_queue_json(maw_youtube_page(3, null, 3));

        maw_run_youtube_load(maw_youtube_config(['playlist_name' => 'testshow']));

        [$data, $context] = maw_hook_args(MediaStore::FILTER_BEFORE_STORE);

        maw_assert_same('testshow', $context['playlist_name'], 'the context carries the playlist name');
        maw_assert_same('youtube', $context['media_type'], 'the context carries the youtube media type');
        maw_assert_same('remote_refresh', $context['source'], 'the context reports a remote refresh');
        maw_assert_same(null, $context['podcast_platform'], 'podcast_platform is null for youtube');
        maw_assert_same(3, count($data), 'the filter receives the parsed playlist');
        maw_assert_same(true, array_is_list($data), 'youtube data is an indexed list');
        maw_assert_same('vid00001', $data[0]['id'] ?? null, 'the items are parsed media item arrays');
    },

    'the hook context never carries the api key' => static function (): void {
        maw_queue_json(maw_youtube_page(2, null, 2));

        maw_run_youtube_load(maw_youtube_config());

        $encoded = (string) json_encode(maw_hook_args(MediaStore::FILTER_BEFORE_STORE)[1] ?? []);

        maw_assert(
            !str_contains($encoded, 'SECRET-API-KEY-DO-NOT-LEAK'),
            'the api key never reaches the hook context'
        );
        maw_assert_same(
            ['playlist_name', 'media_type', 'source', 'podcast_platform'],
            array_keys(maw_hook_args(MediaStore::FILTER_BEFORE_STORE)[1] ?? []),
            'the context exposes only the four documented keys'
        );
    },

    'a transient cache hit fires no hooks' => static function (): void {
        maw_ext_seed_youtube();

        // A response is queued to prove it is never requested.
        maw_queue_json(maw_youtube_page(3, null, 3));

        maw_run_media_content(maw_youtube_config());

        maw_assert_same(0, count(MawTestState::$httpRequests), 'no request is made on a cache hit');
        maw_ext_assert_no_hooks('on a transient cache hit');
    },

    'a backup window read fires no hooks' => static function (): void {
        $good = maw_ext_seed_youtube();
        delete_transient('youtube_testshow');
        update_option('maw_yt_last_fetched_testshow', time() - 10, false);

        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(0, count(MawTestState::$httpRequests), 'the backup window short-circuits the fetch');
        maw_assert_same('backup cache (rate limit)', $state['dataLoadedMethod'], 'the backup file was served');
        maw_ext_assert_no_hooks('when the backup file is served');
        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is byte-for-byte unchanged'
        );
    },

    'an http error part way through fires no hooks and keeps the old data' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_queue_json(maw_youtube_page(50, 'TOKEN-1', 120, 0));
        maw_queue_error();

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the failure is reported');
        maw_ext_assert_no_hooks('on a transport failure');
        maw_ext_assert_youtube_untouched($good);
    },

    'a repeated page token fires no hooks and keeps the old data' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_queue_json(maw_youtube_page(50, 'LOOP', 500, 0));
        maw_queue_json(maw_youtube_page(50, 'LOOP', 500, 50));

        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same('repeated_page_token', YoutubeGuard::getGuardStatus()['reason'] ?? '', 'the loop is caught');
        maw_ext_assert_no_hooks('when pagination would have looped');
        maw_ext_assert_youtube_untouched($good);
    },

    'hitting the page ceiling fires no hooks and keeps the old data' => static function (): void {
        $good = maw_ext_seed_youtube();

        for ($page = 0; $page < 4; $page++) {
            maw_queue_json(maw_youtube_page(50, 'TOKEN-' . $page, 10000, $page * 50));
        }

        maw_run_youtube_load(maw_youtube_config(['youtube_max_pages_per_refresh' => 3]));

        maw_assert_same('maximum_pages_reached', YoutubeGuard::getGuardStatus()['reason'] ?? '', 'the ceiling stops it');
        maw_ext_assert_no_hooks('on a partial refresh capped by the page ceiling');
        maw_ext_assert_youtube_untouched($good);
    },

    'exhausting the daily limit mid refresh fires no hooks' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_queue_json(maw_youtube_page(50, 'TOKEN-1', 500, 0));
        maw_queue_json(maw_youtube_page(50, 'TOKEN-2', 500, 50));

        maw_run_youtube_load(maw_youtube_config(['youtube_daily_call_limit' => 1]));

        maw_assert_same('daily_limit_reached', YoutubeGuard::getGuardStatus()['reason'] ?? '', 'the breaker trips');
        maw_ext_assert_no_hooks('when the daily call limit blocks a request');
        maw_ext_assert_youtube_untouched($good);
    },

    'a malformed response fires no hooks and keeps the old data' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_queue_raw('{not json at all');

        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same('malformed_response', YoutubeGuard::getGuardStatus()['reason'] ?? '', 'the shape is rejected');
        maw_ext_assert_no_hooks('on a malformed API response');
        maw_ext_assert_youtube_untouched($good);
    },

    'an item without a snippet fires no hooks' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_queue_raw((string) json_encode([
            'items'    => [['id' => 'broken']],
            'pageInfo' => ['totalResults' => 1, 'resultsPerPage' => 50],
        ]));

        $previous = error_reporting(E_ALL & ~E_WARNING);
        $state    = maw_run_youtube_load(maw_youtube_config());
        error_reporting($previous);

        maw_assert_same(true, $state['abort'], 'the render is aborted');
        maw_ext_assert_no_hooks('when an item is structurally invalid');
        maw_ext_assert_youtube_untouched($good);
    },

    'a concurrent refresh lock fires no hooks' => static function (): void {
        $good = maw_ext_seed_youtube();
        delete_transient('youtube_testshow');

        YoutubeGuard::acquireLock('testshow', 600);
        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(0, count(MawTestState::$httpRequests), 'no duplicate fetch is started');
        maw_assert_same(true, $state['errorLoadingData'], 'the blocked refresh is reported');
        maw_ext_assert_no_hooks('when another worker holds the refresh lock');
        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is byte-for-byte unchanged'
        );
    },

    // -----------------------------------------------------------------------
    // Storage identity
    // -----------------------------------------------------------------------

    'the filtered youtube data is written identically to the transient and backup' => static function (): void {
        maw_on(MediaStore::FILTER_BEFORE_STORE, static function ($data, array $context) {
            foreach ($data as &$item) {
                $item['transcript_status'] = 'pending';
            }
            unset($item);

            return $data;
        });

        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        $transient = get_transient('youtube_testshow');
        $backup    = json_decode((string) file_get_contents(maw_backup_path('testshow')), true);

        maw_assert_same(
            wp_json_encode($transient),
            wp_json_encode($backup['data']),
            'the transient and the backup hold the identical filtered payload'
        );
        maw_assert_same('pending', $transient[0]['transcript_status'] ?? null, 'the filter value reached the transient');
        maw_assert_same('pending', $backup['data'][0]['transcript_status'] ?? null, 'the filter value reached the backup');
        maw_assert_same(
            'pending',
            $state['parsedData'][0]['transcript_status'] ?? null,
            'the filtered value is what the current response renders'
        );
    },

    'a filter may remove items and the reduced list is what is stored' => static function (): void {
        maw_on(MediaStore::FILTER_BEFORE_STORE, static fn ($data, array $context) => array_values(
            array_slice($data, 0, 1)
        ));

        maw_queue_json(maw_youtube_page(5, null, 5));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(1, count(get_transient('youtube_testshow')), 'the transient holds the reduced list');
        maw_assert_same(1, count($state['parsedData']), 'the response renders the reduced list');
        maw_assert_same(
            1,
            count(maw_hook_args(MediaStore::ACTION_STORED)[0] ?? []),
            'the action receives the reduced list, not the raw one'
        );
    },

    'a filter preserves arbitrary custom keys on items' => static function (): void {
        maw_on(MediaStore::FILTER_BEFORE_STORE, static function ($data, array $context) {
            $data[0]['my_plugin_meta'] = ['nested' => ['deep' => true], 'count' => 7];

            return $data;
        });

        maw_queue_json(maw_youtube_page(2, null, 2));

        maw_run_youtube_load(maw_youtube_config());

        $stored = get_transient('youtube_testshow');

        maw_assert_same(true, $stored[0]['my_plugin_meta']['nested']['deep'] ?? null, 'nested custom data survives');
        maw_assert_same(7, $stored[0]['my_plugin_meta']['count'] ?? null, 'custom scalar data survives');
    },

    // -----------------------------------------------------------------------
    // Podcast
    // -----------------------------------------------------------------------

    'a successful custom rss refresh fires the filter once with podcast context' => static function (): void {
        maw_queue_raw(maw_ext_podcast_rss());

        maw_run_podcast_load(maw_ext_podcast_config());

        maw_assert_same(1, maw_hook_count(MediaStore::FILTER_BEFORE_STORE), 'the filter fires once');

        [$data, $context] = maw_hook_args(MediaStore::FILTER_BEFORE_STORE);

        maw_assert_same('testpod', $context['playlist_name'], 'the context carries the playlist name');
        maw_assert_same('podcast', $context['media_type'], 'the context carries the podcast media type');
        maw_assert_same('custom', $context['podcast_platform'], 'the context carries the podcast platform');
        maw_assert_same('remote_refresh', $context['source'], 'the context reports a remote refresh');
        maw_assert_same(true, is_array($data), 'podcast data is normalized to a plain array');
        maw_assert_same('Test Podcast', $data['channel']['title'] ?? null, 'the channel is readable as an array');
        maw_assert_same(2, count($data['channel']['item'] ?? []), 'both episodes are present');
        maw_assert_same('ep-1', $data['channel']['item'][0]['guid'] ?? null, 'episode guids are readable');
        maw_assert_same(
            'https://feeds.example.com/podcast.xml',
            $data['channel']['rssUrl'] ?? null,
            'the feed url is attached to the channel'
        );
    },

    'the podcast transient still holds a json string after a refresh' => static function (): void {
        maw_queue_raw(maw_ext_podcast_rss());

        maw_run_podcast_load(maw_ext_podcast_config());

        $stored = get_transient('podcast_testpod');

        maw_assert_same(true, is_string($stored), 'the podcast transient remains a JSON string');
        maw_assert_same(
            'Test Podcast',
            json_decode($stored, true)['channel']['title'] ?? null,
            'existing readers can still decode it to an array'
        );
    },

    'a successful podcast refresh writes a backup file' => static function (): void {
        maw_queue_raw(maw_ext_podcast_rss());

        maw_run_podcast_load(maw_ext_podcast_config());

        $backup = json_decode((string) file_get_contents(maw_podcast_backup_path('testpod')), true);

        maw_assert(is_array($backup), 'a podcast backup file is written');
        maw_assert('Test Podcast' === ($backup['data']['channel']['title'] ?? null), 'the backup holds the feed');
        maw_assert(($backup['time_stored'] ?? 0) > 0, 'the backup records a stored-at timestamp');
    },

    'an apple lookup podcast refresh fires the filter once with its platform' => static function (): void {
        maw_queue_json(['results' => [[
            'feedUrl'           => 'https://feeds.example.com/apple.xml',
            'collectionViewUrl' => 'https://podcasts.apple.com/show/1',
        ]]]);
        maw_queue_raw(maw_ext_podcast_rss());

        maw_run_podcast_load(maw_ext_podcast_config([
            'podcast_platform' => 'omny',
            'media_data'       => '123456789',
        ]));

        maw_assert_same(1, maw_hook_count(MediaStore::FILTER_BEFORE_STORE), 'the filter fires once');

        [$data, $context] = maw_hook_args(MediaStore::FILTER_BEFORE_STORE);

        maw_assert_same('omny', $context['podcast_platform'], 'the context carries the apple platform slug');
        maw_assert_same(
            'https://podcasts.apple.com/show/1',
            $data['channel']['collectionViewUrl'] ?? null,
            'the apple collection view url is preserved'
        );
    },

    'an apple lookup that succeeds before a failed rss fetch fires no hooks' => static function (): void {
        maw_queue_json(['results' => [['feedUrl' => 'https://feeds.example.com/apple.xml']]]);
        maw_queue_error();

        $state = maw_run_podcast_load(maw_ext_podcast_config([
            'podcast_platform' => 'omny',
            'media_data'       => '123456789',
        ]));

        maw_assert_same(2, count(MawTestState::$httpRequests), 'the lookup succeeded and the rss fetch was attempted');
        maw_assert_same(true, $state['errorLoadingData'], 'the failure is reported');
        maw_ext_assert_no_hooks('when the apple lookup succeeds but the rss fetch fails');
        maw_assert_same(false, get_transient('podcast_testpod'), 'no podcast transient is written');
    },

    'a failed apple lookup fires no hooks' => static function (): void {
        maw_queue_raw('nope', 500);

        $state = maw_run_podcast_load(maw_ext_podcast_config([
            'podcast_platform' => 'omny',
            'media_data'       => '123456789',
        ]));

        maw_assert_same(true, $state['errorLoadingData'], 'the failure is reported');
        maw_ext_assert_no_hooks('when the apple lookup itself fails');
    },

    'unparseable rss fires no hooks' => static function (): void {
        maw_queue_raw('this is not xml at all');

        $state = maw_run_podcast_load(maw_ext_podcast_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the parse failure is reported');
        maw_ext_assert_no_hooks('when the rss feed cannot be parsed');
        maw_assert_same(false, get_transient('podcast_testpod'), 'no podcast transient is written');
    },

    'an embed podcast fires no hooks and still stores the raw url' => static function (): void {
        $state = maw_run_podcast_load(maw_ext_podcast_config([
            'podcast_platform' => 'embed',
            'media_data'       => 'https://player.example.com/embed/1',
        ]));

        maw_assert_same(0, count(MawTestState::$httpRequests), 'no request is made for an embed');
        maw_ext_assert_no_hooks('for an embed-only podcast, which makes no api call');
        maw_assert_same('https://player.example.com/embed/1', $state['parsedData'], 'the embed url is the parsed data');
        maw_assert_same(
            json_encode('https://player.example.com/embed/1'),
            get_transient('podcast_testpod'),
            'the embed transient format is unchanged'
        );
    },

    // -----------------------------------------------------------------------
    // Shortcode warm-up path
    // -----------------------------------------------------------------------

    'the shortcode warm up path fires both hooks with a warmup source' => static function (): void {
        maw_queue_raw(maw_ext_podcast_rss());

        $result = maw_run_podcast_warmup('testpod', maw_ext_warmup_config());

        maw_assert(is_array($result), 'the warm up returns the podcast data');
        maw_assert_same(1, maw_hook_count(MediaStore::FILTER_BEFORE_STORE), 'the filter fires once');
        maw_assert_same(1, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action fires once');

        $context = maw_hook_args(MediaStore::FILTER_BEFORE_STORE)[1] ?? [];

        maw_assert_same('shortcode_warmup', $context['source'], 'the source distinguishes the warm up path');
        maw_assert_same('testpod', $context['playlist_name'], 'the context carries the playlist name');
        maw_assert_same('podcast', $context['media_type'], 'the context carries the podcast media type');
        maw_assert_same('custom', $context['podcast_platform'], 'the context carries the podcast platform');
    },

    'the shortcode warm up returns the filtered data not the raw parse' => static function (): void {
        maw_on(MediaStore::FILTER_BEFORE_STORE, static function ($data, array $context) {
            $data['channel']['maw_test_marker'] = 'filtered';

            return $data;
        });

        maw_queue_raw(maw_ext_podcast_rss());

        $result = maw_run_podcast_warmup('testpod', maw_ext_warmup_config());

        maw_assert_same(
            'filtered',
            $result['channel']['maw_test_marker'] ?? null,
            'the warm up renders the filtered payload it stored, not the pre-filter parse'
        );
        maw_assert_same(
            'filtered',
            json_decode((string) get_transient('podcast_testpod'), true)['channel']['maw_test_marker'] ?? null,
            'the same filtered payload is what was stored'
        );
    },

    'the shortcode warm up honors the configured media cache ttl' => static function (): void {
        MawTestState::$now = 1800000000;
        MediaApiWidget\Config\Options::setCacheExpirations(['media_cache_ttl' => 111]);

        maw_queue_raw(maw_ext_podcast_rss());
        maw_run_podcast_warmup('testpod', maw_ext_warmup_config());

        maw_assert_same(
            1800000000 + 111,
            MawTestState::$transients['podcast_testpod']['expires'] ?? 0,
            'the configured ttl is used instead of a hardcoded lifetime'
        );
    },

    'the shortcode warm up writes a backup file' => static function (): void {
        maw_queue_raw(maw_ext_podcast_rss());
        maw_run_podcast_warmup('testpod', maw_ext_warmup_config());

        maw_assert(
            is_file(maw_podcast_backup_path('testpod')),
            'the warm up path now writes a backup like the refresh path does'
        );
    },

    'a rejected warm up stores nothing and returns null' => static function (): void {
        maw_on(
            MediaStore::FILTER_BEFORE_STORE,
            static fn ($data, array $context) => new WP_Error('nope', 'Refused.')
        );

        maw_queue_raw(maw_ext_podcast_rss());

        $result = maw_run_podcast_warmup('testpod', maw_ext_warmup_config());

        maw_assert_same(null, $result, 'the warm up reports failure to its caller');
        maw_assert_same(false, get_transient('podcast_testpod'), 'no transient is written');
        maw_assert_same(false, is_file(maw_podcast_backup_path('testpod')), 'no backup is written');
        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire');
    },

    'an embed warm up returns an empty array without firing hooks' => static function (): void {
        $result = maw_run_podcast_warmup('testpod', maw_ext_warmup_config(['podcast_platform' => 'embed']));

        maw_assert_same([], $result, 'the embed warm up returns an empty array');
        maw_assert_same(0, count(MawTestState::$httpRequests), 'no request is made');
        maw_ext_assert_no_hooks('for an embed platform in the warm up path');
    },

    // -----------------------------------------------------------------------
    // Validation and rejection
    // -----------------------------------------------------------------------

    'a filter returning wp_error leaves the old storage untouched' => static function (): void {
        $good = maw_ext_seed_youtube();
        delete_transient('youtube_testshow');
        set_transient('youtube_testshow', json_decode($good, true)['data'], 7200);

        maw_on(
            MediaStore::FILTER_BEFORE_STORE,
            static fn ($data, array $context) => new WP_Error('maw_test_refused', 'Refused by test.')
        );

        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the rejected refresh is reported as an error');
        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire');
        maw_ext_assert_youtube_untouched($good);
        maw_assert_same(
            'store_rejected',
            YoutubeGuard::getGuardStatus()['reason'] ?? '',
            'a store_rejected guard event is recorded for the administrator'
        );
    },

    'a filter returning an associative array is rejected' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_on(
            MediaStore::FILTER_BEFORE_STORE,
            static fn ($data, array $context) => ['unexpected' => 'shape']
        );

        maw_queue_json(maw_youtube_page(3, null, 3));

        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire');
        maw_ext_assert_youtube_untouched($good);
    },

    'a filter returning a non list array is rejected' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_on(MediaStore::FILTER_BEFORE_STORE, static function ($data, array $context) {
            return [5 => $data[0]];
        });

        maw_queue_json(maw_youtube_page(3, null, 3));

        maw_run_youtube_load(maw_youtube_config());

        maw_ext_assert_youtube_untouched($good);
    },

    'a filter returning a scalar for youtube is rejected' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_on(MediaStore::FILTER_BEFORE_STORE, static fn ($data, array $context) => 'not an array');

        maw_queue_json(maw_youtube_page(3, null, 3));

        maw_run_youtube_load(maw_youtube_config());

        maw_ext_assert_youtube_untouched($good);
    },

    'a filter returning a podcast payload without a channel is rejected' => static function (): void {
        maw_on(MediaStore::FILTER_BEFORE_STORE, static fn ($data, array $context) => ['no_channel' => true]);

        maw_queue_raw(maw_ext_podcast_rss());

        $state = maw_run_podcast_load(maw_ext_podcast_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the rejected podcast refresh is reported');
        maw_assert_same(false, get_transient('podcast_testpod'), 'no podcast transient is written');
        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire');
    },

    'a filter returning unencodable data is rejected' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_on(MediaStore::FILTER_BEFORE_STORE, static function ($data, array $context) {
            // A resource has no JSON representation, so wp_json_encode() fails.
            // Chosen over invalid UTF-8, which wp_json_encode() may silently
            // repair via _wp_json_sanity_check() rather than refuse.
            $data[0]['handle'] = fopen('php://memory', 'rb');

            return $data;
        });

        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the unencodable payload is reported as an error');
        maw_ext_assert_youtube_untouched($good);
        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire');
    },

    'a throwing filter callback becomes a rejection rather than a fatal' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_on(MediaStore::FILTER_BEFORE_STORE, static function ($data, array $context) {
            throw new RuntimeException('callback exploded with token SECRET-CALLBACK-TOKEN');
        });

        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the throw is contained and reported as an error');
        maw_ext_assert_youtube_untouched($good);
        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire');
    },

    'the refresh lock is released when a filter rejects the data' => static function (): void {
        global $wpdb;

        maw_on(
            MediaStore::FILTER_BEFORE_STORE,
            static fn ($data, array $context) => new WP_Error('nope', 'Refused.')
        );

        maw_queue_json(maw_youtube_page(3, null, 3));
        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(
            null,
            $wpdb->peek(YoutubeGuard::lockOptionName('testshow')),
            'the youtube refresh lock is released after a rejection'
        );
        maw_assert_same(
            null,
            $wpdb->peek(OptionLock::dataLockName('youtube', 'testshow')),
            'the shared storage lock is released after a rejection'
        );
    },

    'the storage lock is released when a filter throws' => static function (): void {
        global $wpdb;

        maw_on(MediaStore::FILTER_BEFORE_STORE, static function ($data, array $context) {
            throw new RuntimeException('boom');
        });

        maw_queue_json(maw_youtube_page(3, null, 3));
        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(
            null,
            $wpdb->peek(OptionLock::dataLockName('youtube', 'testshow')),
            'the shared storage lock is released even when a callback throws'
        );
        maw_assert_same(
            null,
            $wpdb->peek(YoutubeGuard::lockOptionName('testshow')),
            'the youtube refresh lock is released even when a callback throws'
        );
    },

    // -----------------------------------------------------------------------
    // Persistence failure injection
    // -----------------------------------------------------------------------

    'a failed transient write stores nothing and keeps the old backup' => static function (): void {
        $good = maw_ext_seed_youtube();

        MawTestState::$failTransientWrites = true;

        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        MawTestState::$failTransientWrites = false;

        maw_assert_same(true, $state['errorLoadingData'], 'the failed write is reported');
        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is byte-for-byte unchanged, because the transient is written first'
        );
        maw_assert_same(0, get_option('maw_yt_last_fetched_testshow', 0), 'the refresh is not finalized');
        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire');
    },

    'a false transient write with the value already stored counts as success' => static function (): void {
        // Mirrors real WordPress: set_transient() routes to update_option(),
        // which returns false when the value has not changed. Treating that as a
        // failure would reject a store that actually succeeded.
        MawTestState::$transientWriteReturnsFalse = true;

        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        MawTestState::$transientWriteReturnsFalse = false;

        maw_assert_same(false, $state['errorLoadingData'], 'the store is treated as successful');
        maw_assert_same(3, count(get_transient('youtube_testshow')), 'the transient holds the fresh playlist');
        maw_assert_same(1, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action fires');
        maw_assert(get_option('maw_yt_last_fetched_testshow', 0) > 0, 'the refresh is finalized');
    },

    'a failed backup write reports failure but still renders the filtered data' => static function (): void {
        $good = maw_ext_seed_youtube();

        maw_queue_json(maw_youtube_page(3, null, 3));

        $dir = maw_lock_backup_dir();
        try {
            $state = maw_run_youtube_load(maw_youtube_config());
        } finally {
            maw_unlock_backup_dir($dir);
        }

        maw_assert_same(true, $state['errorLoadingData'], 'the incomplete store is reported as an error');
        maw_assert_same(3, count($state['parsedData']), 'the accepted data is still rendered for this response');
        maw_assert_same(3, count(get_transient('youtube_testshow')), 'the transient holds the accepted data');
        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the previous backup is intact rather than truncated'
        );
        maw_assert_same(0, get_option('maw_yt_last_fetched_testshow', 0), 'the refresh is not finalized, so it retries');
        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire twice on retry');
    },

    'no temporary backup files are left behind' => static function (): void {
        maw_queue_json(maw_youtube_page(3, null, 3));
        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(
            [],
            glob(BackupFiles::directory() . '*.tmp.json') ?: [],
            'the atomic write leaves no temporary file behind'
        );
        maw_assert(
            is_array(json_decode((string) file_get_contents(maw_backup_path('testshow')), true)),
            'the backup file holds complete, valid json'
        );
    },

    // -----------------------------------------------------------------------
    // Post-storage action
    // -----------------------------------------------------------------------

    'the stored action receives the filtered data and the same context' => static function (): void {
        maw_on(MediaStore::FILTER_BEFORE_STORE, static function ($data, array $context) {
            $data[0]['transcript_status'] = 'pending';

            return $data;
        });

        maw_queue_json(maw_youtube_page(2, null, 2));
        maw_run_youtube_load(maw_youtube_config());

        [$storedData, $storedContext] = maw_hook_args(MediaStore::ACTION_STORED);
        $filterContext                = maw_hook_args(MediaStore::FILTER_BEFORE_STORE)[1] ?? [];

        maw_assert_same('pending', $storedData[0]['transcript_status'] ?? null, 'the action receives filtered data');
        maw_assert_same(
            wp_json_encode($filterContext),
            wp_json_encode($storedContext),
            'the action context matches the filter context exactly'
        );
    },

    'a throwing action callback leaves the store successful' => static function (): void {
        add_action(MediaStore::ACTION_STORED, static function ($data, array $context): void {
            throw new RuntimeException('listener exploded');
        }, 10, 2);

        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(false, $state['errorLoadingData'], 'a post-commit throw does not fail the store');
        maw_assert_same(3, count(get_transient('youtube_testshow')), 'the data is still stored');
        maw_assert(
            get_option('maw_yt_last_fetched_testshow', 0) > 0,
            'the refresh still finalizes, so the next request is not forced to retry'
        );
        maw_assert_same(
            null,
            YoutubeGuard::getGuardStatus(),
            'a misbehaving listener does not surface as a guard event'
        );
    },

    // -----------------------------------------------------------------------
    // The global stored-data updater
    // -----------------------------------------------------------------------

    'the documented transcript example updates a stored youtube episode' => static function (): void {
        maw_ext_seed_youtube();

        $result = media_api_widget_update_stored_data(
            'testshow',
            'youtube',
            static function ($data, array $context) {
                foreach ($data as &$item) {
                    if (($item['id'] ?? '') === 'storedvid') {
                        $item['transcript_status'] = 'complete';
                        $item['transcript_url'] = 'https://example.com/transcripts/storedvid';
                    }
                }
                unset($item);

                return $data;
            }
        );

        maw_assert_same(false, is_wp_error($result), 'the update succeeds');
        maw_assert_same('complete', $result[0]['transcript_status'] ?? null, 'the updated data is returned');
        maw_assert_same(
            'complete',
            get_transient('youtube_testshow')[0]['transcript_status'] ?? null,
            'the transient is updated'
        );
        maw_assert_same(
            'https://example.com/transcripts/storedvid',
            json_decode((string) file_get_contents(maw_backup_path('testshow')), true)['data'][0]['transcript_url'] ?? null,
            'the backup file is updated in step with the transient'
        );
        maw_assert_same(0, count(MawTestState::$httpRequests), 'no external request is made');
    },

    'the updater fires the stored action with a manual update source and no filter' => static function (): void {
        maw_ext_seed_youtube();

        media_api_widget_update_stored_data('testshow', 'youtube', static fn ($data, array $context) => $data);

        maw_assert_same(
            0,
            maw_hook_count(MediaStore::FILTER_BEFORE_STORE),
            'the remote-refresh filter is not re-entered, so an enrichment write cannot loop'
        );
        maw_assert_same(1, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action fires once');
        maw_assert_same(
            'manual_update',
            maw_hook_args(MediaStore::ACTION_STORED)[1]['source'] ?? null,
            'the source distinguishes a manual update from a remote refresh'
        );
    },

    'the updater refreshes the backup time_stored value' => static function (): void {
        maw_ext_seed_youtube();

        media_api_widget_update_stored_data('testshow', 'youtube', static fn ($data, array $context) => $data);

        $storedAt = json_decode((string) file_get_contents(maw_backup_path('testshow')), true)['time_stored'] ?? 0;

        maw_assert($storedAt > 1700000000, 'time_stored is refreshed when the backup is rewritten');
    },

    'the updater falls back to the backup file when the transient is gone' => static function (): void {
        maw_ext_seed_youtube();
        delete_transient('youtube_testshow');

        $result = media_api_widget_update_stored_data(
            'testshow',
            'youtube',
            static function ($data, array $context) {
                $data[0]['from_backup'] = true;

                return $data;
            }
        );

        maw_assert_same(false, is_wp_error($result), 'the update succeeds from the backup file');
        maw_assert_same('storedvid', $result[0]['id'] ?? null, 'the backup payload was read');
        maw_assert_same(
            true,
            get_transient('youtube_testshow')[0]['from_backup'] ?? null,
            'the transient is repopulated with the updated data'
        );
        maw_assert_same(0, count(MawTestState::$httpRequests), 'no external request is made');
    },

    'the updater preserves arbitrary custom item keys' => static function (): void {
        $data = [[
            'title'          => 'Stored Episode',
            'id'             => 'storedvid',
            'vendor_payload' => ['a' => [1, 2, 3], 'b' => 'keep me'],
        ]];
        set_transient('youtube_testshow', $data, 7200);

        $result = media_api_widget_update_stored_data(
            'testshow',
            'youtube',
            static function ($items, array $context) {
                $items[0]['transcript_status'] = 'pending';

                return $items;
            }
        );

        maw_assert_same([1, 2, 3], $result[0]['vendor_payload']['a'] ?? null, 'nested custom arrays survive');
        maw_assert_same('keep me', $result[0]['vendor_payload']['b'] ?? null, 'custom scalars survive');
        maw_assert_same(
            'keep me',
            get_transient('youtube_testshow')[0]['vendor_payload']['b'] ?? null,
            'custom keys survive into storage'
        );
    },

    'the updater keeps the podcast json string storage format' => static function (): void {
        // The manual-update context resolves podcast_platform from the admin
        // config, since a manual update has no refresh config to read it from.
        MediaApiWidget\Config\Options::setMediaItems([[
            'type'             => 'podcast',
            'playlist_name'    => 'testpod',
            'podcast_platform' => 'custom',
            'media_data'       => 'https://feeds.example.com/podcast.xml',
        ]]);

        maw_queue_raw(maw_ext_podcast_rss());
        maw_run_podcast_load(maw_ext_podcast_config());

        $result = media_api_widget_update_stored_data(
            'testpod',
            'podcast',
            static function ($data, array $context) {
                $data['channel']['item'][0]['transcript_status'] = 'complete';

                return $data;
            }
        );

        maw_assert_same(false, is_wp_error($result), 'the podcast update succeeds');

        $stored = get_transient('podcast_testpod');

        maw_assert_same(true, is_string($stored), 'the podcast transient is still a JSON string');
        maw_assert_same(
            'complete',
            json_decode($stored, true)['channel']['item'][0]['transcript_status'] ?? null,
            'the episode was updated inside the stored JSON'
        );
        maw_assert_same(
            'custom',
            maw_hook_args(MediaStore::ACTION_STORED, 1)[1]['podcast_platform'] ?? null,
            'the manual-update context carries the podcast platform'
        );
    },

    'the updater rejects an unsupported media type without calling the mutator' => static function (): void {
        $called = false;

        $result = media_api_widget_update_stored_data('testshow', 'vimeo', static function ($data, array $c) use (&$called) {
            $called = true;

            return $data;
        });

        maw_assert_same(true, is_wp_error($result), 'an unsupported media type is refused');
        maw_assert_same('maw_unsupported_media_type', $result->get_error_code(), 'the error code names the problem');
        maw_assert_same(false, $called, 'the mutator is never invoked');
    },

    'the updater rejects an unusable playlist name without calling the mutator' => static function (): void {
        $called = false;

        $result = media_api_widget_update_stored_data('!!!', 'youtube', static function ($data, array $c) use (&$called) {
            $called = true;

            return $data;
        });

        maw_assert_same(true, is_wp_error($result), 'an unsanitizable playlist name is refused');
        maw_assert_same('maw_invalid_playlist_name', $result->get_error_code(), 'the error code names the problem');
        maw_assert_same(false, $called, 'the mutator is never invoked');
    },

    'the updater reports missing stored data and writes nothing' => static function (): void {
        $called = false;

        $result = media_api_widget_update_stored_data('missingshow', 'youtube', static function ($d, array $c) use (&$called) {
            $called = true;

            return $d;
        });

        maw_assert_same(true, is_wp_error($result), 'the update fails');
        maw_assert_same('maw_no_stored_data', $result->get_error_code(), 'the error code names the problem');
        maw_assert_same(false, $called, 'the mutator is never invoked');
        maw_assert_same(false, is_file(maw_backup_path('missingshow')), 'no backup file is created');
        maw_assert_same(false, get_transient('youtube_missingshow'), 'no transient is created');
        maw_assert_same(0, count(MawTestState::$httpRequests), 'no external request is made');
    },

    'the updater refuses an embed only podcast before calling the mutator' => static function (): void {
        maw_run_podcast_load(maw_ext_podcast_config([
            'podcast_platform' => 'embed',
            'media_data'       => 'https://player.example.com/embed/1',
        ]));

        $called = false;

        $result = media_api_widget_update_stored_data('testpod', 'podcast', static function ($d, array $c) use (&$called) {
            $called = true;

            return $d;
        });

        maw_assert_same(true, is_wp_error($result), 'an embed-only playlist cannot be enriched');
        maw_assert_same('maw_unsupported_podcast_payload', $result->get_error_code(), 'the error code names the problem');
        maw_assert_same(false, $called, 'the mutator is never invoked, so it cannot cause side effects');
        maw_assert_same(
            json_encode('https://player.example.com/embed/1'),
            get_transient('podcast_testpod'),
            'the stored embed url is unchanged'
        );
    },

    'a mutator returning wp_error leaves storage untouched' => static function (): void {
        $good = maw_ext_seed_youtube();

        $result = media_api_widget_update_stored_data(
            'testshow',
            'youtube',
            static fn ($data, array $context) => new WP_Error('maw_test_abort', 'Not ready yet.')
        );

        maw_assert_same(true, is_wp_error($result), 'the mutator error is returned to the caller');
        maw_assert_same('maw_test_abort', $result->get_error_code(), 'the callback own error code is preserved');
        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is byte-for-byte unchanged'
        );
        maw_assert_same(
            'storedvid',
            get_transient('youtube_testshow')[0]['id'] ?? null,
            'the transient is unchanged'
        );
        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire');
    },

    'a mutator returning an invalid shape leaves storage untouched' => static function (): void {
        $good = maw_ext_seed_youtube();

        $result = media_api_widget_update_stored_data(
            'testshow',
            'youtube',
            static fn ($data, array $context) => ['not' => 'a list']
        );

        maw_assert_same(true, is_wp_error($result), 'an invalid shape is refused');
        maw_assert_same('maw_invalid_youtube_data', $result->get_error_code(), 'the error code names the problem');
        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is byte-for-byte unchanged'
        );
        maw_assert_same('storedvid', get_transient('youtube_testshow')[0]['id'] ?? null, 'the transient is unchanged');
    },

    'a mutator returning unencodable data leaves storage untouched' => static function (): void {
        $good = maw_ext_seed_youtube();

        $result = media_api_widget_update_stored_data(
            'testshow',
            'youtube',
            static function ($data, array $context) {
                $data[0]['handle'] = fopen('php://memory', 'rb');

                return $data;
            }
        );

        maw_assert_same(true, is_wp_error($result), 'an unencodable payload is refused');
        maw_assert_same('maw_json_encode_failed', $result->get_error_code(), 'the error code names the problem');
        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is byte-for-byte unchanged'
        );
    },

    'a throwing mutator is contained and leaks no exception text' => static function (): void {
        $good = maw_ext_seed_youtube();

        $result = media_api_widget_update_stored_data(
            'testshow',
            'youtube',
            static function ($data, array $context) {
                throw new RuntimeException('failed calling provider with key SECRET-PROVIDER-KEY');
            }
        );

        maw_assert_same(true, is_wp_error($result), 'the throw is converted to a WP_Error');
        maw_assert_same('maw_mutator_threw', $result->get_error_code(), 'the error code names the problem');
        maw_assert(
            !str_contains($result->get_error_message(), 'SECRET-PROVIDER-KEY'),
            'the exception message is not echoed back, so callback credentials cannot leak'
        );
        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is byte-for-byte unchanged'
        );
        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire');
    },

    // -----------------------------------------------------------------------
    // The shared storage lock
    // -----------------------------------------------------------------------

    'a held storage lock blocks the updater without calling the mutator' => static function (): void {
        maw_ext_seed_youtube();

        OptionLock::acquire(OptionLock::dataLockName('youtube', 'testshow'), 30);

        $called = false;

        $result = media_api_widget_update_stored_data('testshow', 'youtube', static function ($d, array $c) use (&$called) {
            $called = true;

            return $d;
        });

        maw_assert_same(true, is_wp_error($result), 'the update is refused while another writer holds the lock');
        maw_assert_same('maw_data_locked', $result->get_error_code(), 'the error code names the problem');
        maw_assert_same(false, $called, 'the mutator is never invoked');
        maw_assert_same(
            'storedvid',
            get_transient('youtube_testshow')[0]['id'] ?? null,
            'the stored data is unchanged'
        );
    },

    'a held storage lock blocks a refresh from overwriting stored data' => static function (): void {
        $good = maw_ext_seed_youtube();

        OptionLock::acquire(OptionLock::dataLockName('youtube', 'testshow'), 30);

        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the refresh cannot store and reports an error');
        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'a refresh and a manual update cannot interleave their writes'
        );
        maw_assert_same(0, maw_hook_count(MediaStore::ACTION_STORED), 'the stored action does not fire');
    },

    'the storage lock is released after a successful update' => static function (): void {
        global $wpdb;

        maw_ext_seed_youtube();

        media_api_widget_update_stored_data('testshow', 'youtube', static fn ($data, array $context) => $data);

        maw_assert_same(
            null,
            $wpdb->peek(OptionLock::dataLockName('youtube', 'testshow')),
            'the storage lock is released on success'
        );
    },

    'the storage lock is released after a throwing mutator' => static function (): void {
        global $wpdb;

        maw_ext_seed_youtube();

        media_api_widget_update_stored_data('testshow', 'youtube', static function ($data, array $context) {
            throw new RuntimeException('boom');
        });

        maw_assert_same(
            null,
            $wpdb->peek(OptionLock::dataLockName('youtube', 'testshow')),
            'the storage lock is released even when the mutator throws'
        );
    },

    'the storage lock is released after a wp_error mutator' => static function (): void {
        global $wpdb;

        maw_ext_seed_youtube();

        media_api_widget_update_stored_data(
            'testshow',
            'youtube',
            static fn ($data, array $context) => new WP_Error('nope', 'Refused.')
        );

        maw_assert_same(
            null,
            $wpdb->peek(OptionLock::dataLockName('youtube', 'testshow')),
            'the storage lock is released when the mutator returns an error'
        );
    },

    'the storage lock does not collide with the youtube refresh lock' => static function (): void {
        maw_ext_seed_youtube();

        // A refresh being in flight must not stop an enrichment write: the two
        // locks are deliberately separate namespaces, so a routine API update
        // does not read as a concurrent_refresh guard event.
        YoutubeGuard::acquireLock('testshow', 600);

        $result = media_api_widget_update_stored_data(
            'testshow',
            'youtube',
            static function ($data, array $context) {
                $data[0]['transcript_status'] = 'pending';

                return $data;
            }
        );

        maw_assert_same(false, is_wp_error($result), 'the update succeeds while a refresh lock is held');
        maw_assert_same('pending', $result[0]['transcript_status'] ?? null, 'the update was applied');
        maw_assert_same(null, YoutubeGuard::getGuardStatus(), 'no guard event is recorded for a normal update');
    },

    'the storage lock is per media type and per playlist' => static function (): void {
        maw_ext_seed_youtube();

        OptionLock::acquire(OptionLock::dataLockName('podcast', 'testshow'), 30);
        OptionLock::acquire(OptionLock::dataLockName('youtube', 'othershow'), 30);

        $result = media_api_widget_update_stored_data('testshow', 'youtube', static fn ($data, array $c) => $data);

        maw_assert_same(
            false,
            is_wp_error($result),
            'a lock on another media type or playlist does not block this one'
        );
    },

    'a refresh after an update leaves the transient and backup in agreement' => static function (): void {
        maw_ext_seed_youtube();

        media_api_widget_update_stored_data('testshow', 'youtube', static function ($data, array $context) {
            $data[0]['transcript_status'] = 'complete';

            return $data;
        });

        maw_queue_json(maw_youtube_page(3, null, 3));
        maw_run_youtube_load(maw_youtube_config());

        $transient = get_transient('youtube_testshow');
        $backup    = json_decode((string) file_get_contents(maw_backup_path('testshow')), true)['data'];

        maw_assert_same(
            wp_json_encode($transient),
            wp_json_encode($backup),
            'serialized writes leave the two stores agreeing, never torn'
        );
    },

    // -----------------------------------------------------------------------
    // The read-only global getter
    // -----------------------------------------------------------------------

    'the getter reads stored youtube data without a request' => static function (): void {
        maw_ext_seed_youtube();

        $data = media_api_widget_get_stored_data('testshow', 'youtube');

        maw_assert_same('storedvid', $data[0]['id'] ?? null, 'the stored playlist is returned');
        maw_assert_same(0, count(MawTestState::$httpRequests), 'no external request is made');
        maw_ext_assert_no_hooks('when only reading stored data');
    },

    'the getter falls back to the backup file and refuses bad input' => static function (): void {
        maw_ext_seed_youtube();
        delete_transient('youtube_testshow');

        maw_assert_same(
            'storedvid',
            media_api_widget_get_stored_data('testshow', 'youtube')[0]['id'] ?? null,
            'the backup file is read when the transient is gone'
        );
        maw_assert_same(null, media_api_widget_get_stored_data('testshow', 'vimeo'), 'an unsupported type returns null');
        maw_assert_same(null, media_api_widget_get_stored_data('!!!', 'youtube'), 'an unusable name returns null');
        maw_assert_same(null, media_api_widget_get_stored_data('nothingstored', 'youtube'), 'no data returns null');
    },
];
