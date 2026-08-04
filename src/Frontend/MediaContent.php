<?php
namespace MediaApiWidget\Frontend;

use MediaApiWidget\Config\Options;
use MediaApiWidget\Support\BackupFiles;
use MediaApiWidget\Support\MediaStore;
use MediaApiWidget\Support\SafeRemoteRequest;
use MediaApiWidget\Support\YoutubeGuard;

if (!defined('ABSPATH')) { exit; }

/**
 * Static utility class for fetching, caching, and inlining media data.
 *
 * Implements the full server-side media data pipeline:
 * 1. Reads the per-item config from plugin options.
 * 2. Checks whether the browser's cookie signals that localStorage is still
 *    fresh; if not, loads data from the WordPress transient cache or by
 *    calling the YouTube Data API / podcast RSS feed.
 * 3. Writes fresh data to the browser's localStorage via an inline
 *    `<script>` emitted in wp_head.
 * 4. Emits a second initialization `<script>` that reads the data back from
 *    localStorage and calls the front-end initialize_media() function.
 *
 * This class fetches and parses; it does not persist. Every successful remote
 * refresh hands its parsed payload to {@see MediaStore::store()}, which is the
 * single place the `media_api_widget_data_before_store` filter fires, the
 * transient and the local backup JSON file are written, and the
 * `media_api_widget_data_stored` action fires. Failed and partial refreshes
 * never reach the store, so previously good data survives intact.
 *
 * All public methods are static; this class is not intended to be instantiated.
 */
final class MediaContent
{
    /**
     * Returns the absolute path to the plugin's backup data directory.
     *
     * The directory is `{uploads_basedir}/media-api-widget/backups/` and is
     * created with wp_mkdir_p() if it does not already exist. The returned
     * path always ends with a trailing slash.
     *
     * Delegates to {@see BackupFiles::directory()}, which is the neutral path
     * authority shared with {@see MediaStore} and the admin Stats page. Kept as
     * a public wrapper because existing callers reference it by this name.
     *
     * @return string Absolute directory path with trailing slash.
     */
    public static function backupDir(): string
    {
        return BackupFiles::directory();
    }

    /**
     * Extracts an episode number from a video title string.
     *
     * Collects every run of consecutive digits in the title and returns the
     * last one that qualifies (non-zero and less than 2000, to exclude
     * year-format numbers). Scanning from the end means season/episode style
     * titles such as "TWCS5E13" resolve to the episode (13) rather than the
     * season (5), while single-number titles like "Retire Smart Austin EP248"
     * still return that number (248). Returns -1 when no qualifying number is
     * found.
     *
     * @param string $title The video title to parse (e.g. "042 - Episode Name").
     * @return int Episode number (1–1999), or -1 if none found.
     */
    public static function episodeNumberGenerator(string $title): int
    {
        if (!preg_match_all('/[0-9]+/', $title, $matches)) {
            return -1;
        }
        foreach (array_reverse($matches[0]) as $number) {
            $number = intval($number);
            if ($number !== 0 && $number < 2000) {
                return $number;
            }
        }
        return -1;
    }

    /**
     * Extracts a season and episode number from a title using a custom regex.
     *
     * This powers the optional "Use season/episode regex" mode. The supplied
     * pattern is written without delimiters; capture group 1 is treated as the
     * season and capture group 2 as the episode. Named groups 'season' and
     * 'episode' (PCRE `(?'name'...)` syntax) are honored when present and take
     * precedence over the positional groups. The pattern is matched
     * case-insensitively, so "S5E14", "s5e14", and "TWCS5E14" all resolve.
     *
     * Falls back gracefully: when the pattern is invalid, empty, or does not
     * match the title, the season is returned as -1 and the episode is resolved
     * via {@see self::episodeNumberGenerator()} so behavior degrades to the
     * default episode-only numbering rather than breaking the playlist.
     *
     * @param string $title The video title to parse (e.g. "TWCS5E14").
     * @param string $regex The user-supplied regex pattern, without delimiters.
     * @return array{season:int,episode:int} Parsed season and episode numbers.
     */
    public static function seasonEpisodeGenerator(string $title, string $regex): array
    {
        $fallback = ['season' => -1, 'episode' => self::episodeNumberGenerator($title)];

        if ($regex === '') {
            return $fallback;
        }

        // Wrap the user pattern in tilde delimiters with a case-insensitive
        // flag. Escape any literal tildes so they cannot terminate the pattern
        // early. Invalid patterns make preg_match() return false; suppress the
        // warning and degrade to episode-only numbering in that case.
        $delimited = '~' . str_replace('~', '\~', $regex) . '~i';
        $result    = @preg_match($delimited, $title, $matches);

        if ($result !== 1) {
            return $fallback;
        }

        $season  = $matches['season']  ?? ($matches[1] ?? null);
        $episode = $matches['episode'] ?? ($matches[2] ?? null);

        return [
            'season'  => ($season !== null && $season !== '') ? (int) $season : -1,
            'episode' => ($episode !== null && $episode !== '') ? (int) $episode : self::episodeNumberGenerator($title),
        ];
    }

    /**
     * Logs a single API call event to the stats table.
     *
     * Delegates to {@see \MediaApiWidget\Stats\ApiCallLogger::log()} after
     * confirming the class exists. Silently returns when the Stats module is
     * unavailable, keeping this class free of hard dependencies.
     *
     * @param string $playlistName Playlist/feed slug for the log row.
     * @param string $type         Media type ('youtube' or 'podcast').
     * @param string $endpoint     Endpoint identifier (e.g. 'youtube_playlist_items').
     * @param mixed  $response     Raw return value from wp_remote_get().
     * @return void
     */
    public static function logApiCallEvent(string $playlistName, string $type, string $endpoint, $response): void
    {
        if (!class_exists('\\MediaApiWidget\\Stats\\ApiCallLogger')) {
            return;
        }

        \MediaApiWidget\Stats\ApiCallLogger::log(
            (string) $playlistName,
            (string) $type,
            (string) $endpoint,
            $response
        );
    }

    /**
     * Wraps wp_remote_get() with automatic API call logging.
     *
     * Makes the HTTP request, then calls {@see self::logApiCallEvent()}
     * with the context metadata and the raw response. The context array
     * should contain 'playlist_name', 'type', and 'endpoint' keys.
     *
     * @param string              $url     The URL to fetch.
     * @param array<string,mixed> $context Metadata for the log entry.
     * @return array|\WP_Error    Raw wp_remote_get() return value.
     */
    public static function trackedRemoteGet(string $url, array $context = [])
    {
        // Route through the hardened wrapper so RSS feed fetches get SSRF
        // (incl. per-redirect-hop) protection, an explicit timeout, and a
        // response-size cap. The hardcoded YouTube/iTunes API hosts pass the
        // public-host check unchanged.
        $response = SafeRemoteRequest::get($url);

        self::logApiCallEvent(
            $context['playlist_name'] ?? '',
            $context['type'] ?? '',
            $context['endpoint'] ?? 'external_request',
            $response
        );

        return $response;
    }

    /**
     * Fetches and parses a podcast RSS feed, optionally via an iTunes lookup.
     *
     * When `$appleData` is true, treats `$rssFeedInput` as a raw JSON
     * response from the iTunes lookup API, extracts the feedUrl from the first
     * result, and fetches that RSS URL. When false, `$rssFeedInput` is used
     * directly as the RSS URL.
     *
     * Strips HTML tags from each episode's title and description. Attaches
     * the RSS URL and Apple collection view URL to the parsed feed's channel
     * element. Returns null on any network or parse error.
     *
     * @param string|null         $rssFeedInput RSS URL (when $appleData is false) or
     *                                          iTunes lookup JSON body (when true).
     * @param bool                $appleData    True when $rssFeedInput is iTunes JSON.
     * @param array<string,mixed> $context      Metadata for API call logging.
     * @return \SimpleXMLElement|null Parsed RSS document, or null on failure.
     */
    public static function parseRssFeed(?string $rssFeedInput = null, bool $appleData = false, array $context = [])
    {
        if ($rssFeedInput) {
            $rssUrl      = null;
            $getRssData  = null;

            if ($appleData) {
                $getRssData = json_decode($rssFeedInput);

                if (!$getRssData || empty(get_object_vars($getRssData)['results'][0]->feedUrl)) {
                    return null;
                }

                // Extract RSS Feed Url From JSON data from iTunes
                $rssUrl = get_object_vars($getRssData)['results'][0]->feedUrl;

                $rssFeed = self::trackedRemoteGet($rssUrl, [
                    'playlist_name' => $context['playlist_name'] ?? '',
                    'type' => $context['type'] ?? 'podcast',
                    'endpoint' => 'podcast_rss',
                ]);
            } else {
                $rssUrl  = $rssFeedInput;
                $rssFeed = self::trackedRemoteGet($rssUrl, [
                    'playlist_name' => $context['playlist_name'] ?? '',
                    'type' => $context['type'] ?? 'podcast',
                    'endpoint' => 'podcast_rss',
                ]);
            }

            if (is_wp_error($rssFeed) || wp_remote_retrieve_response_code($rssFeed) !== 200) {
                return null;
            }

            $rssBody = wp_remote_retrieve_body($rssFeed);
            if ($rssBody === '') {
                return null;
            }

            // Warnings are suppressed rather than raised: a malformed third-party
            // feed is an expected failure that returns null, not a PHP notice in
            // the middle of wp_head.
            $parsedRssFeed = @simplexml_load_string($rssBody);
            if (!$parsedRssFeed) {
                return null;
            }

            $parsedRssFeed->channel->rssUrl = $rssUrl;
            $parsedRssFeed->channel->collectionViewUrl = $appleData && !empty(get_object_vars($getRssData)['results'][0]->collectionViewUrl)
                ? get_object_vars($getRssData)['results'][0]->collectionViewUrl
                : $rssUrl;

            foreach ($parsedRssFeed->channel->item as $item) {
                $descriptionText   = strip_tags($item->description);
                $item->description = strip_tags($descriptionText);
                $titleText         = strip_tags($item->title);
                $item->title       = $titleText;
            }

            return $parsedRssFeed;
        }

        return null;
    }

    /**
     * Returns the absolute backup file path for a podcast playlist.
     *
     * The file name follows the pattern `{playlistName}_podcast_backup_data.json`
     * within the plugin's backup directory. Delegates to
     * {@see BackupFiles::filePath()} so there is one path authority shared with
     * {@see MediaStore} and the admin Stats page.
     *
     * @param string $playlistName The playlist_name slug.
     * @return string Absolute file path, or an empty string when the slug is unusable.
     */
    private static function podcastBackupFilePath(string $playlistName): string
    {
        return BackupFiles::filePath($playlistName, 'podcast');
    }

    /**
     * Main entry point: loads media data and emits initialization scripts.
     *
     * Builds the resolved config from `$params` plus stored TTL settings, then:
     * 1. When the cookie is expired, calls {@see self::loadMediaDataWhenCookieExpired()}
     *    to populate $state with fresh data (from cache or API).
     * 2. If an abort flag was set (e.g. malformed YouTube response), returns early.
     * 3. Calls {@see self::renderMediaDataStatusScripts()} to emit localStorage
     *    update scripts or error/fallback scripts.
     * 4. Calls {@see self::renderMediaInitializationScript()} to emit the
     *    window.initialize_media() call script.
     *
     * @param array<string,mixed> $params Media item config from MediaBootstrap.
     * @return void
     */
    public static function getMediaContent(array $params): void
    {
        $config = self::buildMediaConfig($params);
        $state = [
            'parsedData'       => [],
            'errorLoadingData' => false,
            'dataLoadedMethod' => 'API',
            'abort'            => false,
        ];

        if ($config['cookie_expired']) {
            self::loadMediaDataWhenCookieExpired($config, $state);
        }

        if ($state['abort']) {
            return;
        }

        self::renderMediaDataStatusScripts($config, $state);
        self::renderMediaInitializationScript($config);
    }

    /**
     * Builds the resolved media config array for a single item.
     *
     * Merges the caller-supplied $params with the current plugin TTL settings
     * from {@see Options::getCacheExpirations()} so downstream methods have a
     * single flat array with all values they need.
     *
     * @param array<string,mixed> $params Raw params from {@see getMediaContent()}.
     * @return array<string,mixed> Resolved config including all TTL values.
     */
    private static function buildMediaConfig(array $params): array
    {
        $cacheExpirations = Options::getCacheExpirations();

        return [
            'type' => $params['type'] ?? null,
            'podcast_platform' => $params['podcast_platform'] ?? 'custom',
            'playlist_name' => $params['playlist_name'] ?? 'unnamed',
            'api_key' => $params['api_key'] ?? null,
            'media_data' => $params['media_data'] ?? null,
            'sort_mode' => $params['sort_mode'] ?? 'normal',
            'season_episode_regex_enabled' => $params['season_episode_regex_enabled'] ?? false,
            'season_episode_regex' => $params['season_episode_regex'] ?? '',
            'load_full_playlist' => $params['load_full_playlist'] ?? false,
            'cookie_name' => $params['cookie_name'] ?? null,
            'cookie_expired' => $params['cookie_expired'] ?? true,
            'media_cache_ttl' => $cacheExpirations['media_cache_ttl'],
            'youtube_request_in_progress_ttl' => $cacheExpirations['youtube_request_in_progress_ttl'],
            'youtube_error_ttl' => $cacheExpirations['youtube_error_ttl'],
            'youtube_backup_window_seconds' => $cacheExpirations['youtube_backup_window_seconds'],
            'youtube_max_pages_per_refresh' => $cacheExpirations['youtube_max_pages_per_refresh'],
            'youtube_daily_call_limit' => $cacheExpirations['youtube_daily_call_limit'],
        ];
    }

    /**
     * Checks the transient cache and calls the appropriate API loader if the cache is empty.
     *
     * Reads the `{type}_{playlist_name}` transient. If found, populates
     * $state['parsedData'] and sets the loaded method to 'server cache'. If
     * not found, delegates to {@see self::loadYoutubeData()} or
     * {@see self::loadPodcastData()} depending on the media type.
     *
     * @param array<string,mixed> $config Resolved media config array.
     * @param array<string,mixed> &$state Mutable state passed through the pipeline.
     * @return void
     */
    private static function loadMediaDataWhenCookieExpired(array $config, array &$state): void
    {
        $type         = $config['type'];
        $playlistName = $config['playlist_name'];

        // Check if data is stored in Cache in Wordpress Transients
        $cachedData = get_transient($type . '_' . $playlistName);

        if ($cachedData !== false) {
            if ($type === 'podcast') {
                $state['parsedData'] = json_decode($cachedData, true);
            } else {
                $state['parsedData'] = $cachedData;
            }
            $state['dataLoadedMethod'] = 'server cache';
        }

        if ($type === 'youtube' && $cachedData === false) {
            self::loadYoutubeData($config, $state);
        }

        if ($type === 'podcast' && $cachedData === false) {
            self::loadPodcastData($config, $state);
        }
    }

    /**
     * Fetches and processes the full YouTube playlist via the Data API v3.
     *
     * Enforces a rate-limit window using a `maw_yt_last_fetched_{playlist_name}`
     * wp_options entry: if the last successful fetch was within
     * `youtube_backup_window_seconds`, serves the backup JSON file instead of
     * calling the API. Also bails early if a previous error transient or
     * in-progress transient is set.
     *
     * Concurrency is enforced by {@see \MediaApiWidget\Support\YoutubeGuard::acquireLock()},
     * an atomic per-playlist lock that is always released in a finally block.
     * The legacy `{playlist_name}_youtube_request_in_progress` transient is
     * still written and cleared exactly as before, for the admin status UI and
     * back-compat, but it is no longer what provides mutual exclusion.
     *
     * Pagination is delegated to {@see self::fetchYoutubePlaylistItems()}, which
     * is bounded by `youtube_max_pages_per_refresh` and by the daily circuit
     * breaker. Parsing is delegated to {@see self::parseYoutubeItems()}, which
     * handles title, video ID, thumbnail, episode number (when sort_mode is
     * 'number_in_title'), publishedDate, and description, deduplicates by title,
     * and trims to the first six items unless load_full_playlist is set.
     *
     * The backup JSON file, the transient cache, the cleared error transient and
     * the `maw_yt_last_fetched_{playlist_name}` timestamp are written only after
     * every requested page completed normally. A refresh that aborts part-way
     * leaves all previously stored data intact and falls back to it.
     *
     * @param array<string,mixed> $config Resolved media config array.
     * @param array<string,mixed> &$state Mutable state. Sets 'parsedData', 'errorLoadingData', or 'abort'.
     * @return void
     */
    private static function loadYoutubeData(array $config, array &$state): void
    {
        $type                          = $config['type'];
        $playlistName                  = $config['playlist_name'];
        $apiKey                        = $config['api_key'];
        $mediaData                     = $config['media_data'];
        $sortMode                      = $config['sort_mode'];
        $loadFullPlaylist              = $config['load_full_playlist'];

        // Season/episode regex mode is only active when sort_mode is
        // 'number_in_title', the checkbox is enabled, and a non-empty pattern
        // is set. When inactive, the legacy episode-only path runs unchanged.
        $seasonEpisodeRegex = trim((string) ($config['season_episode_regex'] ?? ''));
        $useSeasonEpisode   = $sortMode === 'number_in_title'
            && !empty($config['season_episode_regex_enabled'])
            && $seasonEpisodeRegex !== '';
        $mediaCacheTtl                 = (int) $config['media_cache_ttl'];
        $youtubeRequestInProgressTtl   = (int) $config['youtube_request_in_progress_ttl'];
        $youtubeBackupWindowSeconds    = (int) $config['youtube_backup_window_seconds'];
        $youtubeMaxPagesPerRefresh     = (int) $config['youtube_max_pages_per_refresh'];
        $youtubeDailyCallLimit         = (int) $config['youtube_daily_call_limit'];

        $previousYoutubeError  = get_transient($playlistName . '_youtube_error');
        $lastFetchedOptionKey  = 'maw_yt_last_fetched_' . sanitize_key((string) $playlistName);
        $lastFetchedAt         = (int) get_option($lastFetchedOptionKey, 0);
        $fetchIntervalSeconds  = $youtubeBackupWindowSeconds;

        if ($lastFetchedAt > 0 && (time() - $lastFetchedAt) < $fetchIntervalSeconds) {
            $youtubeBackupFilePath = self::backupDir() . $playlistName . '_youtube_backup_data.json';

            if (file_exists($youtubeBackupFilePath) && is_readable($youtubeBackupFilePath)) {
                $parsedBackupData = json_decode(file_get_contents($youtubeBackupFilePath), true);
                if (!empty($parsedBackupData['data']) && is_array($parsedBackupData['data'])) {
                    $state['parsedData']       = $parsedBackupData['data'];
                    $state['dataLoadedMethod'] = 'backup cache (rate limit)';
                    // Backup loaded successfully — not an error condition.
                } else {
                    $state['errorLoadingData'] = true;
                }
            } else {
                $state['errorLoadingData'] = true;
            }
            return;
        }

        if ($previousYoutubeError || get_transient($playlistName . '_youtube_request_in_progress')) {
            $state['errorLoadingData'] = true;
            return;
        }

        // Acquire the atomic per-playlist refresh lock before anything goes
        // outbound. A null handle means another worker is already refreshing
        // this playlist, so this request serves cached/backup data instead of
        // duplicating the fetch.
        $lock = YoutubeGuard::acquireLock($playlistName, $youtubeRequestInProgressTtl);

        if ($lock === null) {
            YoutubeGuard::recordGuardEvent('concurrent_refresh', $playlistName);
            $state['errorLoadingData'] = true;
            return;
        }

        set_transient($playlistName . '_youtube_request_in_progress', true, $youtubeRequestInProgressTtl);

        try {
            $fetch = self::fetchYoutubePlaylistItems(
                (string) $mediaData,
                (string) $apiKey,
                (string) $playlistName,
                (string) $type,
                $youtubeMaxPagesPerRefresh,
                $youtubeDailyCallLimit
            );

            // Pagination aborted: record one sanitized guard event and bail out
            // without touching the backup file, the transient, or the
            // last-fetched timestamp, so previously good data survives intact.
            if (!$fetch['ok']) {
                YoutubeGuard::recordGuardEvent($fetch['reason'], $playlistName, $fetch['pages']);
                $state['errorLoadingData'] = true;
                return;
            }

            $parsedItems = self::parseYoutubeItems(
                $fetch['items'],
                $sortMode,
                $useSeasonEpisode,
                $seasonEpisodeRegex,
                $loadFullPlaylist
            );

            // A structurally invalid item aborts the whole render, exactly as
            // the previous implementation did.
            if ($parsedItems === null) {
                $state['abort'] = true;
                return;
            }

            // Every requested page completed normally, so this refresh may now
            // replace the stored data. MediaStore fires the pre-store filter,
            // validates the result, and writes both the transient and the backup
            // file; a rejection there leaves the previous good data untouched.
            $store = MediaStore::store(
                MediaStore::buildContext((string) $playlistName, 'youtube', MediaStore::SOURCE_REMOTE_REFRESH),
                $parsedItems,
                ['ttl' => $mediaCacheTtl, 'write_backup' => true]
            );

            // Render whatever was accepted for storage, so the current response
            // and the stored payload can never disagree.
            if ($store['data'] !== null) {
                $state['parsedData'] = $store['data'];
            }

            // A refused or incomplete store is treated exactly like a failed
            // fetch: the error transient is left in place and the last-fetched
            // timestamp is not stamped, so the next request retries rather than
            // trusting a half-written refresh.
            if (!$store['ok']) {
                YoutubeGuard::recordGuardEvent('store_rejected', $playlistName, $fetch['pages']);
                $state['errorLoadingData'] = true;
                return;
            }

            // Clear Youtube API Error Transient If Data Successfully Retrieved
            delete_transient($playlistName . '_youtube_error');

            // Clear Youtube API Request In Progress Transient If Data Successfully Retrieved
            delete_transient($playlistName . '_youtube_request_in_progress');

            // Persist successful fetch timestamp so rate limiting does not rely on transient durability.
            update_option($lastFetchedOptionKey, time(), false);
        } finally {
            // Released on every path — success, error, malformed response,
            // page-limit abort, and thrown exception — so a stale lock can
            // never wedge future refreshes.
            YoutubeGuard::releaseLock($lock);
        }
    }

    /**
     * Requests every page of a YouTube playlist, with hard bounds on the loop.
     *
     * Pagination follows YouTube's documented model: the first request carries
     * no page token and counts as page 1, and each subsequent request uses the
     * `nextPageToken` from the previous response. Termination under normal
     * conditions is the absence of a `nextPageToken`.
     *
     * `pageInfo.totalResults` is deliberately *not* used to decide when to stop.
     * For playlistItems it counts entries YouTube will not return (deleted or
     * private videos), so a loop driven by it can never satisfy its own exit
     * condition and depends entirely on the token — which is what allowed a
     * single refresh to issue thousands of requests. It is still read, and
     * returned for diagnostics only.
     *
     * That gap also produces a second documented shape: a playlist reporting 67
     * totalResults returned 50 items, then 11, then a page with an empty `items`
     * array whose `nextPageToken` was the very token used to request it. An
     * empty page reached after items have been collected is therefore treated as
     * the successful end of pagination, and its token is never followed.
     *
     * The loop aborts, discarding everything collected so far, when:
     * - the page ceiling would be exceeded (`maximum_pages_reached`);
     * - the daily circuit breaker is out of budget (`daily_limit_reached`);
     * - a page token repeats on a nonempty page (`repeated_page_token`);
     * - the very first page returns no items yet supplies another
     *   token (`empty_page_with_next_token`);
     * - a WP_Error or non-200 status occurs on any page (`http_error`);
     * - a body is not valid JSON or lacks `items`/`pageInfo` (`malformed_response`).
     *
     * A request is only ever issued for page 1 or for a nonempty, previously
     * unseen token, and every URL parameter is rawurlencode()d.
     *
     * @param string $playlistId   The YouTube playlist ID.
     * @param string $apiKey       The YouTube Data API key.
     * @param string $playlistName Playlist slug, for API logging and diagnostics.
     * @param string $type         Media type, for API logging ('youtube').
     * @param int    $maxPages     Hard ceiling on pages requested this refresh.
     * @param int    $dailyLimit   Daily outbound YouTube request budget.
     * @return array{ok:bool,items:array<int,mixed>,pages:int,reason:string,total_results:int|null}
     */
    private static function fetchYoutubePlaylistItems(
        string $playlistId,
        string $apiKey,
        string $playlistName,
        string $type,
        int $maxPages,
        int $dailyLimit
    ): array {
        $baseUrl = 'https://youtube.googleapis.com/youtube/v3/playlistItems'
            . '?part=snippet'
            . '&playlistId=' . rawurlencode($playlistId)
            . '&key=' . rawurlencode($apiKey)
            . '&maxResults=50';

        $items        = [];
        $seenTokens   = [];
        $pageToken    = null;
        $pages        = 0;
        $totalResults = null;

        $fail = static function (string $reason) use (&$items, &$pages, &$totalResults): array {
            return [
                'ok'            => false,
                'items'         => [],
                'pages'         => $pages,
                'reason'        => $reason,
                'total_results' => $totalResults,
            ];
        };

        while (true) {
            // Hard page ceiling. Checked before the request, so the configured
            // maximum is the number of requests actually issued.
            if ($pages >= max(1, $maxPages)) {
                return $fail('maximum_pages_reached');
            }

            $url = $baseUrl;

            if ($pages > 0) {
                // Never request a page without a nonempty, previously unseen token.
                if (!is_string($pageToken) || $pageToken === '') {
                    return $fail('malformed_response');
                }
                if (isset($seenTokens[$pageToken])) {
                    return $fail('repeated_page_token');
                }
                $seenTokens[$pageToken] = true;
                $url .= '&pageToken=' . rawurlencode($pageToken);
            }

            // Reserve quota before the request leaves the server. A blocked
            // request never goes outbound and is never logged as an API call.
            if (!YoutubeGuard::reserveDailyCall($dailyLimit)) {
                return $fail('daily_limit_reached');
            }

            $response = self::trackedRemoteGet($url, [
                'playlist_name' => $playlistName,
                'type' => $type,
                'endpoint' => 'youtube_playlist_items',
            ]);

            $pages++;

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return $fail('http_error');
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (
                !is_array($data)
                || !isset($data['items']) || !is_array($data['items'])
                || !isset($data['pageInfo']) || !is_array($data['pageInfo'])
            ) {
                return $fail('malformed_response');
            }

            if (isset($data['pageInfo']['totalResults'])) {
                $totalResults = (int) $data['pageInfo']['totalResults'];
            }

            $nextPageToken = isset($data['nextPageToken']) && is_string($data['nextPageToken'])
                ? $data['nextPageToken']
                : '';

            // An empty page arriving after items have already been collected is
            // how YouTube signals the end of a playlist whose totalResults
            // counts entries it will not return. The tail page's nextPageToken
            // is the token that fetched it, so honouring it would loop.
            if ($data['items'] === [] && $items !== []) {
                return [
                    'ok'            => true,
                    'items'         => $items,
                    'pages'         => $pages,
                    'reason'        => '',
                    'total_results' => $totalResults,
                ];
            }

            // Nothing collected yet, but the response promises more: still the
            // exact shape that let the old loop spin forever.
            if ($data['items'] === [] && $nextPageToken !== '') {
                return $fail('empty_page_with_next_token');
            }

            $items = array_merge($items, $data['items']);

            // Normal termination: YouTube has no further page to offer.
            if ($nextPageToken === '') {
                return [
                    'ok'            => true,
                    'items'         => $items,
                    'pages'         => $pages,
                    'reason'        => '',
                    'total_results' => $totalResults,
                ];
            }

            $pageToken = $nextPageToken;
        }
    }

    /**
     * Parses raw playlistItems entries into the plugin's media item structure.
     *
     * Extracted verbatim from the previous inline implementation so a healthy
     * playlist produces byte-identical output: the same episode/season parsing,
     * the same thumbnail preference order, the same de-duplication by episode
     * key and then by adjacent title, the same sort, and the same six-item trim
     * when load_full_playlist is not set.
     *
     * @param array<int,mixed> $rawItems           Merged `items` arrays from every page.
     * @param string           $sortMode           'normal' or 'number_in_title'.
     * @param bool             $useSeasonEpisode   True when season/episode regex mode is active.
     * @param string           $seasonEpisodeRegex The user-supplied pattern, without delimiters.
     * @param mixed            $loadFullPlaylist   Anything other than boolean true trims
     *                                             the result to six items. Left untyped so a
     *                                             legacy MEDIA_CONTENT_DATA value of 1 or '1'
     *                                             keeps behaving exactly as it did before.
     * @return array<int,array<string,mixed>>|null Parsed items, or null when an item
     *                                             lacked a snippet and the render must abort.
     */
    private static function parseYoutubeItems(
        array $rawItems,
        string $sortMode,
        bool $useSeasonEpisode,
        string $seasonEpisodeRegex,
        $loadFullPlaylist
    ): ?array {
        $parsedData = [];

        // Used to check if an episode (or season/episode pair) was already
        // retrieved, so duplicate uploads of the same numbered episode are
        // collapsed. In season mode the key combines season and episode so
        // that e.g. S5E14 and S6E14 are kept as distinct items.
        $youtubeEpisodeNumberCollected = [];

        // Loop Through Video Items And Parse Accordingly
        foreach ($rawItems as $item) {
            $itemOutput = [];
            if ($item) {
                if ($item['snippet']) {
                    $snippet = $item['snippet'];
                    if ($snippet['title'] !== '') {
                        if ($useSeasonEpisode) {
                            $parsed        = self::seasonEpisodeGenerator($snippet['title'], $seasonEpisodeRegex);
                            $dedupKey      = $parsed['season'] . '_' . $parsed['episode'];
                            if (in_array($dedupKey, $youtubeEpisodeNumberCollected, true)) {
                                continue;
                            }
                            $itemOutput['season']  = $parsed['season'];
                            $itemOutput['episode'] = $parsed['episode'];
                            array_push($youtubeEpisodeNumberCollected, $dedupKey);
                        } else if ($sortMode === 'number_in_title') {
                            $episodeNumber = self::episodeNumberGenerator($snippet['title']);
                            if (in_array($episodeNumber, $youtubeEpisodeNumberCollected)) {
                                continue;
                            }
                            $itemOutput['episode'] = $episodeNumber;
                            array_push($youtubeEpisodeNumberCollected, $episodeNumber);
                        } else {
                            $itemOutput['episode'] = -1;
                        }
                        $itemOutput['title'] = $snippet['title'];
                    } else {
                        $itemOutput['title']   = null;
                        $itemOutput['episode'] = -1;
                    }
                    if ($snippet['resourceId'] && $snippet['resourceId']['videoId']) {
                        $itemOutput['id'] = $snippet['resourceId']['videoId'];
                    } else {
                        $itemOutput['id'] = null;
                    }
                    if ($snippet['thumbnails']) {
                        $thumbnails = $snippet['thumbnails'];
                        if ($thumbnails['maxres']) {
                            $itemOutput['thumbnail'] = $thumbnails['maxres'];
                        } else if ($thumbnails['standard']) {
                            $itemOutput['thumbnail'] = $thumbnails['standard'];
                        } else if ($thumbnails['high']) {
                            $itemOutput['thumbnail'] = $thumbnails['high'];
                        } else if ($thumbnails['medium']) {
                            $itemOutput['thumbnail'] = $thumbnails['medium'];
                        } else if ($thumbnails['default']) {
                            $itemOutput['default'] = $thumbnails['default'];
                        }
                    } else {
                        $itemOutput['thumbnail'] = null;
                    }
                    if ($snippet['publishedAt'] !== '') {
                        $itemOutput['publishedDate'] = $snippet['publishedAt'];
                    } else {
                        $itemOutput['publishedDate'] = null;
                    }
                    if ($snippet['description'] !== '') {
                        $itemOutput['description'] = $snippet['description'];
                    } else {
                        $itemOutput['description'] = null;
                    }
                } else {
                    return null;
                }
            } else {
                $itemOutput['id'] = null;
            }

            if ($itemOutput['thumbnail'] !== null) {
                array_push($parsedData, $itemOutput);
            }
        }

        // If $sortMode Was Set To "number_in_title", Sort List Based Upon Episode Number Generated.
        // In season/episode regex mode, sort by season first (descending), then
        // episode (descending), so newer seasons lead and episodes order within them.
        if ($useSeasonEpisode) {
            $seasonValues  = array_column($parsedData, 'season');
            $episodeValues = array_column($parsedData, 'episode');
            array_multisort($seasonValues, SORT_DESC, $episodeValues, SORT_DESC, $parsedData);
        } else if ($sortMode === 'number_in_title') {
            $keyValues = array_column($parsedData, 'episode');
            array_multisort($keyValues, SORT_DESC, $parsedData);
        }

        // Checks to make sure there aren't any Items with the same title. If so, it is removed
        $preDuplicateRemoval = $parsedData;

        foreach ($preDuplicateRemoval as $index => $video) {
            if (isset($video['title']) && isset($preDuplicateRemoval[$index + 1]['title']) && $index + 1 < count($preDuplicateRemoval)) {
                if ($video['title'] === $preDuplicateRemoval[$index + 1]['title']) {
                    unset($preDuplicateRemoval[$index + 1]);
                }
            }
        }

        // If $loadFullPlaylist is not set to true, remove all items after index of 5 from array, limiting list to 6 items
        $youtubeListOutput = $preDuplicateRemoval;

        if ($loadFullPlaylist !== true) {
            foreach ($youtubeListOutput as $index => $video) {
                if ($index > 5) {
                    unset($youtubeListOutput[$index]);
                }
            }
        }
        return array_values($youtubeListOutput);
    }

    /**
     * Fetches and caches podcast data for a given platform type.
     *
     * Platform dispatch:
     * - 'omny', 'soundcloud', 'buzzsprout', 'other' — looks up the RSS feed URL
     *   via the iTunes API (using the numeric Apple podcast ID in media_data),
     *   then fetches and parses the RSS feed through {@see self::parseRssFeed()}.
     * - 'embed' — stores the embed URL string directly as parsedData; no RSS
     *   fetch is performed, so no extension hook fires and no backup is written.
     * - 'custom' — treats media_data as a direct RSS URL and parses it.
     *
     * Every platform that actually completes a remote RSS pipeline normalizes the
     * parsed feed to a plain array via {@see MediaStore::normalizePodcastData()}
     * and hands it to {@see MediaStore::store()}, which fires the pre-store
     * filter and writes both the transient (as a JSON string, the format existing
     * readers expect) and the backup JSON file. A lookup that succeeds but whose
     * RSS fetch or parse then fails never reaches the store, so no hook fires and
     * no stored data is replaced.
     *
     * @param array<string,mixed> $config Resolved media config array.
     * @param array<string,mixed> &$state Mutable state. Sets 'parsedData' and 'errorLoadingData'.
     * @return void
     */
    private static function loadPodcastData(array $config, array &$state): void
    {
        $type            = $config['type'];
        $podcastPlatform = $config['podcast_platform'];
        $playlistName    = $config['playlist_name'];
        $mediaData       = $config['media_data'];
        $mediaCacheTtl   = (int) $config['media_cache_ttl'];

        // Embed Podcast Url Without Direct RSS Feed. No API call is made, so
        // there is no successful remote refresh to expose and the stored format
        // stays the bare URL string existing readers already handle.
        if ($podcastPlatform === 'embed') {
            $state['parsedData'] = $mediaData;
            set_transient($type . '_' . $playlistName, json_encode($mediaData), $mediaCacheTtl);

            return;
        }

        $isAppleRss = $podcastPlatform !== 'custom';

        if ($isAppleRss) {
            $lookup = self::trackedRemoteGet(
                'https://itunes.apple.com/lookup?id=' . rawurlencode((string) $mediaData) . '&entity=podcast',
                [
                    'playlist_name' => $playlistName,
                    'type' => $type,
                    'endpoint' => 'podcast_lookup',
                ]
            );

            if (is_wp_error($lookup) || wp_remote_retrieve_response_code($lookup) !== 200) {
                $state['errorLoadingData'] = true;

                return;
            }

            // parseRssFeed() expects the iTunes JSON *body*, not the response
            // array, and uses it to resolve the feed URL it then fetches.
            $parsed = self::parseRssFeed((string) wp_remote_retrieve_body($lookup), true, [
                'playlist_name' => $playlistName,
                'type' => $type,
            ]);
        } else {
            // Direct RSS Feed Url
            $parsed = self::parseRssFeed((string) $mediaData, false, [
                'playlist_name' => $playlistName,
                'type' => $type,
            ]);
        }

        if (!$parsed) {
            $state['errorLoadingData'] = true;

            return;
        }

        if (!$isAppleRss) {
            $parsed->channel->rssUrl = $mediaData;
        }

        $normalized = MediaStore::normalizePodcastData($parsed);

        if ($normalized === null) {
            $state['errorLoadingData'] = true;

            return;
        }

        $store = MediaStore::store(
            MediaStore::buildContext(
                (string) $playlistName,
                'podcast',
                MediaStore::SOURCE_REMOTE_REFRESH,
                (string) $podcastPlatform
            ),
            $normalized,
            ['ttl' => $mediaCacheTtl, 'write_backup' => true]
        );

        if ($store['data'] !== null) {
            $state['parsedData'] = $store['data'];
        }

        if (!$store['ok']) {
            $state['errorLoadingData'] = true;
        }
    }

    /**
     * Emits inline `<script>` tags that update browser localStorage and log status.
     *
     * Handles five distinct states:
     * 1. Error + cookie expired  — logs to console.error, expires cookie, loads backup JSON
     *    into localStorage if available (for both YouTube and podcast).
     * 2. Error + cookie not expired — logs that data is being loaded from localStorage.
     * 3. Success + cookie expired — calls localStorage.setItem() with the fresh data JSON
     *    and logs the load source (API or server cache).
     * 4. Success + cookie not expired — logs that localStorage is still fresh.
     * 5. YouTube in-progress — emits an additional console.warn when a prior request
     *    is still running.
     *
     * @param array<string,mixed> $config Resolved media config array.
     * @param array<string,mixed> $state  Finalized state after data loading.
     * @return void
     */
    private static function renderMediaDataStatusScripts(array $config, array $state): void
    {
        $type             = $config['type'];
        $playlistName     = $config['playlist_name'];
        $cookieName       = $config['cookie_name'];
        $cookieExpired    = $config['cookie_expired'];
        $parsedData       = $state['parsedData'];
        $errorLoadingData = $state['errorLoadingData'];
        $dataLoadedMethod = $state['dataLoadedMethod'];
        $youtubeErrorTtl  = (int) $config['youtube_error_ttl'];

        // Log If There Was An Error Getting The Data And Clear Cookie
        if ($errorLoadingData) {
            if ($type === 'youtube' && !get_transient($playlistName . '_youtube_error')) {
                set_transient($playlistName . '_youtube_error', true, $youtubeErrorTtl);
            }
            if ($type === 'youtube' && get_transient($playlistName . '_youtube_request_in_progress')) {
                echo '<script>console.warn("A previous request for YouTube data is still in progress.  This may be the reason for the error in loading the data.  Please wait a moment and try reloading the page.")</script>';
            }
            echo '<script>
                    console.error("There was an error getting the ' . $playlistName . '_' . $type . '_playlist data.  Check your internet connection, try reloading or check the API key/media data.");
                </script>';
            if ($cookieName) {
                echo '<script>
                    document.cookie = "' . $cookieName . '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                </script>';
            }

            // Load backup server data if type is youtube
            if ($type === 'youtube') {
                $youtubeBackupFilePath = self::backupDir() . $playlistName . '_youtube_backup_data.json';

                if (file_exists($youtubeBackupFilePath) && is_readable($youtubeBackupFilePath)) {
                    $parsedBackupData = json_decode(file_get_contents($youtubeBackupFilePath), true);
                    if (!empty($parsedBackupData['data'])) {
                        echo '<script>localStorage.setItem("' . $playlistName . '_' . $type . '_playlist", JSON.stringify(' . json_encode($parsedBackupData['data']) . ')); console.log("`' . $playlistName . '` ' . $type . ' data loaded from Backup storage and saved on Local Storage as `' . $playlistName . '_' . $type . '_playlist.` as there was an error when making a call to the youtube API.");</script>';
                    }
                }
            }

            if ($type === 'podcast') {
                $podcastBackupFilePath = self::podcastBackupFilePath($playlistName);

                if (file_exists($podcastBackupFilePath) && is_readable($podcastBackupFilePath)) {
                    $parsedBackupData = json_decode(file_get_contents($podcastBackupFilePath), true);
                    if (!empty($parsedBackupData['data'])) {
                        echo '<script>localStorage.setItem("' . $playlistName . '_' . $type . '_playlist", JSON.stringify(' . json_encode($parsedBackupData['data']) . ')); console.log("`' . $playlistName . '` ' . $type . ' data loaded from Backup storage and saved on Local Storage as `' . $playlistName . '_' . $type . '_playlist.` as there was an error when making a call to the podcast RSS feed.");</script>';
                    }
                }
            }
        }

        // Data Stored In Local Storage If Cookie Expired And API Call Was Made With No Errors
        if ($cookieExpired && !$errorLoadingData) {
            // Set Cookie Storage Time Stamp And Output Parsed data Into Browser Local Storage
            echo '<script>localStorage.setItem("' . $playlistName . '_' . $type . '_playlist", JSON.stringify(' . json_encode($parsedData) . ')); console.log("`' . $playlistName . '` ' . $type . ' data loaded from ' . $dataLoadedMethod . ' and saved on Local Storage as `' . $playlistName . '_' . $type . '_playlist.`.");</script>';
        }

        // Data Loaded From Local Storage Due To An Error On API Call With Cookie Expired
        if ($cookieExpired && $errorLoadingData) {
            echo '<script>
                    console.log("`' . $playlistName . '` ' . $type . ' data loaded from Local Storage as there was an error loading the data.  Try checking your internet connection and reload the page.");
                </script>
            ';
        }

        // Data Loaded From Local Storage If Cookie Did Not Expire
        if (!$cookieExpired) {
            echo '<script>
                    console.log("`' . $playlistName . '` ' . $type . ' data loaded from Local Storage as cookie storage time interval has not yet passed.");
                </script>
            ';
        }
    }

    /**
     * Emits the inline `<script>` block that initializes a single media widget.
     *
     * All dynamic PHP values are encoded with wp_json_encode() and the block is
     * wrapped in an IIFE so that playlist names containing hyphens, digits, or
     * other characters that are invalid in JS identifiers cannot break the script.
     *
     * @param array<string,mixed> $config Resolved media config array.
     * @return void
     */
    private static function renderMediaInitializationScript(array $config): void
    {
        $type            = $config['type'];
        $podcastPlatform = $config['podcast_platform'];
        $playlistName    = $config['playlist_name'];
        $cookieName      = $config['cookie_name'];

        $jsName     = wp_json_encode($playlistName);
        $jsType     = wp_json_encode($type);
        $jsPlatform = wp_json_encode($podcastPlatform);
        $jsCookie   = wp_json_encode($cookieName);
        $jsKey      = wp_json_encode($playlistName . '_' . $type . '_playlist');
        $jsLabel    = wp_json_encode($playlistName . '_' . $type);
        ?>

        <!-- Media API "<?= esc_html($playlistName) ?>_<?= esc_html($type) ?>" Code Start -->

        <script>
        (function () {
            const storageKey = <?= $jsKey ?>;
            const label      = <?= $jsLabel ?>;
            const mediaData  = JSON.parse(localStorage.getItem(storageKey));

            if (!mediaData && <?= $jsCookie ?>) {
                document.cookie = <?= $jsCookie ?> + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            }

            if (!mediaData) {
                console.error("No data found for `" + label + "` or data in Local Storage was deleted.  Check your internet connection and reload the page.");
            }

            if (mediaData && mediaData.length === 0) {
                console.error("`" + label + "` data cannot be loaded.. Data is empty.");
            }

            window.addEventListener("load", () => {
                const media_items = document.querySelectorAll('[data-playlistname="' + <?= $jsName ?> + '"]');

                initialize_media(
                    [...media_items].filter(item => item.dataset.mediaplatform === <?= $jsType ?> && item.dataset.playlistname === <?= $jsName ?>),
                    mediaData,
                    <?= $jsName ?>,
                    <?= $jsType ?>,
                    <?= $jsPlatform ?>
                );
            });
        })();
        </script>
        <!-- Media API "<?= esc_html($playlistName) ?>_<?= esc_html($type) ?>" Code End -->
        <?php
    }

    /**
     * No-op stub retained for backward compatibility.
     *
     * SEO meta tags are now rendered server-side by
     * {@see \MediaApiWidget\Seo\MetaUpdater} via wp_head. This method exists
     * only to avoid fatal errors in any code that still calls it directly.
     *
     * @return void
     */
    public static function renderMetaDataUpdaterScript(): void
    {
        // SEO tags are now rendered server-side by MetaUpdater.
    }
}
