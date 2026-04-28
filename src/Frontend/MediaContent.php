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
    public static function backup_dir(): string
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
    public static function episode_number_generator(string $title): int
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
     * @param string $playlist_name Playlist/feed slug for the log row.
     * @param string $type          Media type ('youtube' or 'podcast').
     * @param string $endpoint      Endpoint identifier (e.g. 'youtube_playlist_items').
     * @param mixed  $response      Raw return value from wp_remote_get().
     * @return void
     */
    public static function log_api_call_event(string $playlist_name, string $type, string $endpoint, $response): void
    {
        if (!class_exists('\\MediaApiWidget\\Stats\\ApiCallLogger')) {
            return;
        }

        \MediaApiWidget\Stats\ApiCallLogger::log(
            (string) $playlist_name,
            (string) $type,
            (string) $endpoint,
            $response
        );
    }

    /**
     * Wraps wp_remote_get() with automatic API call logging.
     *
     * Makes the HTTP request, then calls {@see self::log_api_call_event()}
     * with the context metadata and the raw response. The context array
     * should contain 'playlist_name', 'type', and 'endpoint' keys.
     *
     * @param string              $url     The URL to fetch.
     * @param array<string,mixed> $context Metadata for the log entry.
     * @return array|\WP_Error    Raw wp_remote_get() return value.
     */
    public static function tracked_remote_get(string $url, array $context = [])
    {
        $response = wp_remote_get($url);

        self::log_api_call_event(
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
     * When `$apple_data` is true, treats `$rss_feed_input` as a raw JSON
     * response from the iTunes lookup API, extracts the feedUrl from the first
     * result, and fetches that RSS URL. When false, `$rss_feed_input` is used
     * directly as the RSS URL.
     *
     * Strips HTML tags from each episode's title and description. Attaches
     * the RSS URL and Apple collection view URL to the parsed feed's channel
     * element. Returns null on any network or parse error.
     *
     * @param string|null         $rss_feed_input RSS URL (when $apple_data is false) or
     *                                            iTunes lookup JSON body (when true).
     * @param bool                $apple_data     True when $rss_feed_input is iTunes JSON.
     * @param array<string,mixed> $context        Metadata for API call logging.
     * @return \SimpleXMLElement|null Parsed RSS document, or null on failure.
     */
    public static function parse_rss_feed(?string $rss_feed_input = null, bool $apple_data = false, array $context = [])
    {
        if ($rss_feed_input) {
            $rss_url = null;
            $get_rss_data = null;

            if ($apple_data) {
                $get_rss_data = json_decode($rss_feed_input);

                if (!$get_rss_data || empty(get_object_vars($get_rss_data)['results'][0]->feedUrl)) {
                    return null;
                }

                // Extract RSS Feed Url From JSON data from iTunes
                $rss_url = get_object_vars($get_rss_data)['results'][0]->feedUrl;

                $rss_feed = self::tracked_remote_get($rss_url, [
                    'playlist_name' => $context['playlist_name'] ?? '',
                    'type' => $context['type'] ?? 'podcast',
                    'endpoint' => 'podcast_rss',
                ]);
            } else {
                $rss_url = $rss_feed_input;
                $rss_feed = self::tracked_remote_get($rss_url, [
                    'playlist_name' => $context['playlist_name'] ?? '',
                    'type' => $context['type'] ?? 'podcast',
                    'endpoint' => 'podcast_rss',
                ]);
            }

            if (is_wp_error($rss_feed) || wp_remote_retrieve_response_code($rss_feed) !== 200) {
                return null;
            }

            $rss_body = wp_remote_retrieve_body($rss_feed);
            if ($rss_body === '') {
                return null;
            }

            $parsed_rss_feed = simplexml_load_string($rss_body);
            if (!$parsed_rss_feed) {
                return null;
            }

            $parsed_rss_feed->channel->rssUrl = $rss_url;
            $parsed_rss_feed->channel->collectionViewUrl = $apple_data && !empty(get_object_vars($get_rss_data)['results'][0]->collectionViewUrl)
                ? get_object_vars($get_rss_data)['results'][0]->collectionViewUrl
                : $rss_url;

            foreach ($parsed_rss_feed->channel->item as $item) {
                $description_text = strip_tags($item->description);
                $item->description = strip_tags($description_text);
                $title_text = strip_tags($item->title);
                $item->title = $title_text;
            }

            return $parsed_rss_feed;
        }

        return null;
    }

    /**
     * Returns the absolute backup file path for a podcast playlist.
     *
     * The file name follows the pattern `{playlist_name}_podcast_backup_data.json`
     * within the plugin's backup directory.
     *
     * @param string $playlist_name The playlist_name slug.
     * @return string Absolute file path.
     */
    private static function podcast_backup_file_path(string $playlist_name): string
    {
        return self::backup_dir() . $playlist_name . '_podcast_backup_data.json';
    }

    /**
     * Main entry point: loads media data and emits initialization scripts.
     *
     * Builds the resolved config from `$params` plus stored TTL settings, then:
     * 1. When the cookie is expired, calls {@see self::load_media_data_when_cookie_expired()}
     *    to populate $state with fresh data (from cache or API).
     * 2. If an abort flag was set (e.g. malformed YouTube response), returns early.
     * 3. Calls {@see self::render_media_data_status_scripts()} to emit localStorage
     *    update scripts or error/fallback scripts.
     * 4. Calls {@see self::render_media_initialization_script()} to emit the
     *    window.initialize_media() call script.
     *
     * @param array<string,mixed> $params Media item config from MediaBootstrap.
     * @return void
     */
    public static function get_media_content(array $params): void
    {
        $config = self::build_media_config($params);
        $state = [
            'parsed_data' => [],
            'error_loading_data' => false,
            'data_loaded_method' => 'API',
            'abort' => false,
        ];

        if ($config['cookie_expired']) {
            self::load_media_data_when_cookie_expired($config, $state);
        }

        if ($state['abort']) {
            return;
        }

        self::render_media_data_status_scripts($config, $state);
        self::render_media_initialization_script($config);
    }

    /**
     * Builds the resolved media config array for a single item.
     *
     * Merges the caller-supplied $params with the current plugin TTL settings
     * from {@see Options::getCacheExpirations()} so downstream methods have a
     * single flat array with all values they need.
     *
     * @param array<string,mixed> $params Raw params from {@see get_media_content()}.
     * @return array<string,mixed> Resolved config including all TTL values.
     */
    private static function build_media_config(array $params): array
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
     * $state['parsed_data'] and sets the loaded method to 'server cache'. If
     * not found, delegates to {@see self::load_youtube_data()} or
     * {@see self::load_podcast_data()} depending on the media type.
     *
     * @param array<string,mixed> $config Resolved media config array.
     * @param array<string,mixed> &$state Mutable state passed through the pipeline.
     * @return void
     */
    private static function load_media_data_when_cookie_expired(array $config, array &$state): void
    {
        $type = $config['type'];
        $playlist_name = $config['playlist_name'];

        // Check if data is stored in Cache in Wordpress Transients
        $cached_data = get_transient($type . '_' . $playlist_name);

        if ($cached_data !== false) {
            if ($type === 'podcast') {
                $state['parsed_data'] = json_decode($cached_data, true);
            } else {
                $state['parsed_data'] = $cached_data;
            }
            $state['data_loaded_method'] = 'server cache';
        }

        if ($type === 'youtube' && $cached_data === false) {
            self::load_youtube_data($config, $state);
        }

        if ($type === 'podcast' && $cached_data === false) {
            self::load_podcast_data($config, $state);
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
     * @param array<string,mixed> &$state Mutable state. Sets 'parsed_data', 'error_loading_data', or 'abort'.
     * @return void
     */
    private static function load_youtube_data(array $config, array &$state): void
    {
        $type = $config['type'];
        $playlist_name = $config['playlist_name'];
        $api_key = $config['api_key'];
        $media_data = $config['media_data'];
        $sort_mode = $config['sort_mode'];
        $load_full_playlist = $config['load_full_playlist'];
        $media_cache_ttl = (int) $config['media_cache_ttl'];
        $youtube_request_in_progress_ttl = (int) $config['youtube_request_in_progress_ttl'];
        $youtube_backup_window_seconds = (int) $config['youtube_backup_window_seconds'];

        $previous_youtube_error = get_transient($playlist_name . '_youtube_error');
        $last_fetched_option_key = 'maw_yt_last_fetched_' . sanitize_key((string) $playlist_name);
        $last_fetched_at = (int) get_option($last_fetched_option_key, 0);
        $fetch_interval_seconds = $youtube_backup_window_seconds;

        if ($last_fetched_at > 0 && (time() - $last_fetched_at) < $fetch_interval_seconds) {
            $youtube_backup_file_path = self::backup_dir() . $playlist_name . '_youtube_backup_data.json';

            if (file_exists($youtube_backup_file_path) && is_readable($youtube_backup_file_path)) {
                $parsed_backup_data = json_decode(file_get_contents($youtube_backup_file_path), true);
                if (!empty($parsed_backup_data['data']) && is_array($parsed_backup_data['data'])) {
                    $state['parsed_data'] = $parsed_backup_data['data'];
                    $state['data_loaded_method'] = 'backup cache (rate limit)';
                    $state['error_loading_data'] = true;
                }
            } else {
                $state['error_loading_data'] = true;
            }
            return;
        }

        if ($previous_youtube_error || get_transient($playlist_name . '_youtube_request_in_progress')) {
            $state['error_loading_data'] = true;
            return;
        }

        $youtube_req_url = 'https://youtube.googleapis.com/youtube/v3/playlistItems?part=snippet&playlistId=' . $media_data . '&key=' . $api_key . '&maxResults=50';
        set_transient($playlist_name . '_youtube_request_in_progress', true, $youtube_request_in_progress_ttl);

        $youtube_get_req = self::tracked_remote_get($youtube_req_url, [
            'playlist_name' => $playlist_name,
            'type' => $type,
            'endpoint' => 'youtube_playlist_items',
        ]);

        if (is_wp_error($youtube_get_req) || wp_remote_retrieve_response_code($youtube_get_req) !== 200) {
            $state['error_loading_data'] = true;
            return;
        }

        $youtube_data = json_decode(wp_remote_retrieve_body($youtube_get_req), true);
        $youtube_items = $youtube_data['items'];
        $youtube_items_tally = count($youtube_data['items']);
        $nextPageToken = $youtube_data['nextPageToken'] ?? null;

        // Youtube Has A Limit Of 50 Results Per Request. If Playlist Item Total Is Greater Than 50, Then A While Loop Runs Until Total Items Received Equals Total Results
        while ($youtube_data['pageInfo']['totalResults'] > $youtube_items_tally) {
            $youtube_loop = self::tracked_remote_get($youtube_req_url . '&pageToken=' . $nextPageToken, [
                'playlist_name' => $playlist_name,
                'type' => $type,
                'endpoint' => 'youtube_playlist_items',
            ]);
            if (is_wp_error($youtube_loop) || wp_remote_retrieve_response_code($youtube_loop) !== 200) {
                $state['error_loading_data'] = true;
                break;
            }
            $youtube_loop = json_decode(wp_remote_retrieve_body($youtube_loop), true);
            $youtube_items_tally += count($youtube_loop['items']);
            $youtube_items = array_merge($youtube_items, $youtube_loop['items']);
            $nextPageToken = $youtube_loop['nextPageToken'] ?? null;
            if (!$nextPageToken) {
                break;
            }
        }

        // Combine All Youtube Video Items Together From While Loop If Looped Through
        $youtube_data['items'] = $youtube_items;

        // Used to check if episode number already retrieved
        $youtube_episode_number_collected = [];

        // Loop Through Video Items And Parse Accordingly
        foreach ($youtube_data['items'] as $item) {
            $itemOutput = [];
            if ($item) {
                if ($item['snippet']) {
                    $snippet = $item['snippet'];
                    if ($snippet['title'] !== '') {
                        if ($sort_mode === 'number_in_title') {
                            $episode_number = self::episode_number_generator($snippet['title']);
                            if (in_array($episode_number, $youtube_episode_number_collected)) {
                                continue;
                            }
                            $itemOutput['episode'] = $episode_number;
                            array_push($youtube_episode_number_collected, $episode_number);
                        } else {
                            $itemOutput['episode'] = -1;
                        }
                        $itemOutput['title'] = $snippet['title'];
                    } else {
                        $itemOutput['title'] = null;
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
                array_push($state['parsed_data'], $itemOutput);
            }
        }

        // If $sort_mode Was Set To "number_in_title", Sort List Based Upon Episode Number Generated
        if ($sort_mode === 'number_in_title') {
            $key_values = array_column($state['parsed_data'], 'episode');
            array_multisort($key_values, SORT_DESC, $state['parsed_data']);
        }

        // Checks to make sure there aren't any Items with the same title. If so, it is removed
        $pre_duplicate_removal = $state['parsed_data'];

        foreach ($pre_duplicate_removal as $index => $video) {
            if (isset($video['title']) && isset($pre_duplicate_removal[$index + 1]['title']) && $index + 1 < count($pre_duplicate_removal)) {
                if ($video['title'] === $pre_duplicate_removal[$index + 1]['title']) {
                    unset($pre_duplicate_removal[$index + 1]);
                }
            }
        }

        // If $load_full_playlist is not set to true, remove all items after index of 5 from array, limiting list to 6 items
        $youtube_list_output = $pre_duplicate_removal;

        if ($load_full_playlist !== true) {
            foreach ($youtube_list_output as $index => $video) {
                if ($index > 5) {
                    unset($youtube_list_output[$index]);
                }
            }
        }
        $state['parsed_data'] = array_values($youtube_list_output);

        // Check backup youtube stored data to see if it is older than 6 hours. If so, update youtube data backup file
        $backup_data = json_encode([
            'time_stored' => time(),
            'data' => $state['parsed_data'],
        ]);

        $youtube_backup_file_path = self::backup_dir() . $playlist_name . '_youtube_backup_data.json';
        file_put_contents($youtube_backup_file_path, $backup_data);

        // Set parsed data to Wordpress transient server cache
        set_transient($type . '_' . $playlist_name, $state['parsed_data'], $media_cache_ttl);

        // Clear Youtube API Error Transient If Data Successfully Retrieved
        delete_transient($playlist_name . '_youtube_error');

        // Clear Youtube API Request In Progress Transient If Data Successfully Retrieved
        delete_transient($playlist_name . '_youtube_request_in_progress');

        // Persist successful fetch timestamp so rate limiting does not rely on transient durability.
        update_option($last_fetched_option_key, time(), false);
    }

    /**
     * Fetches and caches podcast data for a given platform type.
     *
     * Platform dispatch:
     * - 'omny', 'soundcloud', 'buzzsprout', 'other' — looks up the RSS feed URL
     *   via the iTunes API (using the numeric Apple podcast ID in media_data),
     *   then fetches and parses the RSS feed through {@see self::parse_rss_feed()}.
     * - 'embed' — stores the embed URL string directly as parsed_data; no RSS
     *   fetch is performed.
     * - 'custom' — treats media_data as a direct RSS URL, parses it, writes a
     *   backup JSON file, and stores the result in the transient.
     *
     * On success, JSON-encodes parsed_data and writes it to the transient for
     * `media_cache_ttl` seconds.
     *
     * @param array<string,mixed> $config Resolved media config array.
     * @param array<string,mixed> &$state Mutable state. Sets 'parsed_data' and 'error_loading_data'.
     * @return void
     */
    private static function load_podcast_data(array $config, array &$state): void
    {
        $type = $config['type'];
        $podcast_platform = $config['podcast_platform'];
        $playlist_name = $config['playlist_name'];
        $media_data = $config['media_data'];
        $media_cache_ttl = (int) $config['media_cache_ttl'];

        $get_rss = null;

        if ($podcast_platform !== 'embed' && $podcast_platform !== 'custom') {
            if ($podcast_platform === 'omny' || $podcast_platform === 'soundcloud' || $podcast_platform === 'buzzsprout' || $podcast_platform === 'other') {
                $get_rss = self::tracked_remote_get('https://itunes.apple.com/lookup?id=' . $media_data . '&entity=podcast', [
                    'playlist_name' => $playlist_name,
                    'type' => $type,
                    'endpoint' => 'podcast_lookup',
                ]);
            }

            if (!$get_rss && $podcast_platform !== 'custom') {
                $state['error_loading_data'] = true;
            } else {
                $is_apple_rss = $podcast_platform !== 'custom';
                $state['parsed_data'] = self::parse_rss_feed($get_rss, $is_apple_rss, [
                    'playlist_name' => $playlist_name,
                    'type' => $type,
                ]);
                if (!$state['parsed_data']) {
                    $state['error_loading_data'] = true;
                }
            }
        } else {
            // Embed Podcast Url Without Direct RSS Feed
            if ($podcast_platform === 'embed') {
                $state['parsed_data'] = $media_data;
            }

            // Direct RSS Feed Url
            if ($podcast_platform === 'custom') {
                $state['parsed_data'] = self::parse_rss_feed($media_data, false, [
                    'playlist_name' => $playlist_name,
                    'type' => $type,
                ]);
                if ($state['parsed_data']) {
                    $state['parsed_data']->channel->rssUrl = $media_data;
                    $backup_data = json_encode([
                        'time_stored' => time(),
                        'data' => $state['parsed_data'],
                    ]);
                    file_put_contents(self::podcast_backup_file_path($playlist_name), $backup_data);
                } else {
                    $state['error_loading_data'] = true;
                }
            }
        }

        // Set parsed data to Wordpress transient server cache
        if (!$state['error_loading_data']) {
            set_transient($type . '_' . $playlist_name, json_encode($state['parsed_data']), $media_cache_ttl);
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
    private static function render_media_data_status_scripts(array $config, array $state): void
    {
        $type = $config['type'];
        $playlist_name = $config['playlist_name'];
        $cookie_name = $config['cookie_name'];
        $cookie_expired = $config['cookie_expired'];
        $parsed_data = $state['parsed_data'];
        $error_loading_data = $state['error_loading_data'];
        $data_loaded_method = $state['data_loaded_method'];
        $youtube_error_ttl = (int) $config['youtube_error_ttl'];

        // Log If There Was An Error Getting The Data And Clear Cookie
        if ($error_loading_data) {
            if ($type === 'youtube' && !get_transient($playlist_name . '_youtube_error')) {
                set_transient($playlist_name . '_youtube_error', true, $youtube_error_ttl);
            }
            if ($type === 'youtube' && get_transient($playlist_name . '_youtube_request_in_progress')) {
                echo '<script>console.warn("A previous request for YouTube data is still in progress.  This may be the reason for the error in loading the data.  Please wait a moment and try reloading the page.")</script>';
            }
            echo '<script>
                    console.error("There was an error getting the ' . $playlist_name . '_' . $type . '_playlist data.  Check your internet connection, try reloading or check the API key/media data.");
                </script>';
            if ($cookie_name) {
                echo '<script>
                    document.cookie = "' . $cookie_name . '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                </script>';
            }

            // Load backup server data if type is youtube
            if ($type === 'youtube') {
                $youtube_backup_file_path = self::backup_dir() . $playlist_name . '_youtube_backup_data.json';

                if (file_exists($youtube_backup_file_path) && is_readable($youtube_backup_file_path)) {
                    $parsed_backup_data = json_decode(file_get_contents($youtube_backup_file_path), true);
                    if (!empty($parsed_backup_data['data'])) {
                        echo '<script>localStorage.setItem("' . $playlist_name . '_' . $type . '_playlist", JSON.stringify(' . json_encode($parsed_backup_data['data']) . ')); console.log("`' . $playlist_name . '` ' . $type . ' data loaded from Backup storage and saved on Local Storage as `' . $playlist_name . '_' . $type . '_playlist.` as there was an error when making a call to the youtube API.");</script>';
                    }
                }
            }

            if ($type === 'podcast') {
                $podcast_backup_file_path = self::podcast_backup_file_path($playlist_name);

                if (file_exists($podcast_backup_file_path) && is_readable($podcast_backup_file_path)) {
                    $parsed_backup_data = json_decode(file_get_contents($podcast_backup_file_path), true);
                    if (!empty($parsed_backup_data['data'])) {
                        echo '<script>localStorage.setItem("' . $playlist_name . '_' . $type . '_playlist", JSON.stringify(' . json_encode($parsed_backup_data['data']) . ')); console.log("`' . $playlist_name . '` ' . $type . ' data loaded from Backup storage and saved on Local Storage as `' . $playlist_name . '_' . $type . '_playlist.` as there was an error when making a call to the podcast RSS feed.");</script>';
                    }
                }
            }
        }

        // Data Stored In Local Storage If Cookie Expired And API Call Was Made With No Errors
        if ($cookie_expired && !$error_loading_data) {
            // Set Cookie Storage Time Stamp And Output Parsed data Into Browser Local Storage
            echo '<script>localStorage.setItem("' . $playlist_name . '_' . $type . '_playlist", JSON.stringify(' . json_encode($parsed_data) . ')); console.log("`' . $playlist_name . '` ' . $type . ' data loaded from ' . $data_loaded_method . ' and saved on Local Storage as `' . $playlist_name . '_' . $type . '_playlist.`.");</script>';
        }

        // Data Loaded From Local Storage Due To An Error On API Call With Cookie Expired
        if ($cookie_expired && $error_loading_data) {
            echo '<script>
                    console.log("`' . $playlist_name . '` ' . $type . ' data loaded from Local Storage as there was an error loading the data.  Try checking your internet connection and reload the page.");
                </script>
            ';
        }

        // Data Loaded From Local Storage If Cookie Did Not Expire
        if (!$cookie_expired) {
            echo '<script>
                    console.log("`' . $playlist_name . '` ' . $type . ' data loaded from Local Storage as cookie storage time interval has not yet passed.");
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
    private static function render_media_initialization_script(array $config): void
    {
        $type = $config['type'];
        $podcast_platform = $config['podcast_platform'];
        $playlist_name = $config['playlist_name'];
        $cookie_name = $config['cookie_name'];

        // INDIVIDUAL MEDIA TYPE INITIALIZATION
        echo '

            <!-- Media API "' . $playlist_name . '_' . $type . '" Code Start -->

            <script>

                // Load ' . $type . ' Data From Local Storage

                const ' . $playlist_name . '_' . $type . '_data = JSON.parse(localStorage.getItem("' . $playlist_name . '_' . $type . '_playlist"));

                // If No Data Found In Local Storage, Clear Cookie

                if (!' . $playlist_name . '_' . $type . '_data && "' . $cookie_name . '") {
                    document.cookie = "' . $cookie_name . '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                }

                // Console Error If No Data Found

                if (!' . $playlist_name . '_' . $type . '_data) {
                    console.error("No data found for `' . $playlist_name . '_' . $type . '` or data in Local Storage was deleted.  Check your internet connection and reload the page.");
                }

                if (' . $playlist_name . '_' . $type . '_data && ' . $playlist_name . '_' . $type . '_data.length === 0) {
                    console.error("`' . $playlist_name . '_' . $type . '` data cannot be loaded.. Data is empty.");
                }

                // Initialize Media

                window.addEventListener("load", () => {
                    const media_items = document.querySelectorAll(`[data-playlistname="' . $playlist_name . '"]`);

                    initialize_media([...media_items].filter(item => item.dataset.mediaplatform === "' . $type . '" && item.dataset.playlistname === "' . $playlist_name . '"),
                        ' . $playlist_name . '_' . $type . '_data,
                        "' . $playlist_name . '", "' . $type . '",
                        "' . $podcast_platform . '"
                    );
                });

            </script>
            <!-- Media API "' . $playlist_name . '_' . $type . '" Code End -->
        ';
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
    public static function render_meta_data_updater_script(): void
    {
        // SEO tags are now rendered server-side by MetaUpdater.
    }
}
