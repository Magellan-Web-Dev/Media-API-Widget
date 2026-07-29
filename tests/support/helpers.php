<?php
/**
 * Assertions and fixtures for the Media API Widget test suite.
 *
 * Kept to plain functions and one counter class so the suite stays runnable
 * with `php tests/run-tests.php` and adds no dependencies to the plugin.
 */

declare(strict_types=1);

/**
 * Pass/fail tallies and failure messages for the current run.
 */
final class MawTestResults
{
    /** @var int Assertions that passed. */
    public static int $passed = 0;

    /** @var int Assertions that failed. */
    public static int $failed = 0;

    /** @var array<int,string> Failure descriptions. */
    public static array $failures = [];

    /** @var string Name of the test currently executing. */
    public static string $currentTest = '';
}

/**
 * Records a passing or failing assertion.
 *
 * @param bool   $condition Result of the assertion.
 * @param string $message   Description of what was asserted.
 * @return void
 */
function maw_assert(bool $condition, string $message): void
{
    if ($condition) {
        MawTestResults::$passed++;

        return;
    }

    MawTestResults::$failed++;
    MawTestResults::$failures[] = MawTestResults::$currentTest . ' — ' . $message;
}

/**
 * Asserts two values are identical (===), reporting both on failure.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Description of what was asserted.
 * @return void
 */
function maw_assert_same($expected, $actual, string $message): void
{
    if ($expected === $actual) {
        MawTestResults::$passed++;

        return;
    }

    MawTestResults::$failed++;
    MawTestResults::$failures[] = sprintf(
        "%s — %s\n        expected: %s\n        actual:   %s",
        MawTestResults::$currentTest,
        $message,
        maw_test_describe($expected),
        maw_test_describe($actual)
    );
}

/**
 * Renders a value compactly for failure output.
 *
 * @param mixed $value Value to describe.
 * @return string Single-line description.
 */
function maw_test_describe($value): string
{
    if (is_string($value)) {
        return "'" . (strlen($value) > 120 ? substr($value, 0, 117) . '...' : $value) . "'";
    }

    $encoded = json_encode($value);
    if (!is_string($encoded)) {
        return gettype($value);
    }

    return strlen($encoded) > 200 ? substr($encoded, 0, 197) . '...' : $encoded;
}

/**
 * Queues a successful HTTP 200 JSON response.
 *
 * @param mixed $payload Value to JSON-encode as the body.
 * @return void
 */
function maw_queue_json($payload): void
{
    MawTestState::$httpQueue[] = [
        'response' => ['code' => 200],
        'body'     => is_string($payload) ? $payload : (string) json_encode($payload),
        'headers'  => [],
    ];
}

/**
 * Queues a raw body with an explicit status code.
 *
 * @param string $body Response body.
 * @param int    $code HTTP status code.
 * @return void
 */
function maw_queue_raw(string $body, int $code = 200): void
{
    MawTestState::$httpQueue[] = [
        'response' => ['code' => $code],
        'body'     => $body,
        'headers'  => [],
    ];
}

/**
 * Queues a WP_Error, simulating a connection failure.
 *
 * @param string $code Error code.
 * @return void
 */
function maw_queue_error(string $code = 'http_request_failed'): void
{
    MawTestState::$httpQueue[] = new WP_Error($code, 'Simulated transport failure.');
}

/**
 * Builds a well-formed playlistItems page.
 *
 * @param int         $itemCount     Number of items on this page.
 * @param string|null $nextPageToken Token for the following page, or null for the last page.
 * @param int         $totalResults  Value to report in pageInfo.totalResults.
 * @param int         $startIndex    Offset used to make titles/IDs unique across pages.
 * @return array<string,mixed> Decoded response payload.
 */
function maw_youtube_page(int $itemCount, ?string $nextPageToken, int $totalResults, int $startIndex = 0): array
{
    $items = [];

    for ($i = 0; $i < $itemCount; $i++) {
        $n       = $startIndex + $i + 1;
        $items[] = [
            'snippet' => [
                'title'       => 'Episode ' . $n,
                'description' => 'Description for episode ' . $n,
                'publishedAt' => sprintf('2026-01-%02dT12:00:00Z', ($n % 28) + 1),
                'resourceId'  => ['videoId' => sprintf('vid%05d', $n)],
                'thumbnails'  => [
                    'maxres'   => ['url' => 'https://i.ytimg.com/vi/' . sprintf('vid%05d', $n) . '/maxres.jpg'],
                    'standard' => ['url' => 'https://i.ytimg.com/vi/' . sprintf('vid%05d', $n) . '/standard.jpg'],
                    'high'     => ['url' => 'https://i.ytimg.com/vi/' . sprintf('vid%05d', $n) . '/high.jpg'],
                    'medium'   => ['url' => 'https://i.ytimg.com/vi/' . sprintf('vid%05d', $n) . '/medium.jpg'],
                    'default'  => ['url' => 'https://i.ytimg.com/vi/' . sprintf('vid%05d', $n) . '/default.jpg'],
                ],
            ],
        ];
    }

    $page = [
        'kind'     => 'youtube#playlistItemListResponse',
        'items'    => $items,
        'pageInfo' => ['totalResults' => $totalResults, 'resultsPerPage' => 50],
    ];

    if ($nextPageToken !== null) {
        $page['nextPageToken'] = $nextPageToken;
    }

    return $page;
}

/**
 * Returns a media config array for MediaContent's YouTube loader.
 *
 * @param array<string,mixed> $overrides Values to merge over the defaults.
 * @return array<string,mixed> Resolved config.
 */
function maw_youtube_config(array $overrides = []): array
{
    $defaults = MediaApiWidget\Config\Options::getDefaultCacheExpirations();

    return array_merge([
        'type'                          => 'youtube',
        'podcast_platform'              => 'custom',
        'playlist_name'                 => 'testshow',
        'api_key'                       => 'SECRET-API-KEY-DO-NOT-LEAK',
        'media_data'                    => 'PL1234567890',
        'sort_mode'                     => 'normal',
        'season_episode_regex_enabled'  => false,
        'season_episode_regex'          => '',
        'load_full_playlist'            => true,
        'cookie_name'                   => 'media_api_widget',
        'cookie_expired'                => true,
        'media_cache_ttl'               => $defaults['media_cache_ttl'],
        'youtube_request_in_progress_ttl' => $defaults['youtube_request_in_progress_ttl'],
        'youtube_error_ttl'             => $defaults['youtube_error_ttl'],
        'youtube_backup_window_seconds' => $defaults['youtube_backup_window_seconds'],
        'youtube_max_pages_per_refresh' => $defaults['youtube_max_pages_per_refresh'],
        'youtube_daily_call_limit'      => $defaults['youtube_daily_call_limit'],
    ], $overrides);
}

/**
 * Invokes MediaContent's private YouTube loader and returns the resulting state.
 *
 * A closure bound to the MediaContent class scope is used rather than
 * ReflectionMethod because the loader takes its state array by reference.
 *
 * @param array<string,mixed> $config Media config array.
 * @return array<string,mixed> Final state array.
 */
function maw_run_youtube_load(array $config): array
{
    $state = [
        'parsedData'       => [],
        'errorLoadingData' => false,
        'dataLoadedMethod' => 'API',
        'abort'            => false,
    ];

    $invoke = Closure::bind(
        static function (array $config, array &$state): void {
            MediaApiWidget\Frontend\MediaContent::loadYoutubeData($config, $state);
        },
        null,
        MediaApiWidget\Frontend\MediaContent::class
    );

    $invoke($config, $state);

    return $state;
}

/**
 * Invokes MediaContent's private podcast loader and returns the resulting state.
 *
 * @param array<string,mixed> $config Media config array.
 * @return array<string,mixed> Final state array.
 */
function maw_run_podcast_load(array $config): array
{
    $state = [
        'parsedData'       => [],
        'errorLoadingData' => false,
        'dataLoadedMethod' => 'API',
        'abort'            => false,
    ];

    $invoke = Closure::bind(
        static function (array $config, array &$state): void {
            MediaApiWidget\Frontend\MediaContent::loadPodcastData($config, $state);
        },
        null,
        MediaApiWidget\Frontend\MediaContent::class
    );

    $invoke($config, $state);

    return $state;
}

/**
 * Returns the absolute backup file path for a YouTube playlist.
 *
 * @param string $playlistName Playlist slug.
 * @return string Absolute path.
 */
function maw_backup_path(string $playlistName): string
{
    return MediaApiWidget\Frontend\MediaContent::backupDir() . $playlistName . '_youtube_backup_data.json';
}
