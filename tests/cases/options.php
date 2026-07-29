<?php
/**
 * Settings and backward-compatibility tests.
 *
 * The important guarantee is that installs saved before the guard settings
 * existed receive the new defaults at read time, without an administrator
 * having to resave anything and without any stored value changing.
 */

declare(strict_types=1);

use MediaApiWidget\Config\Options;

return [

    'the new guard defaults are present' => static function (): void {
        $defaults = Options::getDefaultCacheExpirations();

        maw_assert_same(20, $defaults['youtube_max_pages_per_refresh'], 'the default page ceiling is 20');
        maw_assert_same(200, $defaults['youtube_daily_call_limit'], 'the default daily limit is 200');
    },

    'the existing ttl defaults are unchanged' => static function (): void {
        $defaults = Options::getDefaultCacheExpirations();

        maw_assert_same(7200, $defaults['media_cache_ttl'], 'media_cache_ttl is unchanged');
        maw_assert_same(600, $defaults['youtube_request_in_progress_ttl'], 'youtube_request_in_progress_ttl is unchanged');
        maw_assert_same(600, $defaults['youtube_error_ttl'], 'youtube_error_ttl is unchanged');
        maw_assert_same(7200, $defaults['youtube_backup_window_seconds'], 'youtube_backup_window_seconds is unchanged');
    },

    'a legacy saved option without the new keys receives the defaults' => static function (): void {
        // Exactly what a pre-upgrade install has stored: the four original TTLs.
        update_option(Options::OPTION_CACHE_EXPIRATIONS, [
            'media_cache_ttl'                 => 3600,
            'youtube_request_in_progress_ttl' => 300,
            'youtube_error_ttl'               => 900,
            'youtube_backup_window_seconds'   => 1800,
        ]);

        $settings = Options::getCacheExpirations();

        maw_assert_same(3600, $settings['media_cache_ttl'], 'the stored media_cache_ttl is preserved');
        maw_assert_same(300, $settings['youtube_request_in_progress_ttl'], 'the stored in-progress TTL is preserved');
        maw_assert_same(900, $settings['youtube_error_ttl'], 'the stored error TTL is preserved');
        maw_assert_same(1800, $settings['youtube_backup_window_seconds'], 'the stored backup window is preserved');
        maw_assert_same(20, $settings['youtube_max_pages_per_refresh'], 'the page ceiling defaults to 20');
        maw_assert_same(200, $settings['youtube_daily_call_limit'], 'the daily limit defaults to 200');
    },

    'reading legacy settings does not rewrite the stored option' => static function (): void {
        $stored = [
            'media_cache_ttl'                 => 3600,
            'youtube_request_in_progress_ttl' => 300,
            'youtube_error_ttl'               => 900,
            'youtube_backup_window_seconds'   => 1800,
        ];
        update_option(Options::OPTION_CACHE_EXPIRATIONS, $stored);

        Options::getCacheExpirations();

        maw_assert_same(
            $stored,
            get_option(Options::OPTION_CACHE_EXPIRATIONS),
            'the stored option is left exactly as it was'
        );
    },

    'a missing option returns every default' => static function (): void {
        maw_assert_same(
            Options::getDefaultCacheExpirations(),
            Options::getCacheExpirations(),
            'a fresh install gets the full default set'
        );
    },

    'a corrupted option falls back to the defaults' => static function (): void {
        update_option(Options::OPTION_CACHE_EXPIRATIONS, 'not-an-array');

        maw_assert_same(
            Options::getDefaultCacheExpirations(),
            Options::getCacheExpirations(),
            'a non-array stored value degrades to the defaults'
        );
    },

    'the page ceiling is clamped to 1-100' => static function (): void {
        Options::setCacheExpirations(['youtube_max_pages_per_refresh' => 0]);
        maw_assert_same(1, Options::getCacheExpirations()['youtube_max_pages_per_refresh'], 'zero is raised to 1');

        Options::setCacheExpirations(['youtube_max_pages_per_refresh' => 5000]);
        maw_assert_same(100, Options::getCacheExpirations()['youtube_max_pages_per_refresh'], '5000 is lowered to 100');

        Options::setCacheExpirations(['youtube_max_pages_per_refresh' => 37]);
        maw_assert_same(37, Options::getCacheExpirations()['youtube_max_pages_per_refresh'], 'an in-range value is kept');
    },

    'the daily limit is clamped to 1-10000' => static function (): void {
        Options::setCacheExpirations(['youtube_daily_call_limit' => 0]);
        maw_assert_same(1, Options::getCacheExpirations()['youtube_daily_call_limit'], 'zero is raised to 1');

        Options::setCacheExpirations(['youtube_daily_call_limit' => 999999]);
        maw_assert_same(10000, Options::getCacheExpirations()['youtube_daily_call_limit'], '999999 is lowered to 10000');

        Options::setCacheExpirations(['youtube_daily_call_limit' => 2500]);
        maw_assert_same(2500, Options::getCacheExpirations()['youtube_daily_call_limit'], 'an in-range value is kept');
    },

    'saving a form submission preserves every key' => static function (): void {
        Options::setCacheExpirations([
            'media_cache_ttl'                 => '1200',
            'youtube_request_in_progress_ttl' => '120',
            'youtube_error_ttl'               => '240',
            'youtube_backup_window_seconds'   => '3600',
            'youtube_max_pages_per_refresh'   => '12',
            'youtube_daily_call_limit'        => '750',
        ]);

        maw_assert_same(
            [
                'media_cache_ttl'                 => 1200,
                'youtube_request_in_progress_ttl' => 120,
                'youtube_error_ttl'               => 240,
                'youtube_backup_window_seconds'   => 3600,
                'youtube_max_pages_per_refresh'   => 12,
                'youtube_daily_call_limit'        => 750,
            ],
            Options::getCacheExpirations(),
            'string form values are normalized to integers and all six keys persist'
        );
    },

    'existing ttl clamping behavior is unchanged' => static function (): void {
        Options::setCacheExpirations(['media_cache_ttl' => 0]);
        maw_assert_same(1, Options::getCacheExpirations()['media_cache_ttl'], 'a zero TTL is still raised to 1');

        Options::setCacheExpirations(['youtube_error_ttl' => 86400 * 30]);
        maw_assert_same(
            86400 * 30,
            Options::getCacheExpirations()['youtube_error_ttl'],
            'TTLs still have no upper bound'
        );
    },

    'the original option name is unchanged' => static function (): void {
        maw_assert_same('maw_cache_expirations', Options::OPTION_CACHE_EXPIRATIONS, 'the option key is preserved');
    },
];
