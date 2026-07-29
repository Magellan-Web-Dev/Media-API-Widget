<?php
/**
 * Daily circuit-breaker tests.
 *
 * Covers reservation before the request goes outbound, enforcement during a
 * multi-page refresh, the midnight America/Los_Angeles reset (including across
 * a daylight-saving change), and cleanup of obsolete counter rows.
 */

declare(strict_types=1);

use MediaApiWidget\Support\YoutubeGuard;

return [

    'a reservation is counted before the request is sent' => static function (): void {
        maw_assert_same(0, YoutubeGuard::getDailyCallCount(), 'the counter starts at zero');

        maw_assert_same(true, YoutubeGuard::reserveDailyCall(3), 'the first reservation succeeds');
        maw_assert_same(1, YoutubeGuard::getDailyCallCount(), 'the counter increments immediately');

        maw_assert_same(true, YoutubeGuard::reserveDailyCall(3), 'the second reservation succeeds');
        maw_assert_same(true, YoutubeGuard::reserveDailyCall(3), 'the third reservation succeeds');
        maw_assert_same(3, YoutubeGuard::getDailyCallCount(), 'three calls are counted');

        maw_assert_same(false, YoutubeGuard::reserveDailyCall(3), 'the fourth reservation is refused');
        maw_assert_same(3, YoutubeGuard::getDailyCallCount(), 'a refused reservation does not inflate the counter');
        maw_assert_same(false, YoutubeGuard::reserveDailyCall(3), 'the limit stays enforced on retry');
        maw_assert_same(3, YoutubeGuard::getDailyCallCount(), 'repeated refusals still do not inflate the counter');
    },

    'the limit stops a refresh part-way through pagination' => static function (): void {
        for ($page = 0; $page < 8; $page++) {
            maw_queue_json(maw_youtube_page(50, 'TOKEN-' . $page, 400, $page * 50));
        }

        $state = maw_run_youtube_load(maw_youtube_config(['youtube_daily_call_limit' => 3]));

        maw_assert_same(3, count(MawTestState::$httpRequests), 'only three requests go outbound');
        maw_assert_same(true, $state['errorLoadingData'], 'the refresh is reported as failed');
        maw_assert_same([], $state['parsedData'], 'no partial playlist is returned');
        maw_assert_same(
            'daily_limit_reached',
            YoutubeGuard::getGuardStatus()['reason'] ?? '',
            'a daily_limit_reached event is recorded'
        );
    },

    'a blocked request is not logged as an api call' => static function (): void {
        for ($page = 0; $page < 8; $page++) {
            maw_queue_json(maw_youtube_page(50, 'TOKEN-' . $page, 400, $page * 50));
        }

        maw_run_youtube_load(maw_youtube_config(['youtube_daily_call_limit' => 3]));

        maw_assert_same(3, count(MawTestState::$apiLog), 'exactly three api call rows are written');
        maw_assert_same(3, count(MawTestState::$httpRequests), 'the log row count matches the request count');
    },

    'no request is sent at all once the budget is spent' => static function (): void {
        YoutubeGuard::reserveDailyCall(2);
        YoutubeGuard::reserveDailyCall(2);

        maw_queue_json(maw_youtube_page(6, null, 6));

        $state = maw_run_youtube_load(maw_youtube_config(['youtube_daily_call_limit' => 2]));

        maw_assert_same(0, count(MawTestState::$httpRequests), 'nothing goes outbound');
        maw_assert_same(0, count(MawTestState::$apiLog), 'nothing is logged');
        maw_assert_same(true, $state['errorLoadingData'], 'the refresh is reported as failed');
        maw_assert_same(
            'daily_limit_reached',
            YoutubeGuard::getGuardStatus()['reason'] ?? '',
            'a daily_limit_reached event is recorded'
        );
    },

    'the counter key rolls over at midnight america/los_angeles' => static function (): void {
        // 2026-07-15 06:59:00 UTC is 2026-07-14 23:59 PDT (UTC-7).
        $beforeMidnight = (int) strtotime('2026-07-15 06:59:00 UTC');
        // One minute later is 2026-07-15 00:00 PDT — a new quota day.
        $afterMidnight = $beforeMidnight + 60;

        maw_assert_same(
            'maw_yt_calls_20260714',
            YoutubeGuard::dailyCounterOptionName($beforeMidnight),
            'just before local midnight the counter is still on the 14th'
        );
        maw_assert_same(
            'maw_yt_calls_20260715',
            YoutubeGuard::dailyCounterOptionName($afterMidnight),
            'at local midnight the counter moves to the 15th'
        );

        maw_assert_same(true, YoutubeGuard::reserveDailyCall(1, $beforeMidnight), 'the last slot of the 14th is used');
        maw_assert_same(false, YoutubeGuard::reserveDailyCall(1, $beforeMidnight), 'the 14th is now exhausted');
        maw_assert_same(true, YoutubeGuard::reserveDailyCall(1, $afterMidnight), 'the 15th starts with a fresh budget');

        maw_assert_same(1, YoutubeGuard::getDailyCallCount($beforeMidnight), 'the 14th still shows one call');
        maw_assert_same(1, YoutubeGuard::getDailyCallCount($afterMidnight), 'the 15th shows one call');
    },

    'the reset boundary follows standard time in winter' => static function (): void {
        // Pacific Standard Time is UTC-8, so local midnight is 08:00 UTC.
        $beforeMidnight = (int) strtotime('2026-01-15 07:59:00 UTC');
        $afterMidnight  = (int) strtotime('2026-01-15 08:00:00 UTC');

        maw_assert_same(
            'maw_yt_calls_20260114',
            YoutubeGuard::dailyCounterOptionName($beforeMidnight),
            '07:59 UTC in January is still the previous Pacific day'
        );
        maw_assert_same(
            'maw_yt_calls_20260115',
            YoutubeGuard::dailyCounterOptionName($afterMidnight),
            '08:00 UTC in January is the new Pacific day'
        );
    },

    'the reset boundary follows daylight time in summer' => static function (): void {
        // Pacific Daylight Time is UTC-7, so local midnight is 07:00 UTC.
        $beforeMidnight = (int) strtotime('2026-07-15 06:59:00 UTC');
        $afterMidnight  = (int) strtotime('2026-07-15 07:00:00 UTC');

        maw_assert_same(
            'maw_yt_calls_20260714',
            YoutubeGuard::dailyCounterOptionName($beforeMidnight),
            '06:59 UTC in July is still the previous Pacific day'
        );
        maw_assert_same(
            'maw_yt_calls_20260715',
            YoutubeGuard::dailyCounterOptionName($afterMidnight),
            '07:00 UTC in July is the new Pacific day'
        );

        // 07:30 UTC discriminates the two offsets: in January it is still the
        // previous Pacific day, in July it is already the new one. A fixed
        // offset could not satisfy both, so this proves DST is honored.
        maw_assert_same(
            'maw_yt_calls_20260114',
            YoutubeGuard::dailyCounterOptionName((int) strtotime('2026-01-15 07:30:00 UTC')),
            '07:30 UTC on 15 January is still the 14th in Pacific Standard Time'
        );
        maw_assert_same(
            'maw_yt_calls_20260715',
            YoutubeGuard::dailyCounterOptionName((int) strtotime('2026-07-15 07:30:00 UTC')),
            '07:30 UTC on 15 July is already the 15th in Pacific Daylight Time'
        );
    },

    'obsolete counter rows are cleaned up' => static function (): void {
        global $wpdb;

        $wpdb->seed('maw_yt_calls_20250101', '17');
        $wpdb->seed('maw_yt_calls_20250102', '42');

        $now = (int) strtotime('2026-07-15 20:00:00 UTC');
        YoutubeGuard::reserveDailyCall(500, $now);

        $names = $wpdb->names();

        maw_assert(!in_array('maw_yt_calls_20250101', $names, true), 'the 2025-01-01 counter is removed');
        maw_assert(!in_array('maw_yt_calls_20250102', $names, true), 'the 2025-01-02 counter is removed');
        maw_assert(
            in_array(YoutubeGuard::dailyCounterOptionName($now), $names, true),
            "today's counter survives"
        );
    },

    "yesterday's counter is preserved for diagnostics" => static function (): void {
        global $wpdb;

        $now       = (int) strtotime('2026-07-15 20:00:00 UTC');
        $yesterday = YoutubeGuard::dailyCounterOptionName($now - DAY_IN_SECONDS);

        $wpdb->seed($yesterday, '123');
        YoutubeGuard::reserveDailyCall(500, $now);

        maw_assert_same('123', $wpdb->peek($yesterday), "yesterday's counter is left alone");
    },
];
