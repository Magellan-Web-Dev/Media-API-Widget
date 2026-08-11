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
 * Builds a playlistItems page whose items carry exactly the thumbnails given.
 *
 * Real playlists are ragged: YouTube omits any size it did not generate, and a
 * snippet can arrive with no `thumbnails` object at all. Each entry is the
 * `thumbnails` value for one item, or null to leave the key off entirely.
 *
 * @param array<int,array<string,mixed>|null> $thumbnailSets One entry per item.
 * @return array<string,mixed> Decoded response payload.
 */
function maw_youtube_thumbnail_page(array $thumbnailSets): array
{
    $items = [];

    foreach (array_values($thumbnailSets) as $index => $thumbnails) {
        $n       = $index + 1;
        $snippet = [
            'title'       => 'Episode ' . $n,
            'description' => 'Description for episode ' . $n,
            'publishedAt' => sprintf('2026-01-%02dT12:00:00Z', ($n % 28) + 1),
            'resourceId'  => ['videoId' => sprintf('vid%05d', $n)],
        ];

        if ($thumbnails !== null) {
            $snippet['thumbnails'] = $thumbnails;
        }

        $items[] = ['snippet' => $snippet];
    }

    return [
        'kind'     => 'youtube#playlistItemListResponse',
        'items'    => $items,
        'pageInfo' => ['totalResults' => count($items), 'resultsPerPage' => 50],
    ];
}

/**
 * Builds one thumbnail size entry, matching the shape the API returns.
 *
 * @param string $size Size name, e.g. 'maxres'.
 * @param int    $n    Item number, so URLs stay distinguishable.
 * @return array<string,mixed> Thumbnail object.
 */
function maw_thumbnail(string $size, int $n = 1): array
{
    return [
        'url'    => sprintf('https://i.ytimg.com/vi/vid%05d/%s.jpg', $n, $size),
        'width'  => 1280,
        'height' => 720,
    ];
}

/**
 * Runs a callable with a handler that records every PHP diagnostic raised.
 *
 * Warnings and notices are what a missing optional array key produces, and they
 * are invisible to a normal assertion — the value still comes back null. This
 * captures them so a test can assert none were emitted at all.
 *
 * @param callable $callback Code to run.
 * @return array{result:mixed,errors:array<int,string>} Return value and messages.
 */
function maw_capture_php_errors(callable $callback): array
{
    $errors = [];

    set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$errors): bool {
        $errors[] = sprintf('%d: %s at %s:%d', $level, $message, basename($file), $line);

        return true;
    });

    try {
        $result = $callback();
    } finally {
        restore_error_handler();
    }

    return ['result' => $result, 'errors' => $errors];
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

/**
 * Returns the absolute backup file path for a podcast playlist.
 *
 * @param string $playlistName Playlist slug.
 * @return string Absolute path.
 */
function maw_podcast_backup_path(string $playlistName): string
{
    return MediaApiWidget\Frontend\MediaContent::backupDir() . $playlistName . '_podcast_backup_data.json';
}

/**
 * Invokes Shortcode's private podcast cache warm-up path.
 *
 * Driven directly rather than through the shortcode entry points, which would
 * pull in a dozen further WordPress functions (shortcode_atts, home_url,
 * esc_url, sanitize_text_field, …) that this suite deliberately does not stub.
 *
 * @param string              $playlistName Playlist slug.
 * @param array<string,mixed> $mediaConfig  Admin media config for the playlist.
 * @return array<string,mixed>|null Stored podcast data, or null on failure.
 */
function maw_run_podcast_warmup(string $playlistName, array $mediaConfig): ?array
{
    $shortcode = new MediaApiWidget\Frontend\Shortcode();

    $invoke = Closure::bind(
        static function (
            MediaApiWidget\Frontend\Shortcode $shortcode,
            string $playlistName,
            array $mediaConfig
        ): ?array {
            return $shortcode->fetchPodcastDataAndWarmCache($playlistName, $mediaConfig);
        },
        null,
        MediaApiWidget\Frontend\Shortcode::class
    );

    return $invoke($shortcode, $playlistName, $mediaConfig);
}

/**
 * Runs the full wp_head media pipeline, capturing the emitted scripts.
 *
 * Needed for cases about the transient-hit path: the loader helpers above call
 * loadYoutubeData()/loadPodcastData() directly, which is *past* the transient
 * check, so only the public entry point can prove a cache hit skips the store.
 *
 * @param array<string,mixed> $config Media config array.
 * @return string Everything echoed into wp_head.
 */
function maw_run_media_content(array $config): string
{
    ob_start();
    MediaApiWidget\Frontend\MediaContent::getMediaContent($config);

    return (string) ob_get_clean();
}

/**
 * Registers a hook callback for the duration of one test.
 *
 * Defaults to two accepted arguments because every hook in the extension API
 * passes a payload and a context array.
 *
 * @param string   $tag          Hook name.
 * @param callable $callback     Callback to register.
 * @param int      $acceptedArgs How many arguments the callback wants.
 * @return void
 */
function maw_on(string $tag, callable $callback, int $acceptedArgs = 2): void
{
    add_filter($tag, $callback, 10, $acceptedArgs);
}

/**
 * Returns every recorded invocation of one hook, in order.
 *
 * @param string $tag Hook name.
 * @return array<int,array{tag:string,args:array<int,mixed>}> Matching invocations.
 */
function maw_hook_calls(string $tag): array
{
    return array_values(array_filter(
        MawTestState::$hookCalls,
        static fn (array $call): bool => $call['tag'] === $tag
    ));
}

/**
 * Returns how many times one hook fired.
 *
 * @param string $tag Hook name.
 * @return int Invocation count.
 */
function maw_hook_count(string $tag): int
{
    return count(maw_hook_calls($tag));
}

/**
 * Returns the arguments of one recorded hook invocation.
 *
 * @param string $tag   Hook name.
 * @param int    $index Zero-based invocation index.
 * @return array<int,mixed> Arguments, or an empty array when absent.
 */
function maw_hook_args(string $tag, int $index = 0): array
{
    $calls = maw_hook_calls($tag);

    return $calls[$index]['args'] ?? [];
}

/**
 * Makes the backup directory unwritable so a backup write fails for real.
 *
 * Used instead of a mock flag inside the storage code, so the partial-persist
 * path is exercised through a genuine filesystem failure. Any existing backup
 * file stays present and readable, which is the state the assertions check.
 *
 * @return string The backup directory path, for restoring afterwards.
 */
function maw_lock_backup_dir(): string
{
    $dir = MediaApiWidget\Support\BackupFiles::directory();
    chmod($dir, 0555);

    return $dir;
}

/**
 * Restores write access to the backup directory.
 *
 * @param string $dir Path returned by {@see maw_lock_backup_dir()}.
 * @return void
 */
function maw_unlock_backup_dir(string $dir): void
{
    chmod($dir, 0777);
}
