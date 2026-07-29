<?php
/**
 * Atomic per-playlist refresh lock tests.
 *
 * The lock is a wp_options row created by a bare INSERT, so the simulated
 * unique index in the $wpdb double is what these cases actually exercise.
 */

declare(strict_types=1);

use MediaApiWidget\Support\YoutubeGuard;

return [

    'two callers cannot both acquire the same playlist lock' => static function (): void {
        $first  = YoutubeGuard::acquireLock('testshow', 600);
        $second = YoutubeGuard::acquireLock('testshow', 600);

        maw_assert(is_array($first), 'the first caller acquires the lock');
        maw_assert_same(null, $second, 'the second caller is refused');
        maw_assert_same(false, $first['fallback'], 'the first caller holds a real database lock');
    },

    'different playlists lock independently' => static function (): void {
        $a = YoutubeGuard::acquireLock('showone', 600);
        $b = YoutubeGuard::acquireLock('showtwo', 600);

        maw_assert(is_array($a), 'the first playlist locks');
        maw_assert(is_array($b), 'a different playlist locks at the same time');
    },

    'releasing a lock lets the next caller in' => static function (): void {
        $first = YoutubeGuard::acquireLock('testshow', 600);
        YoutubeGuard::releaseLock($first);

        $second = YoutubeGuard::acquireLock('testshow', 600);

        maw_assert(is_array($second), 'the lock is available again after release');
    },

    'only the owner can release a lock' => static function (): void {
        global $wpdb;

        $real = YoutubeGuard::acquireLock('testshow', 600);

        // A different worker that thinks it owns the lock must not delete it.
        YoutubeGuard::releaseLock(['name' => $real['name'], 'value' => '{"owner":"someone-else","expires":9999999999}', 'fallback' => false]);

        maw_assert(
            $wpdb->peek(YoutubeGuard::lockOptionName('testshow')) !== null,
            'a non-owner release leaves the lock in place'
        );
        maw_assert_same(null, YoutubeGuard::acquireLock('testshow', 600), 'the lock is still held');

        YoutubeGuard::releaseLock($real);
        maw_assert(
            $wpdb->peek(YoutubeGuard::lockOptionName('testshow')) === null,
            'the real owner can release it'
        );
    },

    'an expired lock is stolen so refreshes are never blocked forever' => static function (): void {
        global $wpdb;

        $name = YoutubeGuard::lockOptionName('testshow');
        $now  = 1800000000;

        // Simulate a worker killed mid-refresh: its lock row survived, but its
        // expiry is in the past.
        $wpdb->seed($name, (string) json_encode(['owner' => 'dead-worker', 'expires' => $now - 1]));

        $stolen = YoutubeGuard::acquireLock('testshow', 600, $now);

        maw_assert(is_array($stolen), 'the stale lock is reclaimed');
        maw_assert(
            $wpdb->peek($name) !== (string) json_encode(['owner' => 'dead-worker', 'expires' => $now - 1]),
            'the lock row now holds the new owner'
        );
        maw_assert_same(null, YoutubeGuard::acquireLock('testshow', 600, $now), 'the reclaimed lock excludes others');
    },

    'a live lock is not stolen' => static function (): void {
        global $wpdb;

        $now = 1800000000;
        $wpdb->seed(
            YoutubeGuard::lockOptionName('testshow'),
            (string) json_encode(['owner' => 'busy-worker', 'expires' => $now + 300])
        );

        maw_assert_same(null, YoutubeGuard::acquireLock('testshow', 600, $now), 'a live lock is respected');
    },

    'a corrupted lock value is treated as stale' => static function (): void {
        global $wpdb;

        $wpdb->seed(YoutubeGuard::lockOptionName('testshow'), 'not-json-at-all');

        maw_assert(
            is_array(YoutubeGuard::acquireLock('testshow', 600)),
            'an unparseable lock row cannot wedge refreshes'
        );
    },

    'a lock without an expiry is treated as stale' => static function (): void {
        global $wpdb;

        $wpdb->seed(YoutubeGuard::lockOptionName('testshow'), '{"owner":"legacy"}');

        maw_assert(
            is_array(YoutubeGuard::acquireLock('testshow', 600)),
            'a lock row with no expiry is reclaimed'
        );
    },

    'a concurrent refresh is refused and recorded' => static function (): void {
        // Another worker already holds the lock.
        YoutubeGuard::acquireLock('testshow', 600);

        maw_queue_json(maw_youtube_page(6, null, 6));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(0, count(MawTestState::$httpRequests), 'no duplicate fetch is started');
        maw_assert_same(true, $state['errorLoadingData'], 'the request falls back instead of fetching');
        maw_assert_same(
            'concurrent_refresh',
            YoutubeGuard::getGuardStatus()['reason'] ?? '',
            'a concurrent_refresh event is recorded'
        );
    },

    'the lock is released after a successful refresh' => static function (): void {
        global $wpdb;

        maw_queue_json(maw_youtube_page(6, null, 6));
        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(
            null,
            $wpdb->peek(YoutubeGuard::lockOptionName('testshow')),
            'no lock row is left behind'
        );
    },

    'the lock is released after an aborted refresh' => static function (): void {
        global $wpdb;

        maw_queue_json(maw_youtube_page(50, 'LOOP', 4000, 0));
        maw_queue_json(maw_youtube_page(50, 'LOOP', 4000, 50));

        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(
            null,
            $wpdb->peek(YoutubeGuard::lockOptionName('testshow')),
            'a repeated-token abort still releases the lock'
        );
    },

    'the lock is released after a page-limit abort' => static function (): void {
        global $wpdb;

        for ($page = 0; $page < 10; $page++) {
            maw_queue_json(maw_youtube_page(50, 'TOKEN-' . $page, 100000, $page * 50));
        }

        maw_run_youtube_load(maw_youtube_config(['youtube_max_pages_per_refresh' => 3]));

        maw_assert_same(
            null,
            $wpdb->peek(YoutubeGuard::lockOptionName('testshow')),
            'a page-limit abort still releases the lock'
        );
    },

    'the lock is released after an http error' => static function (): void {
        global $wpdb;

        maw_queue_error();
        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(
            null,
            $wpdb->peek(YoutubeGuard::lockOptionName('testshow')),
            'a transport failure still releases the lock'
        );
    },

    'the lock is released when an item aborts the render' => static function (): void {
        global $wpdb;

        maw_queue_json([
            'items'    => [['id' => 'no-snippet']],
            'pageInfo' => ['totalResults' => 1, 'resultsPerPage' => 50],
        ]);

        $previous = error_reporting(E_ALL & ~E_WARNING);
        maw_run_youtube_load(maw_youtube_config());
        error_reporting($previous);

        maw_assert_same(
            null,
            $wpdb->peek(YoutubeGuard::lockOptionName('testshow')),
            'the abort path still releases the lock'
        );
    },

    'the legacy in-progress transient is still written and cleared on success' => static function (): void {
        maw_queue_json(maw_youtube_page(6, null, 6));
        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(
            false,
            get_transient('testshow_youtube_request_in_progress'),
            'the legacy transient is cleared after a successful refresh'
        );
    },

    'the legacy in-progress transient survives a failed refresh, as before' => static function (): void {
        maw_queue_error();
        maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(
            true,
            get_transient('testshow_youtube_request_in_progress'),
            'the legacy transient still lingers after a failure, preserving the existing console warning'
        );
    },
];
