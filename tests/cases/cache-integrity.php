<?php
/**
 * Tests that a partial or failed refresh never replaces good stored data.
 *
 * This is the second half of the production incident: a later page failing did
 * not stop the old code from writing the truncated playlist over the backup
 * file, the transient, and the last-fetched timestamp.
 */

declare(strict_types=1);

use MediaApiWidget\Support\YoutubeGuard;

/**
 * Writes a known-good backup file for the test playlist.
 *
 * @param string $playlistName Playlist slug.
 * @return string The JSON that was written.
 */
function maw_seed_good_backup(string $playlistName = 'testshow'): string
{
    $json = (string) json_encode([
        'time_stored' => 1700000000,
        'data'        => [
            ['title' => 'Known Good Episode', 'episode' => -1, 'id' => 'goodvid', 'thumbnail' => ['url' => 'https://example.com/good.jpg'], 'publishedDate' => '2025-01-01T00:00:00Z', 'description' => 'good'],
        ],
    ]);

    file_put_contents(maw_backup_path($playlistName), $json);

    return $json;
}

return [

    'a successful refresh replaces the backup, transient, and timestamp' => static function (): void {
        maw_seed_good_backup();
        maw_queue_json(maw_youtube_page(3, null, 3));

        $state = maw_run_youtube_load(maw_youtube_config());

        $backup = json_decode((string) file_get_contents(maw_backup_path('testshow')), true);

        maw_assert_same(3, count($backup['data']), 'the backup now holds the fresh playlist');
        maw_assert_same(3, count(get_transient('youtube_testshow')), 'the transient holds the fresh playlist');
        maw_assert(get_option('maw_yt_last_fetched_testshow', 0) > 0, 'the last-fetched timestamp is set');
        maw_assert_same(false, $state['errorLoadingData'], 'no error is reported');
    },

    'an http error on a later page leaves the good backup intact' => static function (): void {
        $good = maw_seed_good_backup();

        maw_queue_json(maw_youtube_page(50, 'TOKEN-1', 200, 0));
        maw_queue_json(maw_youtube_page(50, 'TOKEN-2', 200, 50));
        maw_queue_error();

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is byte-for-byte unchanged'
        );
        maw_assert_same(false, get_transient('youtube_testshow'), 'no partial transient is written');
        maw_assert_same(0, get_option('maw_yt_last_fetched_testshow', 0), 'the last-fetched timestamp is not set');
        maw_assert_same(true, $state['errorLoadingData'], 'the failure is reported so the backup fallback runs');
    },

    'a repeated page token leaves the good backup intact' => static function (): void {
        $good = maw_seed_good_backup();

        maw_queue_json(maw_youtube_page(50, 'LOOP', 4000, 0));
        maw_queue_json(maw_youtube_page(50, 'LOOP', 4000, 50));

        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is unchanged'
        );
        maw_assert_same(false, get_transient('youtube_testshow'), 'no partial transient is written');
        maw_assert_same(0, get_option('maw_yt_last_fetched_testshow', 0), 'the last-fetched timestamp is not set');
    },

    'a page-limit abort leaves the good backup intact' => static function (): void {
        $good = maw_seed_good_backup();

        for ($page = 0; $page < 10; $page++) {
            maw_queue_json(maw_youtube_page(50, 'TOKEN-' . $page, 100000, $page * 50));
        }

        maw_run_youtube_load(maw_youtube_config(['youtube_max_pages_per_refresh' => 4]));

        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is unchanged'
        );
        maw_assert_same(false, get_transient('youtube_testshow'), 'no partial transient is written');
        maw_assert_same(0, get_option('maw_yt_last_fetched_testshow', 0), 'the last-fetched timestamp is not set');
    },

    'a daily limit abort leaves the good backup intact' => static function (): void {
        $good = maw_seed_good_backup();

        for ($page = 0; $page < 8; $page++) {
            maw_queue_json(maw_youtube_page(50, 'TOKEN-' . $page, 400, $page * 50));
        }

        maw_run_youtube_load(maw_youtube_config(['youtube_daily_call_limit' => 2]));

        maw_assert_same(
            $good,
            (string) file_get_contents(maw_backup_path('testshow')),
            'the backup file is unchanged'
        );
        maw_assert_same(false, get_transient('youtube_testshow'), 'no partial transient is written');
        maw_assert_same(0, get_option('maw_yt_last_fetched_testshow', 0), 'the last-fetched timestamp is not set');
    },

    'a failed refresh leaves an existing valid transient untouched' => static function (): void {
        $existing = [
            ['title' => 'Cached Episode', 'episode' => -1, 'id' => 'cachedvid', 'thumbnail' => ['url' => 'https://example.com/c.jpg'], 'publishedDate' => null, 'description' => null],
        ];
        set_transient('youtube_testshow', $existing, 7200);

        maw_queue_json(maw_youtube_page(50, 'LOOP', 4000, 0));
        maw_queue_json(maw_youtube_page(50, 'LOOP', 4000, 50));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the refresh reports failure');
        maw_assert_same($existing, get_transient('youtube_testshow'), 'the existing transient is not overwritten');
        maw_assert_same(
            'repeated_page_token',
            YoutubeGuard::getGuardStatus()['reason'] ?? '',
            'the guard event survives for the admin page'
        );
    },

    'a previously stored error transient short-circuits the fetch' => static function (): void {
        set_transient('testshow_youtube_error', true, 600);
        maw_queue_json(maw_youtube_page(6, null, 6));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(0, count(MawTestState::$httpRequests), 'the back-off window is respected');
        maw_assert_same(true, $state['errorLoadingData'], 'the request falls back to stored data');
    },

    'the backup window still short-circuits the fetch' => static function (): void {
        maw_seed_good_backup();
        update_option('maw_yt_last_fetched_testshow', maw_test_time() - 60, false);

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(0, count(MawTestState::$httpRequests), 'no API call is made inside the backup window');
        maw_assert_same('backup cache (rate limit)', $state['dataLoadedMethod'], 'the backup file is served');
        maw_assert_same(1, count($state['parsedData']), 'the backup data is returned');
        maw_assert_same(false, $state['errorLoadingData'], 'serving the backup is not an error');
    },
];
