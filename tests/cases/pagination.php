<?php
/**
 * Pagination safety tests for the YouTube playlistItems fetch.
 *
 * Every case here maps to a way the previous loop could either run away or
 * silently truncate a playlist.
 */

declare(strict_types=1);

use MediaApiWidget\Support\YoutubeGuard;

return [

    'single page playlist fetches exactly one request' => static function (): void {
        maw_queue_json(maw_youtube_page(6, null, 6));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(1, count(MawTestState::$httpRequests), 'exactly one HTTP request is made');
        maw_assert_same(false, $state['errorLoadingData'], 'no error is reported');
        maw_assert_same(6, count($state['parsedData']), 'all six items are parsed');
        maw_assert_same('Episode 1', $state['parsedData'][0]['title'], 'first item title is parsed');
        maw_assert_same('vid00001', $state['parsedData'][0]['id'], 'video id is parsed');
        maw_assert_same(null, YoutubeGuard::getGuardStatus(), 'no guard event is recorded');
    },

    '400 item playlist completes in exactly eight pages' => static function (): void {
        for ($page = 0; $page < 8; $page++) {
            maw_queue_json(maw_youtube_page(
                50,
                $page < 7 ? 'TOKEN-' . ($page + 1) : null,
                400,
                $page * 50
            ));
        }

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(8, count(MawTestState::$httpRequests), 'exactly eight HTTP requests are made');
        maw_assert_same(false, $state['errorLoadingData'], 'no error is reported');
        maw_assert_same(400, count($state['parsedData']), 'all 400 items are parsed');
        maw_assert_same('Episode 400', $state['parsedData'][399]['title'], 'the last item of the last page is present');
        maw_assert_same(null, YoutubeGuard::getGuardStatus(), 'no guard event is recorded');

        // Page tokens must be sent, in order, and only from page 2 onwards.
        maw_assert(
            !str_contains(MawTestState::$httpRequests[0], 'pageToken'),
            'page 1 carries no pageToken'
        );
        maw_assert(
            str_contains(MawTestState::$httpRequests[1], 'pageToken=TOKEN-1'),
            'page 2 carries the token from page 1'
        );
        maw_assert(
            str_contains(MawTestState::$httpRequests[7], 'pageToken=TOKEN-7'),
            'page 8 carries the token from page 7'
        );
    },

    'pagination stops when nextPageToken is absent' => static function (): void {
        maw_queue_json(maw_youtube_page(50, 'TOKEN-1', 500, 0));
        // Page 2 reports far more totalResults than it delivers, but supplies no
        // token. The old loop kept going on totalResults; this one must stop.
        maw_queue_json(maw_youtube_page(10, null, 500, 50));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(2, count(MawTestState::$httpRequests), 'only two requests are made');
        maw_assert_same(false, $state['errorLoadingData'], 'the refresh succeeds');
        maw_assert_same(60, count($state['parsedData']), 'both pages of items are kept');
    },

    'a repeated nextPageToken aborts the refresh' => static function (): void {
        maw_queue_json(maw_youtube_page(50, 'LOOP', 4000, 0));
        maw_queue_json(maw_youtube_page(50, 'LOOP', 4000, 50));
        // A third response is queued to prove it is never requested.
        maw_queue_json(maw_youtube_page(50, 'LOOP', 4000, 100));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(2, count(MawTestState::$httpRequests), 'the repeated token is never re-requested');
        maw_assert_same(true, $state['errorLoadingData'], 'the refresh is reported as failed');
        maw_assert_same([], $state['parsedData'], 'no partial data is returned');

        $guard = YoutubeGuard::getGuardStatus();
        maw_assert_same('repeated_page_token', $guard['reason'] ?? '', 'a repeated_page_token event is recorded');
        maw_assert_same(2, $guard['pages'] ?? 0, 'the event records two pages requested');
    },

    'an empty items page with another token aborts the refresh' => static function (): void {
        maw_queue_json(maw_youtube_page(50, 'TOKEN-1', 4000, 0));
        maw_queue_json(maw_youtube_page(0, 'TOKEN-2', 4000, 50));
        maw_queue_json(maw_youtube_page(50, 'TOKEN-3', 4000, 50));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(2, count(MawTestState::$httpRequests), 'the loop stops at the empty page');
        maw_assert_same(true, $state['errorLoadingData'], 'the refresh is reported as failed');
        maw_assert_same(
            'empty_page_with_next_token',
            YoutubeGuard::getGuardStatus()['reason'] ?? '',
            'an empty_page_with_next_token event is recorded'
        );
    },

    'malformed JSON aborts the refresh' => static function (): void {
        maw_queue_raw('{"items": [', 200);

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(1, count(MawTestState::$httpRequests), 'only the first request is made');
        maw_assert_same(true, $state['errorLoadingData'], 'the refresh is reported as failed');
        maw_assert_same([], $state['parsedData'], 'no data is returned');
        maw_assert_same(
            'malformed_response',
            YoutubeGuard::getGuardStatus()['reason'] ?? '',
            'a malformed_response event is recorded'
        );
    },

    'a missing items key aborts the refresh' => static function (): void {
        maw_queue_json(['pageInfo' => ['totalResults' => 10, 'resultsPerPage' => 50]]);

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the refresh is reported as failed');
        maw_assert_same(
            'malformed_response',
            YoutubeGuard::getGuardStatus()['reason'] ?? '',
            'a malformed_response event is recorded'
        );
    },

    'a missing pageInfo key aborts the refresh' => static function (): void {
        maw_queue_json(['items' => [], 'kind' => 'youtube#playlistItemListResponse']);

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the refresh is reported as failed');
        maw_assert_same(
            'malformed_response',
            YoutubeGuard::getGuardStatus()['reason'] ?? '',
            'a malformed_response event is recorded'
        );
    },

    'an HTTP error on a later page aborts the refresh' => static function (): void {
        maw_queue_json(maw_youtube_page(50, 'TOKEN-1', 200, 0));
        maw_queue_json(maw_youtube_page(50, 'TOKEN-2', 200, 50));
        maw_queue_raw('{"error":{"code":403}}', 403);

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(3, count(MawTestState::$httpRequests), 'the failing page is the last request');
        maw_assert_same(true, $state['errorLoadingData'], 'the refresh is reported as failed');
        maw_assert_same([], $state['parsedData'], 'the 100 items already collected are discarded');

        $guard = YoutubeGuard::getGuardStatus();
        maw_assert_same('http_error', $guard['reason'] ?? '', 'an http_error event is recorded');
        maw_assert_same(3, $guard['pages'] ?? 0, 'the event records three pages requested');
    },

    'a WP_Error on a later page aborts the refresh' => static function (): void {
        maw_queue_json(maw_youtube_page(50, 'TOKEN-1', 200, 0));
        maw_queue_error();

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(2, count(MawTestState::$httpRequests), 'no further pages are attempted');
        maw_assert_same(true, $state['errorLoadingData'], 'the refresh is reported as failed');
        maw_assert_same(
            'http_error',
            YoutubeGuard::getGuardStatus()['reason'] ?? '',
            'an http_error event is recorded'
        );
    },

    'the maximum page guard caps requests at the configured ceiling' => static function (): void {
        // Every page offers a fresh token, so only the page ceiling can stop this.
        for ($page = 0; $page < 40; $page++) {
            maw_queue_json(maw_youtube_page(50, 'TOKEN-' . $page, 100000, $page * 50));
        }

        $state = maw_run_youtube_load(maw_youtube_config(['youtube_max_pages_per_refresh' => 5]));

        maw_assert_same(5, count(MawTestState::$httpRequests), 'exactly five requests are made');
        maw_assert_same(true, $state['errorLoadingData'], 'the refresh is reported as failed');
        maw_assert_same([], $state['parsedData'], 'no partial playlist is returned');

        $guard = YoutubeGuard::getGuardStatus();
        maw_assert_same('maximum_pages_reached', $guard['reason'] ?? '', 'a maximum_pages_reached event is recorded');
        maw_assert_same(5, $guard['pages'] ?? 0, 'the event records five pages requested');
    },

    'the default page ceiling is 20' => static function (): void {
        for ($page = 0; $page < 60; $page++) {
            maw_queue_json(maw_youtube_page(50, 'TOKEN-' . $page, 100000, $page * 50));
        }

        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(20, count(MawTestState::$httpRequests), 'the default ceiling stops the loop at 20 requests');
    },

    'the api key is never written into diagnostics' => static function (): void {
        maw_queue_raw('not json at all', 200);

        maw_run_youtube_load(maw_youtube_config(['api_key' => 'SECRET-API-KEY-DO-NOT-LEAK']));

        $guard = YoutubeGuard::getGuardStatus();
        $dump  = (string) json_encode($guard);

        maw_assert(
            !str_contains($dump, 'SECRET-API-KEY-DO-NOT-LEAK'),
            'the guard event contains no API key'
        );
        maw_assert(
            !str_contains($dump, 'youtube.googleapis.com'),
            'the guard event contains no request URL'
        );
        maw_assert(
            !str_contains($dump, 'not json at all'),
            'the guard event contains no response body'
        );
        maw_assert_same(
            ['reason', 'playlist', 'pages', 'timestamp'],
            array_keys($guard ?? []),
            'the guard event holds only reason, playlist, pages, and timestamp'
        );
    },

    'page tokens and ids are url encoded' => static function (): void {
        maw_queue_json(maw_youtube_page(1, 'a b&c=d', 2, 0));
        maw_queue_json(maw_youtube_page(1, null, 2, 1));

        maw_run_youtube_load(maw_youtube_config(['media_data' => 'PL&evil=1']));

        maw_assert(
            str_contains(MawTestState::$httpRequests[0], 'playlistId=PL%26evil%3D1'),
            'the playlist id is rawurlencoded'
        );
        maw_assert(
            str_contains(MawTestState::$httpRequests[1], 'pageToken=a%20b%26c%3Dd'),
            'the page token is rawurlencoded'
        );
    },

    'sorting and trimming behave exactly as before' => static function (): void {
        maw_queue_json(maw_youtube_page(12, null, 12));

        $state = maw_run_youtube_load(maw_youtube_config([
            'sort_mode'          => 'number_in_title',
            'load_full_playlist' => false,
        ]));

        maw_assert_same(6, count($state['parsedData']), 'the list is trimmed to six items');
        maw_assert_same('Episode 12', $state['parsedData'][0]['title'], 'the highest episode number sorts first');
        maw_assert_same(12, $state['parsedData'][0]['episode'], 'the episode number is parsed from the title');
        maw_assert_same('Episode 7', $state['parsedData'][5]['title'], 'the sixth item is episode 7');
    },

    'an item without a snippet aborts the render' => static function (): void {
        maw_queue_json([
            'items'    => [['id' => 'no-snippet-here']],
            'pageInfo' => ['totalResults' => 1, 'resultsPerPage' => 50],
        ]);

        // The parse loop reads $item['snippet'] unguarded, exactly as it did
        // before this change, so a missing key emits a warning on the way to the
        // abort. Silenced here to keep the suite output readable.
        $previous = error_reporting(E_ALL & ~E_WARNING);
        $state    = maw_run_youtube_load(maw_youtube_config());
        error_reporting($previous);

        maw_assert_same(true, $state['abort'], 'the render is aborted');
        maw_assert_same(false, $state['errorLoadingData'], 'the abort is not reported as a load error');
        maw_assert_same(false, get_transient('youtube_testshow'), 'no transient is written');
    },
];
