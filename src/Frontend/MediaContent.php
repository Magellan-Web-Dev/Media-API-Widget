<?php
namespace MediaApiWidget\Frontend;

use MediaApiWidget\Config\Options;

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
 * YouTube data is also persisted to a local backup JSON file so it can be
 * served on subsequent requests if the API is temporarily unavailable.
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
     * @return string Absolute directory path with trailing slash.
     */
    public static function backupDir(): string
    {
        $upload = wp_upload_dir();
        $base = rtrim($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads', '/');
        $dir = $base . '/media-api-widget/backups';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        return $dir . '/';
    }

    /**
     * Extracts a leading episode number from a video title string.
     *
     * Scans character-by-character from the start of the title, collecting
     * consecutive digit characters. Returns the parsed integer when it is
     * non-zero and less than 2000 (to exclude year-format numbers). Returns
     * -1 when no qualifying episode number is found.
     *
     * @param string $title The video title to parse (e.g. "042 - Episode Name").
     * @return int Episode number (1–1999), or -1 if none found.
     */
    public static function episodeNumberGenerator(string $title): int
    {
        $output = '';
        $splitter = str_split($title);
        foreach ($splitter as $char) {
            if (preg_match('/[0-9]/', $char) === 1) {
                $output .= $char;
            } else {
                if ($output !== '') {
                    break;
                }
            }
        }
        $output = intval($output);
        if ($output !== 0 && $output < 2000) {
            return intval($output);
        }
        return -1;
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
        $response = wp_remote_get($url);

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

            $parsedRssFeed = simplexml_load_string($rssBody);
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
     * within the plugin's backup directory.
     *
     * @param string $playlistName The playlist_name slug.
     * @return string Absolute file path.
     */
    private static function podcastBackupFilePath(string $playlistName): string
    {
        return self::backupDir() . $playlistName . '_podcast_backup_data.json';
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
            'load_full_playlist' => $params['load_full_playlist'] ?? false,
            'cookie_name' => $params['cookie_name'] ?? null,
            'cookie_expired' => $params['cookie_expired'] ?? true,
            'media_cache_ttl' => $cacheExpirations['media_cache_ttl'],
            'youtube_request_in_progress_ttl' => $cacheExpirations['youtube_request_in_progress_ttl'],
            'youtube_error_ttl' => $cacheExpirations['youtube_error_ttl'],
            'youtube_backup_window_seconds' => $cacheExpirations['youtube_backup_window_seconds'],
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
     * Paginates through all results (50 items per page) using nextPageToken.
     * Parses title, video ID, thumbnail, episode number (when sort_mode is
     * 'number_in_title'), publishedDate, and description for each item. Deduplicates
     * by title and optionally trims to the first six items unless load_full_playlist
     * is set. Writes results to the backup JSON file, the transient cache, and a
     * wp_options timestamp record. Clears error and in-progress transients on success.
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
        $mediaCacheTtl                 = (int) $config['media_cache_ttl'];
        $youtubeRequestInProgressTtl   = (int) $config['youtube_request_in_progress_ttl'];
        $youtubeBackupWindowSeconds    = (int) $config['youtube_backup_window_seconds'];

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

        $youtubeReqUrl = 'https://youtube.googleapis.com/youtube/v3/playlistItems?part=snippet&playlistId=' . $mediaData . '&key=' . $apiKey . '&maxResults=50';
        set_transient($playlistName . '_youtube_request_in_progress', true, $youtubeRequestInProgressTtl);

        $youtubeGetReq = self::trackedRemoteGet($youtubeReqUrl, [
            'playlist_name' => $playlistName,
            'type' => $type,
            'endpoint' => 'youtube_playlist_items',
        ]);

        if (is_wp_error($youtubeGetReq) || wp_remote_retrieve_response_code($youtubeGetReq) !== 200) {
            $state['errorLoadingData'] = true;
            return;
        }

        $youtubeData        = json_decode(wp_remote_retrieve_body($youtubeGetReq), true);
        $youtubeItems       = $youtubeData['items'];
        $youtubeItemsTally  = count($youtubeData['items']);
        $nextPageToken      = $youtubeData['nextPageToken'] ?? null;

        // Youtube Has A Limit Of 50 Results Per Request. If Playlist Item Total Is Greater Than 50, Then A While Loop Runs Until Total Items Received Equals Total Results
        while ($youtubeData['pageInfo']['totalResults'] > $youtubeItemsTally) {
            $youtubeLoop = self::trackedRemoteGet($youtubeReqUrl . '&pageToken=' . $nextPageToken, [
                'playlist_name' => $playlistName,
                'type' => $type,
                'endpoint' => 'youtube_playlist_items',
            ]);
            if (is_wp_error($youtubeLoop) || wp_remote_retrieve_response_code($youtubeLoop) !== 200) {
                $state['errorLoadingData'] = true;
                break;
            }
            $youtubeLoop        = json_decode(wp_remote_retrieve_body($youtubeLoop), true);
            $youtubeItemsTally += count($youtubeLoop['items']);
            $youtubeItems       = array_merge($youtubeItems, $youtubeLoop['items']);
            $nextPageToken      = $youtubeLoop['nextPageToken'] ?? null;
            if (!$nextPageToken) {
                break;
            }
        }

        // Combine All Youtube Video Items Together From While Loop If Looped Through
        $youtubeData['items'] = $youtubeItems;

        // Used to check if episode number already retrieved
        $youtubeEpisodeNumberCollected = [];

        // Loop Through Video Items And Parse Accordingly
        foreach ($youtubeData['items'] as $item) {
            $itemOutput = [];
            if ($item) {
                if ($item['snippet']) {
                    $snippet = $item['snippet'];
                    if ($snippet['title'] !== '') {
                        if ($sortMode === 'number_in_title') {
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
                    $state['abort'] = true;
                    return;
                }
            } else {
                $itemOutput['id'] = null;
            }

            if ($itemOutput['thumbnail'] !== null) {
                array_push($state['parsedData'], $itemOutput);
            }
        }

        // If $sortMode Was Set To "number_in_title", Sort List Based Upon Episode Number Generated
        if ($sortMode === 'number_in_title') {
            $keyValues = array_column($state['parsedData'], 'episode');
            array_multisort($keyValues, SORT_DESC, $state['parsedData']);
        }

        // Checks to make sure there aren't any Items with the same title. If so, it is removed
        $preDuplicateRemoval = $state['parsedData'];

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
        $state['parsedData'] = array_values($youtubeListOutput);

        // Check backup youtube stored data to see if it is older than 6 hours. If so, update youtube data backup file
        $backupData = json_encode([
            'time_stored' => time(),
            'data' => $state['parsedData'],
        ]);

        $youtubeBackupFilePath = self::backupDir() . $playlistName . '_youtube_backup_data.json';
        file_put_contents($youtubeBackupFilePath, $backupData);

        // Set parsed data to Wordpress transient server cache
        set_transient($type . '_' . $playlistName, $state['parsedData'], $mediaCacheTtl);

        // Clear Youtube API Error Transient If Data Successfully Retrieved
        delete_transient($playlistName . '_youtube_error');

        // Clear Youtube API Request In Progress Transient If Data Successfully Retrieved
        delete_transient($playlistName . '_youtube_request_in_progress');

        // Persist successful fetch timestamp so rate limiting does not rely on transient durability.
        update_option($lastFetchedOptionKey, time(), false);
    }

    /**
     * Fetches and caches podcast data for a given platform type.
     *
     * Platform dispatch:
     * - 'omny', 'soundcloud', 'buzzsprout', 'other' — looks up the RSS feed URL
     *   via the iTunes API (using the numeric Apple podcast ID in media_data),
     *   then fetches and parses the RSS feed through {@see self::parseRssFeed()}.
     * - 'embed' — stores the embed URL string directly as parsedData; no RSS
     *   fetch is performed.
     * - 'custom' — treats media_data as a direct RSS URL, parses it, writes a
     *   backup JSON file, and stores the result in the transient.
     *
     * On success, JSON-encodes parsedData and writes it to the transient for
     * `media_cache_ttl` seconds.
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

        $getRss = null;

        if ($podcastPlatform !== 'embed' && $podcastPlatform !== 'custom') {
            if ($podcastPlatform === 'omny' || $podcastPlatform === 'soundcloud' || $podcastPlatform === 'buzzsprout' || $podcastPlatform === 'other') {
                $getRss = self::trackedRemoteGet('https://itunes.apple.com/lookup?id=' . $mediaData . '&entity=podcast', [
                    'playlist_name' => $playlistName,
                    'type' => $type,
                    'endpoint' => 'podcast_lookup',
                ]);
            }

            if (!$getRss && $podcastPlatform !== 'custom') {
                $state['errorLoadingData'] = true;
            } else {
                $isAppleRss          = $podcastPlatform !== 'custom';
                $state['parsedData'] = self::parseRssFeed($getRss, $isAppleRss, [
                    'playlist_name' => $playlistName,
                    'type' => $type,
                ]);
                if (!$state['parsedData']) {
                    $state['errorLoadingData'] = true;
                }
            }
        } else {
            // Embed Podcast Url Without Direct RSS Feed
            if ($podcastPlatform === 'embed') {
                $state['parsedData'] = $mediaData;
            }

            // Direct RSS Feed Url
            if ($podcastPlatform === 'custom') {
                $state['parsedData'] = self::parseRssFeed($mediaData, false, [
                    'playlist_name' => $playlistName,
                    'type' => $type,
                ]);
                if ($state['parsedData']) {
                    $state['parsedData']->channel->rssUrl = $mediaData;
                    $backupData = json_encode([
                        'time_stored' => time(),
                        'data' => $state['parsedData'],
                    ]);
                    file_put_contents(self::podcastBackupFilePath($playlistName), $backupData);
                } else {
                    $state['errorLoadingData'] = true;
                }
            }
        }

        // Set parsed data to Wordpress transient server cache
        if (!$state['errorLoadingData']) {
            set_transient($type . '_' . $playlistName, json_encode($state['parsedData']), $mediaCacheTtl);
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
     * Outputs a script that:
     * 1. Reads `{playlist_name}_{type}_data` from localStorage.
     * 2. Expires the cookie and logs an error if no data was found.
     * 3. Logs an error if the data array is empty.
     * 4. On the window 'load' event, queries all `[data-playlistname]` elements
     *    matching this item's name and type, then calls `initialize_media()`.
     *
     * The emitted JavaScript variable name is `{playlist_name}_{type}_data`, so
     * `playlist_name` values must be valid JavaScript identifier segments (i.e.
     * the `sanitize_key()` output used throughout the plugin satisfies this).
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
        ?>

        <!-- Media API "<?= $playlistName ?>_<?= $type ?>" Code Start -->

        <script>

            // Load <?= $type ?> Data From Local Storage

            const <?= $playlistName ?>_<?= $type ?>_data = JSON.parse(localStorage.getItem("<?= $playlistName ?>_<?= $type ?>_playlist"));

            // If No Data Found In Local Storage, Clear Cookie

            if (!<?= $playlistName ?>_<?= $type ?>_data && "<?= $cookieName ?>") {
                document.cookie = "<?= $cookieName ?>=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            }

            // Console Error If No Data Found

            if (!<?= $playlistName ?>_<?= $type ?>_data) {
                console.error("No data found for `<?= $playlistName ?>_<?= $type ?>` or data in Local Storage was deleted.  Check your internet connection and reload the page.");
            }

            if (<?= $playlistName ?>_<?= $type ?>_data && <?= $playlistName ?>_<?= $type ?>_data.length === 0) {
                console.error("`<?= $playlistName ?>_<?= $type ?>` data cannot be loaded.. Data is empty.");
            }

            // Initialize Media

            window.addEventListener("load", () => {
                const media_items = document.querySelectorAll(`[data-playlistname="<?= $playlistName ?>"]`);

                initialize_media([...media_items].filter(item => item.dataset.mediaplatform === "<?= $type ?>" && item.dataset.playlistname === "<?= $playlistName ?>"),
                    <?= $playlistName ?>_<?= $type ?>_data,
                    "<?= $playlistName ?>", "<?= $type ?>",
                    "<?= $podcastPlatform ?>"
                );
            });

        </script>
        <!-- Media API "<?= $playlistName ?>_<?= $type ?>" Code End -->
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
