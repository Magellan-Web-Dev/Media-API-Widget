<?php
namespace MediaApiWidget\Frontend;

use MediaApiWidget\Config\Options;

if (!defined('ABSPATH')) { exit; }

/**
 * Registers and handles all four plugin shortcodes.
 *
 * Shortcodes:
 * - [media-api-widget field=""]        — returns a stored shortcode field value.
 * - [media-api-widget-render ...]      — renders a single media item thumbnail/card.
 * - [media-api-widget-item ...]        — alias for media-api-widget-render.
 * - [media-api-podcast-player ...]     — renders a podcast player iframe.
 *
 * All shortcode attribute values support two admin field reference syntaxes:
 * - `{{field_name}}`                   — resolves to the stored shortcode value.
 * - `[media-api-widget field="name"]`  — same, in full shortcode notation.
 *
 * The class is instantiated once by {@see \MediaApiWidget\Plugin::register()}.
 */
final class Shortcode
{
    /**
     * Registers all four shortcodes with WordPress.
     *
     * Both 'media-api-widget-render' and 'media-api-widget-item' map to the
     * same handler so legacy content using either tag continues to work.
     *
     * @return void
     */
    public function register(): void
    {
        add_shortcode('media-api-widget', [$this, 'renderFieldShortcode']);
        add_shortcode('media-api-widget-render', [$this, 'renderMediaShortcode']);
        add_shortcode('media-api-widget-item', [$this, 'renderMediaShortcode']);
        add_shortcode('media-api-podcast-player', [$this, 'renderPodcastPlayerShortcode']);
    }

    /**
     * Returns the stored value of a named shortcode field.
     *
     * Looks up the `field` attribute in the list returned by
     * {@see Options::getShortcodes()} and returns the matching value, HTML-escaped.
     * Returns an empty string when the field is not found or the attribute is missing.
     *
     * Usage: `[media-api-widget field="hero_title"]`
     *
     * @param array<string,mixed> $atts Shortcode attributes; expects 'field'.
     * @return string Escaped field value, or ''.
     */
    public function renderFieldShortcode($atts = []): string
    {
        $atts = shortcode_atts(['field' => ''], $atts, 'media-api-widget');
        $field = sanitize_key((string) ($atts['field'] ?? ''));
        if ($field === '') {
            return '';
        }

        foreach (Options::getShortcodes() as $shortcode) {
            if (!is_array($shortcode)) {
                continue;
            }

            if (($shortcode['field'] ?? '') === $field) {
                return esc_html((string) ($shortcode['value'] ?? ''));
            }
        }

        return '';
    }

    /**
     * Renders a single media item (thumbnail card) or grid of items.
     *
     * Handles both [media-api-widget-render] and [media-api-widget-item].
     * Resolves `{{field}}` / `[media-api-widget field=""]` references in all
     * attribute values, reads cached media data, and branches into one of three
     * output modes:
     *
     * - `mediatitle` / `mediadescription` — returns a plain `<p>` text block.
     * - `multiplegrid="true"` with more than one item — returns a CSS grid of
     *   media item cards, with optional search, limit, and episode-range filters.
     * - Single item — returns one media item card resolved by episode number,
     *   name substring, or orderdescending index.
     *
     * Returns '' when playlist_name or media_platform is missing, when no
     * matching config or cache data is found, or when the resolved index is out
     * of range.
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     * @return string HTML output string.
     */
    public function renderMediaShortcode($atts = []): string
    {
        $atts = is_array($atts) ? $atts : [];
        if (!isset($atts['multiplegridtext']) && isset($atts['mutiplegridtext'])) {
            $atts['multiplegridtext'] = $atts['mutiplegridtext'];
        }

        $adminShortcodeValues = $this->getAdminShortcodeValues();

        $defaults = [
            'playlist_name' => '',
            'media_platform' => 'youtube',
            'podcast_platform' => '',
            'showplaybutton' => 'true',
            'playbuttoniconimgurl' => '',
            'playbuttonstyling' => 'width: 35%; height: 35%; opacity: 0.3;',
            'showtextoverlay' => 'true',
            'instructionmessage' => '',
            'fontfamily' => '',
            'logo' => '',
            'lightboxshowlogoimgurl' => '',
            'lightboxfont' => '',
            'lightboxthemecolor' => '',
            'lightboxshowplaylist' => 'false',
            'showplaybar' => 'false',
            'playbarcolor' => '#fff',
            'thumbnail' => '',
            'nameselect' => '',
            'episodenumber' => '',
            'orderdescending' => '',
            'mediatitle' => 'false',
            'mediadescription' => 'false',
            'mediadescriptiontextcolor' => '',
            'multiplegrid' => 'false',
            'multiplegridshowall' => 'false',
            'multiplegridsearch' => '',
            'multiplegridlimititems' => '',
            'multiplegridepisoderange' => '',
            'multiplegridgap' => '48px',
            'multiplegridminsize' => '400px',
            'multiplegridtext' => '',
            'podcastplayermode' => '',
            'podcastplayerbuttoncolor' => '',
            'podcastplayercolor' => '',
            'podcastprogressplayerbarcolor' => '',
            'podcastplayerhighlightcolor' => '',
            'podcastplayerfont' => '',
            'podcastplayerscrollcolor' => '',
            'podcastplayertextcolor' => '',
            'showepisodedateaftertitle' => '',
        ];

        $defaults = $this->mergeAdminShortcodeDefaults($defaults, $adminShortcodeValues);
        $atts = shortcode_atts($defaults, $atts, 'media-api-widget-render');
        $atts = $this->resolveAdminShortcodeAttributeValues($atts, $adminShortcodeValues);

        $playlistName = sanitize_key((string) $atts['playlist_name']);
        $mediaType = sanitize_key((string) $atts['media_platform']);

        if ($playlistName === '' || $mediaType === '') {
            return '';
        }

        $mediaConfig = $this->findMediaConfig($playlistName, $mediaType);
        if ($mediaConfig === null) {
            return '';
        }

        $podcastPlatform = sanitize_key((string) $atts['podcast_platform']);
        if ($podcastPlatform === '') {
            $podcastPlatform = sanitize_key((string) ($mediaConfig['podcast_platform'] ?? 'custom'));
        }

        $mediaData = $this->readCachedMediaData($playlistName, $mediaType, $mediaConfig);
        if ($mediaType === 'podcast' && $podcastPlatform === 'embed' && $mediaData === null) {
            $mediaData = [];
        }
        if ($mediaData === null) {
            return '';
        }

        $itemData = [
            'showplaybutton' => $this->isTruthy((string) $atts['showplaybutton']),
            'playbuttoniconimgurl' => (string) $atts['playbuttoniconimgurl'],
            'playbuttonstyling' => (string) $atts['playbuttonstyling'],
            'showtextoverlay' => $this->isTruthy((string) $atts['showtextoverlay']),
            'instructionmessage' => (string) $atts['instructionmessage'],
            'fontfamily' => (string) $atts['fontfamily'],
            'logo' => (string) $atts['logo'],
            'lightboxshowlogoimgurl' => (string) $atts['lightboxshowlogoimgurl'],
            'lightboxfont' => (string) $atts['lightboxfont'],
            'lightboxthemecolor' => (string) $atts['lightboxthemecolor'],
            'lightboxshowplaylist' => $this->isTruthy((string) $atts['lightboxshowplaylist']),
            'showplaybar' => $this->isTruthy((string) $atts['showplaybar']),
            'playbarcolor' => (string) $atts['playbarcolor'],
            'thumbnail' => (string) $atts['thumbnail'],
            'nameselect' => (string) $atts['nameselect'],
            'episodenumber' => (string) $atts['episodenumber'],
            'orderdescending' => (string) $atts['orderdescending'],
            'mediatitle' => $this->isTruthy((string) $atts['mediatitle']),
            'mediadescription' => $this->isTruthy((string) $atts['mediadescription']),
            'mediadescriptiontextcolor' => (string) $atts['mediadescriptiontextcolor'],
            'multiplegrid' => $this->isTruthy((string) $atts['multiplegrid']),
            'multiplegridshowall' => $this->isTruthy((string) $atts['multiplegridshowall']),
            'multiplegridsearch' => (string) $atts['multiplegridsearch'],
            'multiplegridlimititems' => (string) $atts['multiplegridlimititems'],
            'multiplegridepisoderange' => (string) $atts['multiplegridepisoderange'],
            'multiplegridgap' => (string) $atts['multiplegridgap'],
            'multiplegridminsize' => (string) $atts['multiplegridminsize'],
            'multiplegridtext' => $mediaType === 'youtube' ? $this->sanitizeMultipleGridText((string) $atts['multiplegridtext']) : '',
            'podcastplayermode' => (string) $atts['podcastplayermode'],
            'podcastplayerbuttoncolor' => (string) $atts['podcastplayerbuttoncolor'],
            'podcastplayercolor' => (string) $atts['podcastplayercolor'],
            'podcastprogressplayerbarcolor' => (string) $atts['podcastprogressplayerbarcolor'],
            'podcastplayerhighlightcolor' => (string) $atts['podcastplayerhighlightcolor'],
            'podcastplayerfont' => (string) $atts['podcastplayerfont'],
            'podcastplayerscrollcolor' => (string) $atts['podcastplayerscrollcolor'],
            'podcastplayertextcolor' => (string) $atts['podcastplayertextcolor'],
            'showepisodedateaftertitle' => (string) $atts['showepisodedateaftertitle'],
        ];

        $renderData = $this->normalizeRenderData($mediaData, $mediaType, $podcastPlatform, $itemData['thumbnail'], $itemData['orderdescending']);

        if ($itemData['mediatitle'] || $itemData['mediadescription']) {
            $index = $this->resolveIndex($renderData, $itemData, $mediaType, $podcastPlatform);
            if ($index !== null && isset($renderData[$index])) {
                $descriptionOrTitle = '';
                if ($itemData['mediadescription']) {
                    $descriptionOrTitle = (string) ($renderData[$index]['description'] ?? '');
                }
                if ($itemData['mediatitle'] && $descriptionOrTitle === '') {
                    $descriptionOrTitle = (string) ($renderData[$index]['title'] ?? '');
                }

                if ($descriptionOrTitle !== '') {
                    $style = '';
                    if ($itemData['mediadescriptiontextcolor'] !== '') {
                        $style = ' style="color: ' . esc_attr($itemData['mediadescriptiontextcolor']) . '"';
                    }
                    return '<p' . $style . ' class="media-description-text">' . esc_html($descriptionOrTitle) . '</p>';
                }
            }
            return '';
        }

        if ($itemData['multiplegrid'] && count($renderData) > 1) {
            $renderGridData = $renderData;

            if (!$itemData['multiplegridshowall']) {
                if ($itemData['multiplegridsearch'] !== '') {
                    $query = strtolower($itemData['multiplegridsearch']);
                    $renderGridData = array_values(array_filter($renderGridData, static function ($item) use ($query) {
                        return isset($item['title']) && stripos(strtolower((string) $item['title']), $query) !== false;
                    }));
                }

                if ((int) $itemData['multiplegridlimititems'] > 0) {
                    $renderGridData = array_slice($renderGridData, 0, (int) $itemData['multiplegridlimititems']);
                }

                if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $itemData['multiplegridepisoderange'], $m) === 1) {
                    $min = (int) $m[1];
                    $max = (int) $m[2];
                    $renderGridData = array_values(array_filter($renderData, static function ($item) use ($min, $max) {
                        $episode = isset($item['episode']) ? (int) $item['episode'] : -1;
                        return $episode >= $min && $episode <= $max;
                    }));
                }
            }

            if (count($renderGridData) === 0) {
                return '<h3 class="media-api-widget-err-msg">No ' . esc_html($mediaType) . ' items found in playlist based upon search parameters provided.</h3>';
            }

            $settings = $this->buildRenderSettings($itemData, $mediaType);
            $gridHtml = '';
            foreach ($renderGridData as $row) {
                $gridSettings = $settings;
                if ($itemData['multiplegridtext'] !== '') {
                    $gridSettings['multipleGridText'] = (string) $itemData['multiplegridtext'];
                }

                $gridHtml .= $this->renderMediaItem($row, $gridSettings, false, $playlistName, $mediaType);
            }

            return '<div class="media_items_multiple_grid_layout" style="gap: ' . esc_attr($itemData['multiplegridgap']) . '; grid-template-columns: repeat(auto-fill, minmax(min(100%, ' . esc_attr($itemData['multiplegridminsize']) . '), 1fr));">' . $gridHtml . '</div>';
        }

        $index = $this->resolveIndex($renderData, $itemData, $mediaType, $podcastPlatform);
        if ($index === null || !isset($renderData[$index])) {
            return '<h3 class="media-api-widget-err-msg">No ' . esc_html($mediaType) . ' item found in playlist based upon search parameters provided.</h3>';
        }

        $settings = $this->buildRenderSettings($itemData, $mediaType);
        return $this->renderMediaItem($renderData[$index], $settings, false, $playlistName, $mediaType);
    }

    /**
     * Renders the podcast player shortcode as an iframe.
     *
     * Resolves podcast player styling attributes from admin shortcode fields
     * (via {@see self::applyPodcastPlayerAdminFieldDefaults()}) and resolves
     * the RSS URL from the cached media data or direct config. Builds the
     * `/podcast/player` URL with all styling and episode parameters as query
     * arguments and returns the `<iframe>` tag.
     *
     * Returns '' when playlist_name is missing, media_platform is not 'podcast',
     * no matching config is found, or the RSS URL cannot be resolved.
     *
     * Usage: `[media-api-podcast-player playlist_name="my_podcast"]`
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     * @return string `<iframe>` HTML string, or ''.
     */
    public function renderPodcastPlayerShortcode($atts = []): string
    {
        $atts = is_array($atts) ? $atts : [];
        $adminShortcodeValues = $this->getAdminShortcodeValues();

        $atts = shortcode_atts($this->getPodcastPlayerShortcodeDefaults(), $atts, 'media-api-podcast-player');
        $atts = $this->resolveAdminShortcodeAttributeValues($atts, $adminShortcodeValues);
        $atts = $this->applyPodcastPlayerAdminFieldDefaults($atts, $adminShortcodeValues);
        $atts = $this->resolveAdminShortcodeAttributeValues($atts, $adminShortcodeValues);

        $playlistName = sanitize_key((string) ($atts['playlist_name'] ?? ''));
        $mediaType = sanitize_key((string) ($atts['media_platform'] ?? 'podcast'));
        if ($playlistName === '' || $mediaType !== 'podcast') {
            return '';
        }

        $mediaConfig = $this->findMediaConfig($playlistName, $mediaType);
        if ($mediaConfig === null) {
            return '';
        }

        $podcastPlatform = sanitize_key((string) ($atts['podcast_platform'] ?? ''));
        if ($podcastPlatform === '') {
            $podcastPlatform = sanitize_key((string) ($mediaConfig['podcast_platform'] ?? 'custom'));
        }

        $rssUrl = $this->resolvePodcastPlayerRssUrl($playlistName, $mediaConfig, $podcastPlatform);
        if ($rssUrl === '') {
            return '';
        }

        $track = '';
        if (isset($atts['orderdescending']) && is_numeric((string) $atts['orderdescending'])) {
            $track = (string) max(0, (int) $atts['orderdescending']);
        }

        $queryArgs = [
            'url' => $rssUrl,
            'track' => $track,
            'mode' => ltrim((string) ($atts['podcastplayermode'] ?? ''), '#'),
            'buttoncolor' => ltrim((string) ($atts['podcastplayerbuttoncolor'] ?? ''), '#'),
            'color1' => ltrim((string) ($atts['podcastplayercolor'] ?? ''), '#'),
            'progressbarcolor' => ltrim((string) ($atts['podcastprogressplayerbarcolor'] ?? ''), '#'),
            'highlightcolor' => ltrim((string) ($atts['podcastplayerhighlightcolor'] ?? ''), '#'),
            'font' => (string) ($atts['podcastplayerfont'] ?? ''),
            'scrollcolor' => ltrim((string) ($atts['podcastplayerscrollcolor'] ?? ''), '#'),
            'textcolor' => ltrim((string) ($atts['podcastplayertextcolor'] ?? ''), '#'),
        ];

        if ($this->isTruthy((string) ($atts['showepisodedateaftertitle'] ?? ''))) {
            $queryArgs['adddatetotitle'] = 'true';
        }

        $src = add_query_arg($queryArgs, home_url('/podcast/player'));

        return '<iframe style="width: 100%; min-height: 32.5rem; max-height: 100%; display: block;" src="' . esc_url($src) . '" loading="lazy" title="Podcast player"></iframe>';
    }

    /**
     * Finds the admin-configured media item matching a playlist name and type.
     *
     * Searches {@see Options::getMediaItems()} and any items declared via the
     * legacy MEDIA_CONTENT_DATA constant. Returns the first config array whose
     * playlist_name and type match, or null.
     *
     * @param string $playlistName Sanitized playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return array<string,mixed>|null Matching config array, or null.
     */
    private function findMediaConfig(string $playlistName, string $mediaType): ?array
    {
        $items = Options::getMediaItems();
        if (defined('MEDIA_CONTENT_DATA') && is_array(constant('MEDIA_CONTENT_DATA'))) {
            $items = array_merge($items, constant('MEDIA_CONTENT_DATA'));
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (sanitize_key((string) ($item['playlist_name'] ?? '')) === $playlistName
                && sanitize_key((string) ($item['type'] ?? '')) === $mediaType) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Reads media data from the WordPress transient cache or backup JSON file.
     *
     * For YouTube: checks the transient, then falls back to the backup JSON.
     * For podcast: checks the transient (JSON-decoding the string value), then
     * calls {@see self::fetchPodcastDataAndWarmCache()} to perform a live fetch
     * and warm the transient when no cache is available.
     *
     * @param string              $playlistName Playlist_name slug.
     * @param string              $mediaType    'youtube' or 'podcast'.
     * @param array<string,mixed> $mediaConfig  Config array used by the warm-up fetch.
     * @return array<int,array<string,mixed>>|array<string,mixed>|string|null Cached data, or null.
     */
    private function readCachedMediaData(string $playlistName, string $mediaType, array $mediaConfig = [])
    {
        $cached = get_transient($mediaType . '_' . $playlistName);

        if ($cached !== false && $cached !== null) {
            if ($mediaType === 'podcast') {
                if (is_string($cached)) {
                    $decoded = json_decode($cached, true);
                    return is_array($decoded) ? $decoded : null;
                }
                return is_array($cached) ? $cached : null;
            }

            return is_array($cached) ? $cached : null;
        }

        if ($mediaType === 'youtube') {
            $upload = wp_upload_dir();
            $base = rtrim((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads'), '/');
            $path = $base . '/media-api-widget/backups/' . $playlistName . '_youtube_backup_data.json';
            if (is_readable($path)) {
                $backup = json_decode((string) file_get_contents($path), true);
                if (is_array($backup) && isset($backup['data']) && is_array($backup['data'])) {
                    return $backup['data'];
                }
            }
        }

        if ($mediaType === 'podcast') {
            return $this->fetchPodcastDataAndWarmCache($playlistName, $mediaConfig);
        }

        return null;
    }

    /**
     * Fetches podcast RSS data on a cache miss and warms the transient.
     *
     * Used when no transient or backup data is available (e.g. a shortcode is
     * rendered before MediaBootstrap has had a chance to warm the cache). Performs
     * an iTunes lookup for non-custom platforms, fetches the RSS URL, parses it
     * with SimpleXML, normalizes items (strip tags, extract guid/pubDate), and
     * stores the result in the `podcast_{playlistName}` transient for 2 hours.
     *
     * Returns an empty array for embed platforms (no RSS to fetch). Returns null
     * on any network or parse error.
     *
     * @param string              $playlistName The playlist_name slug.
     * @param array<string,mixed> $mediaConfig  Admin media config array for this playlist.
     * @return array<string,mixed>|null Parsed and normalized podcast data, or null on failure.
     */
    private function fetchPodcastDataAndWarmCache(string $playlistName, array $mediaConfig): ?array
    {
        $platform = sanitize_key((string) ($mediaConfig['podcast_platform'] ?? 'custom'));
        $mediaData = trim((string) ($mediaConfig['media_data'] ?? ''));

        if ($platform === 'embed') {
            return [];
        }

        if ($mediaData === '') {
            return null;
        }

        $feedUrl = $mediaData;
        if ($platform !== 'custom') {
            $lookupUrl = 'https://itunes.apple.com/lookup?id=' . rawurlencode($mediaData) . '&entity=podcast';
            $lookupRes = wp_remote_get($lookupUrl);
            if (is_wp_error($lookupRes) || wp_remote_retrieve_response_code($lookupRes) !== 200) {
                return null;
            }

            $lookupData = json_decode((string) wp_remote_retrieve_body($lookupRes), true);
            if (!is_array($lookupData)) {
                return null;
            }

            $results = isset($lookupData['results']) && is_array($lookupData['results']) ? $lookupData['results'] : [];
            $first = $results[0] ?? null;
            $feedUrl = is_array($first) ? (string) ($first['feedUrl'] ?? '') : '';
            if ($feedUrl === '') {
                return null;
            }
        }

        $rssRes = wp_remote_get($feedUrl);
        if (is_wp_error($rssRes) || wp_remote_retrieve_response_code($rssRes) !== 200) {
            return null;
        }

        $xml = @simplexml_load_string((string) wp_remote_retrieve_body($rssRes));
        if ($xml === false) {
            return null;
        }

        $parsed = json_decode((string) wp_json_encode($xml), true);
        if (!is_array($parsed)) {
            return null;
        }

        if (!isset($parsed['channel']) || !is_array($parsed['channel'])) {
            return null;
        }

        $parsed['channel']['rssUrl'] = $feedUrl;
        $parsed['channel']['collectionViewUrl'] = $feedUrl;

        if (isset($parsed['channel']['item'])) {
            $items = $parsed['channel']['item'];
            if (isset($items['title'])) {
                $items = [$items];
            }

            if (is_array($items)) {
                foreach ($items as &$item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $item['title'] = wp_strip_all_tags($this->extractPodcastText($item['title'] ?? ''));
                    $item['description'] = wp_strip_all_tags($this->extractPodcastText($item['description'] ?? ''));
                    $item['guid'] = $this->extractPodcastText($item['guid'] ?? '');
                    $item['pubDate'] = $this->extractPodcastText($item['pubDate'] ?? '');
                }
                unset($item);
                $parsed['channel']['item'] = $items;
            }
        }

        set_transient('podcast_' . $playlistName, wp_json_encode($parsed), 7200);

        return $parsed;
    }

    /**
     * Converts raw cached media data into a normalized flat array for rendering.
     *
     * For YouTube, returns the data array as-is (re-indexed). For podcast,
     * handles three cases:
     * - 'embed' platform — returns a synthetic single-item array with the
     *   thumbnail URL and placeholder values.
     * - Standard RSS data — iterates channel.item, normalizes each episode into a
     *   flat array, and resolves a `trackSelect` index for SoundCloud/custom platforms
     *   when `orderdescending` is numeric.
     *
     * @param array<int,array<string,mixed>>|array<string,mixed>|string $mediaData    Raw cached data.
     * @param string                                                     $mediaType    'youtube' or 'podcast'.
     * @param string                                                     $podcastPlatform Platform key.
     * @param string                                                     $thumbnailUrl Fallback thumbnail URL.
     * @param string                                                     $orderDescending Numeric order index or ''.
     * @return array<int,array<string,mixed>> Normalized item array.
     */
    private function normalizeRenderData($mediaData, string $mediaType, string $podcastPlatform, string $thumbnailUrl, string $orderDescending): array
    {
        if ($mediaType === 'youtube') {
            return is_array($mediaData) ? array_values($mediaData) : [];
        }

        if ($mediaType !== 'podcast') {
            return [];
        }

        if ($podcastPlatform === 'embed') {
            return [[
                'thumbnail' => ['url' => $thumbnailUrl],
                'publishedDate' => 'unknown',
                'id' => 'unknown',
                'episode' => -1,
                'title' => 'Embedded Podcast',
                'description' => '',
            ]];
        }

        if (!is_array($mediaData) || !isset($mediaData['channel']['item'])) {
            return [];
        }

        $items = $mediaData['channel']['item'];
        if (!is_array($items)) {
            return [];
        }

        if (isset($items['title'])) {
            $items = [$items];
        }

        $trackSelect = null;
        if ($podcastPlatform === 'soundcloud' && is_numeric($orderDescending)) {
            $trackSelect = (int) $orderDescending - 1;
        }
        if ($podcastPlatform === 'custom' && is_numeric($orderDescending)) {
            $trackSelect = (int) $orderDescending;
        }

        $output = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $output[] = [
                'thumbnail' => ['url' => $thumbnailUrl],
                'publishedDate' => $this->extractPodcastText($item['pubDate'] ?? ''),
                'id' => $this->extractPodcastText($item['guid'] ?? ''),
                'episode' => -1,
                'title' => wp_strip_all_tags($this->extractPodcastText($item['title'] ?? '')),
                'description' => wp_strip_all_tags($this->extractPodcastText($item['description'] ?? '')),
                'trackSelect' => $trackSelect,
            ];
        }

        return $output;
    }

    /**
     * Flattens XML-derived values into a plain text string.
     *
     * Handles strings, numerics, booleans, and nested arrays. Skips keys that
     * begin with '@' (e.g. '@attributes' from SimpleXML → JSON encoding) to
     * prevent attribute metadata from leaking into text content.
     *
     * @param mixed $value The value to extract text from.
     * @return string Flattened, whitespace-normalized string.
     */
    private function extractPodcastText($value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $key => $child) {
            if (is_string($key) && ($key === '@attributes' || strpos($key, '@') === 0)) {
                continue;
            }

            $text = $this->extractPodcastText($child);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    /**
     * Resolves the integer index of the item to render within $renderData.
     *
     * Selection priority (first match wins):
     * 1. YouTube: match by `episodenumber` against item['episode'].
     * 2. Match by `nameselect` substring in item['title'] (case-insensitive).
     * 3. Use `orderdescending` as a 1-based index (converted to 0-based).
     * 4. For embed platform, always returns 0.
     *
     * Returns null when no rule applies and the caller should fall back to an
     * error message.
     *
     * @param array<int,array<string,mixed>> $renderData    Normalized item array.
     * @param array<string,mixed>            $itemData      Resolved shortcode item options.
     * @param string                         $mediaType     'youtube' or 'podcast'.
     * @param string                         $podcastPlatform Platform key.
     * @return int|null Zero-based item index, or null.
     */
    private function resolveIndex(array $renderData, array $itemData, string $mediaType, string $podcastPlatform): ?int
    {
        $index = null;

        if ($itemData['episodenumber'] !== '' && $mediaType === 'youtube') {
            $episodeNumber = (int) $itemData['episodenumber'];
            foreach ($renderData as $i => $item) {
                if (isset($item['episode']) && (int) $item['episode'] === $episodeNumber) {
                    $index = (int) $i;
                    break;
                }
            }
        }

        if ($itemData['nameselect'] !== '') {
            foreach ($renderData as $i => $item) {
                if (isset($item['title']) && stripos((string) $item['title'], (string) $itemData['nameselect']) !== false) {
                    $index = (int) $i;
                    break;
                }
            }
        }

        if ($itemData['orderdescending'] !== '' && is_numeric((string) $itemData['orderdescending'])) {
            $index = (int) $itemData['orderdescending'] - 1;
        }

        if ($podcastPlatform === 'embed') {
            $index = 0;
        }

        return $index;
    }

    /**
     * Builds the flat render settings array used by {@see self::renderMediaItem()}.
     *
     * Normalizes boolean flags, strips leading '#' from color values, applies
     * the default instruction message ('Click Here To Watch' / 'Click Here To
     * Listen') when none is provided, and consolidates the `logo` and
     * `lightboxshowlogoimgurl` attributes (logo takes precedence when set).
     *
     * @param array<string,mixed> $itemData  Resolved shortcode item options.
     * @param string              $mediaType 'youtube' or 'podcast'.
     * @return array<string,mixed> Render settings ready for renderMediaItem().
     */
    private function buildRenderSettings(array $itemData, string $mediaType): array
    {
        $instruction = trim((string) $itemData['instructionmessage']);
        if ($instruction === '') {
            $instruction = match($mediaType) {
                'youtube' => 'Click Here To Watch',
                'podcast' => 'Click Here To Listen',
                default   => '',
            };
        }

        return [
            'showPlayButton' => (bool) $itemData['showplaybutton'],
            'playButtonIconImgUrl' => (string) $itemData['playbuttoniconimgurl'],
            'playButtonStyling' => (string) $itemData['playbuttonstyling'],
            'showTextOverlay' => (bool) $itemData['showtextoverlay'],
            'instructionMessage' => $instruction,
            'fontFamily' => (string) $itemData['fontfamily'],
            'lightboxshowlogoimgurl' => (string) ($itemData['logo'] !== '' ? $itemData['logo'] : $itemData['lightboxshowlogoimgurl']),
            'lightboxfont' => (string) $itemData['lightboxfont'],
            'lightboxthemecolor' => (string) $itemData['lightboxthemecolor'],
            'lightboxshowplaylist' => (bool) $itemData['lightboxshowplaylist'],
            'showPlaybar' => (bool) $itemData['showplaybar'],
            'playbarColor' => ltrim((string) $itemData['playbarcolor'], '#'),
            'podcastplayermode' => ltrim((string) $itemData['podcastplayermode'], '#'),
            'podcastplayerbuttoncolor' => ltrim((string) $itemData['podcastplayerbuttoncolor'], '#'),
            'podcastplayercolor' => ltrim((string) $itemData['podcastplayercolor'], '#'),
            'podcastprogressplayerbarcolor' => ltrim((string) $itemData['podcastprogressplayerbarcolor'], '#'),
            'podcastplayerhighlightcolor' => ltrim((string) $itemData['podcastplayerhighlightcolor'], '#'),
            'podcastplayerfont' => (string) $itemData['podcastplayerfont'],
            'podcastplayerscrollcolor' => ltrim((string) $itemData['podcastplayerscrollcolor'], '#'),
            'podcastplayertextcolor' => ltrim((string) $itemData['podcastplayertextcolor'], '#'),
            'showepisodedateaftertitle' => (string) $itemData['showepisodedateaftertitle'],
        ];
    }

    /**
     * Renders the HTML for a single media item card.
     *
     * Outputs an `<a>` element with all required data attributes for the front-end
     * JS (itemclickable, itemclickableplaylist, data-id, lightbox attributes,
     * podcast player styling attributes, trackSelect). Inside the anchor:
     * - Thumbnail `<img>`
     * - Optional play button (SVG or custom icon)
     * - Optional text overlay (title + instruction message)
     * - Optional audio play bar SVG
     * - Optional multiple-grid text block (title or description)
     *
     * When `$playlistItem` is true (reserved for future playlist-mode use),
     * settings are overridden with minimal playlist-item defaults and an episode
     * heading `<h3>` is prepended.
     *
     * @param array<string,mixed> $item         Normalized media data item.
     * @param array<string,mixed> $settings     Render settings from buildRenderSettings().
     * @param bool                $playlistItem True when rendering inside a playlist widget (overrides settings).
     * @param string              $name         Playlist name used for data attributes and HTML comments.
     * @param string              $type         Media type ('youtube' or 'podcast').
     * @return string HTML string for the media item.
     */
    private function renderMediaItem(array $item, array $settings, bool $playlistItem, string $name, string $type): string
    {
        if ($playlistItem) {
            $settings = [
                'showPlayButton' => true,
                'playButtonIconImgUrl' => '',
                'playButtonStyling' => 'width: 50%; height: 50%; opacity: 0.3;',
                'showTextOverlay' => false,
                'instructionMessage' => '',
                'fontFamily' => '',
                'lightboxshowlogoimgurl' => '',
                'lightboxfont' => '',
                'lightboxthemecolor' => '',
                'lightboxshowplaylist' => false,
                'showPlaybar' => false,
                'playbarColor' => '#fff',
                'podcastplayermode' => '',
                'podcastplayerbuttoncolor' => '',
                'podcastplayercolor' => '',
                'podcastprogressplayerbarcolor' => '',
                'podcastplayerhighlightcolor' => '',
                'podcastplayerfont' => '',
                'podcastplayerscrollcolor' => '',
                'podcastplayertextcolor' => '',
                'showepisodedateaftertitle' => '',
            ];
        }

        $mediaType = ($type === 'youtube' || $type === 'vimeo') ? 'video' : 'audio';

        $title = (string) ($item['title'] ?? '');
        $publishedDate = (string) ($item['publishedDate'] ?? '');
        $episode = isset($item['episode']) ? (int) $item['episode'] : -1;
        $id = (string) ($item['id'] ?? '');
        $thumbnail = (isset($item['thumbnail']) && is_array($item['thumbnail'])) ? $item['thumbnail'] : [];
        $thumbnailUrl = (string) ($thumbnail['url'] ?? '');
        $thumbnailWidth = isset($thumbnail['width']) ? (int) $thumbnail['width'] : 1280;
        $thumbnailHeight = isset($thumbnail['height']) ? (int) $thumbnail['height'] : 720;

        $class = 'media_item' . ($settings['showTextOverlay'] ? '-text-overlay-enabled' : '');
        $style = '';
        if ($settings['fontFamily'] !== '') {
            $style = ' style="font-family: ' . esc_attr((string) $settings['fontFamily']) . ';"';
        }

        $customPodcastPlayer = $this->hasCustomPodcastPlayerSettings($settings);
        $podcastAttrs = '';
        if ($customPodcastPlayer) {
            $podcastMode = trim((string) $settings['podcastplayermode']) !== '' ? (string) $settings['podcastplayermode'] : 'dark';
            $podcastAttrs =
                ' data-podcastplayermode="' . esc_attr($podcastMode) . '"' .
                ' data-podcastplayerbuttoncolor="' . esc_attr((string) $settings['podcastplayerbuttoncolor']) . '"' .
                ' data-podcastplayercolor="' . esc_attr((string) $settings['podcastplayercolor']) . '"' .
                ' data-podcastprogressplayerbarcolor="' . esc_attr((string) $settings['podcastprogressplayerbarcolor']) . '"' .
                ' data-podcastplayerhighlightcolor="' . esc_attr((string) $settings['podcastplayerhighlightcolor']) . '"' .
                ' data-podcastplayerfont="' . esc_attr((string) $settings['podcastplayerfont']) . '"' .
                ' data-podcastplayerscrollcolor="' . esc_attr((string) $settings['podcastplayerscrollcolor']) . '"' .
                ' data-podcastplayertextcolor="' . esc_attr((string) $settings['podcastplayertextcolor']) . '"' .
                ' data-showepisodedateaftertitle="' . esc_attr((string) $settings['showepisodedateaftertitle']) . '"';
        }

        $trackSelectAttr = '';
        if (isset($item['trackSelect']) && $item['trackSelect'] !== null && $item['trackSelect'] !== '') {
            $trackSelectAttr = ' data-trackselect="' . esc_attr((string) $item['trackSelect']) . '"';
        }

        $lightboxPlaylistAttr = $settings['lightboxshowplaylist'] ? ' data-lightboxshowplaylist="true"' : '';
        $lightboxLogoAttr = trim((string) ($settings['lightboxshowlogoimgurl'] ?? '')) !== ''
            ? ' data-lightboxshowlogoimgurl="' . esc_url((string) $settings['lightboxshowlogoimgurl']) . '"'
            : '';
        $lightboxFontAttr = trim((string) ($settings['lightboxfont'] ?? '')) !== ''
            ? ' data-lightboxfont="' . esc_attr((string) $settings['lightboxfont']) . '"'
            : '';
        $lightboxThemeColorAttr = trim((string) ($settings['lightboxthemecolor'] ?? '')) !== ''
            ? ' data-lightboxthemecolor="' . esc_attr((string) $settings['lightboxthemecolor']) . '"'
            : '';

        $playButtonHtml = '';
        if ($settings['showPlayButton']) {
            $playButtonInner = '';
            if ((string) $settings['playButtonIconImgUrl'] !== '') {
                $playButtonInner = '<img src="' . esc_url((string) $settings['playButtonIconImgUrl']) . '">';
            } else {
                $playButtonInner = $this->playButtonIconSvg();
            }
            $playButtonHtml = '<div class="media-item-play-button" style="' . esc_attr((string) $settings['playButtonStyling']) . '">' . $playButtonInner . '</div>';
        }

        $textOverlay = '';
        if ($settings['showTextOverlay']) {
            $audioTextStyle = $mediaType === 'audio' ? ' style="padding-bottom: max(10vw, 48px);"' : '';
            $textOverlay =
                '<div class="media-item-text-overlay"' . $audioTextStyle . '>' .
                    '<h3>' . $this->renderOverlayTitle($title, $episode) . '</h3>' .
                    '<p>' . esc_html((string) $settings['instructionMessage']) . '</p>' .
                '</div>';
        }

        $playlistHeading = '';
        if ($playlistItem) {
            $playlistHeading = '<h3 class="playlist-episode-text">' . esc_html($episode !== -1 ? ('Episode ' . $episode) : $title) . '</h3>';
        }

        $playBarHtml = '';
        if ($mediaType === 'audio' && $settings['showPlaybar']) {
            $playBarHtml = $this->audioPlayBarSvg((string) $settings['playbarColor']);
        }

        $multipleGridTextHtml = '';
        if (!$playlistItem && isset($settings['multipleGridText'])) {
            $multipleGridTextHtml = $this->renderMultipleGridText($item, (string) $settings['multipleGridText']);
        }

        $mediaItemHtml =
            '<a' . $style .
                ' class="' . esc_attr($class) . '"' .
                ' data-itemclickablemediatype="' . esc_attr($mediaType) . '"' .
                ' data-itemclickable="true"' .
                ' data-itemclickableplaylist="' . esc_attr($name . '_' . $type) . '"' .
                ' data-id="' . esc_attr($id) . '"' .
                $lightboxPlaylistAttr .
                $lightboxLogoAttr .
                $lightboxFontAttr .
                $lightboxThemeColorAttr .
                $trackSelectAttr .
                $podcastAttrs .
            '>' .
                '<div class="media-item-thumbnail-text-wrapper">' .
                    '<img class="media-item-thumbnail" src="' . esc_url($thumbnailUrl) . '" width="' . esc_attr((string) $thumbnailWidth) . '" height="' . esc_attr((string) $thumbnailHeight) . '" alt="' . esc_attr($title) . '">' .
                    $playButtonHtml .
                    $textOverlay .
                '</div>' .
                $playlistHeading .
                $playBarHtml .
            '</a>';

        $itemComment = '<!-- ' . esc_html($name) . ' ' . esc_html($type) . ' item - ' . esc_html($title) . ' (Published On - ' . esc_html($publishedDate) . ') -->';

        if ($multipleGridTextHtml !== '') {
            return $itemComment .
                '<div class="media-item-multiple-grid-entry">' .
                    $mediaItemHtml .
                    $multipleGridTextHtml .
                '</div>';
        }

        return $itemComment . $mediaItemHtml;
    }

    /**
     * Renders the text overlay title for a media item card.
     *
     * When the item has an episode number, returns "Episode N". Otherwise,
     * splits the title by whitespace; if there are more than three words, wraps
     * words 4+ in a `<span class="sub-text">` element to allow CSS to style
     * the continuation differently.
     *
     * @param string $title   The item title.
     * @param int    $episode The episode number, or -1 if not applicable.
     * @return string Escaped HTML string for the overlay heading.
     */
    private function renderOverlayTitle(string $title, int $episode): string
    {
        if ($episode !== -1) {
            return esc_html('Episode ' . $episode);
        }

        $words = preg_split('/\s+/', trim($title)) ?: [];
        if (count($words) > 3) {
            $first = implode(' ', array_slice($words, 0, 3));
            $rest = implode(' ', array_slice($words, 3));
            return esc_html($first) . '<br><span class="sub-text">' . esc_html($rest) . '</span>';
        }

        return esc_html($title);
    }

    /**
     * Renders a title or description text block below a grid item.
     *
     * Returns a `<div>` with the item's title or description text when `$mode`
     * is 'title' or 'description' respectively and the text is non-empty.
     * Returns '' for any other mode value or when the text is empty.
     *
     * @param array<string,mixed> $item The normalized media item.
     * @param string              $mode 'title' or 'description'.
     * @return string HTML `<div>` block, or ''.
     */
    private function renderMultipleGridText(array $item, string $mode): string
    {
        $text = '';

        if ($mode === 'title') {
            $text = trim((string) ($item['title'] ?? ''));
        } elseif ($mode === 'description') {
            $text = trim((string) ($item['description'] ?? ''));
        }

        if ($text === '') {
            return '';
        }

        return '<div class="media-item-multiple-grid-text media-item-multiple-grid-text-' . esc_attr($mode) . '">' . esc_html($text) . '</div>';
    }

    /**
     * Returns the inline SVG markup for the circular play button icon.
     *
     * The fill color of the play-button path is injected via the `$color`
     * parameter so callers can theme the icon without CSS overrides.
     *
     * @param string $color CSS color value for the icon fill (default '#fff').
     * @return string Inline SVG string.
     */
    private function playButtonIconSvg(string $color = '#fff'): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" id="Layer_1" data-name="Layer 1" viewBox="0 0 145.2 145.2"><defs><style>.cls-1{fill:none}.cls-2{clip-path:url(#clip-path)}.cls-3{opacity:1}.cls-4{clip-path:url(#clip-path-3)}</style><clipPath id="clip-path" transform="translate(-264.41 -245.59)"><rect class="cls-1" x="264.41" y="245.59" width="145.2" height="145.2"></rect></clipPath><clipPath id="clip-path-3" transform="translate(-264.41 -245.59)"><rect class="cls-1" x="255.41" y="238.59" width="163.2" height="153.2"></rect></clipPath></defs><g class="cls-2"><g class="cls-2"><g class="cls-3"><g class="cls-4"><path style="fill:' . esc_attr($color) . '" class="cls-5" d="M378.93,318.19,311,357.4V279Zm30.68,0a72.6,72.6,0,1,0-72.6,72.6,72.6,72.6,0,0,0,72.6-72.6" transform="translate(-264.41 -245.59)"></path></g></g></g></g></svg>';
    }

    /**
     * Returns the inline SVG markup for the audio play bar decoration.
     *
     * Renders a rectangular bar with a play-triangle icon on the left and a
     * series of vertical tick marks. The background fill color is set via
     * `$color` so it can match the podcast player theme.
     *
     * @param string $color CSS color value for the bar background fill (default '#fff').
     * @return string Inline SVG string.
     */
    private function audioPlayBarSvg(string $color = '#fff'): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" id="audio_play_bar" class="audio-play-bar" data-name="Layer 1" viewBox="0 0 453 45"><defs><style>.pb-2{fill:#231f20}.pb-3{fill:none;stroke:#231f20;stroke-width:3px}</style></defs><rect style="fill:' . esc_attr($color) . '" width="453" height="45"></rect><polygon class="pb-2" points="42.99 22 17.01 7 17.01 37 42.99 22"></polygon><line class="pb-3" x1="52" y1="9" x2="52" y2="36"></line><line class="pb-3" x1="58" y1="9" x2="58" y2="36"></line><line class="pb-3" x1="64" y1="9" x2="64" y2="36"></line><line class="pb-3" x1="70" y1="9" x2="70" y2="36"></line><line class="pb-3" x1="76" y1="9" x2="76" y2="36"></line><line class="pb-3" x1="82" y1="9" x2="82" y2="36"></line><line class="pb-3" x1="88" y1="9" x2="88" y2="36"></line><line class="pb-3" x1="94" y1="9" x2="94" y2="36"></line><line class="pb-3" x1="100" y1="9" x2="100" y2="36"></line><line class="pb-3" x1="106" y1="9" x2="106" y2="36"></line><line class="pb-3" x1="112" y1="9" x2="112" y2="36"></line><line class="pb-3" x1="118" y1="9" x2="118" y2="36"></line><line class="pb-3" x1="124" y1="9" x2="124" y2="36"></line><line class="pb-3" x1="130" y1="9" x2="130" y2="36"></line><line class="pb-3" x1="136" y1="9" x2="136" y2="36"></line><line class="pb-3" x1="142" y1="9" x2="142" y2="36"></line><line class="pb-3" x1="148" y1="9" x2="148" y2="36"></line><line class="pb-3" x1="154" y1="9" x2="154" y2="36"></line><line class="pb-3" x1="160" y1="9" x2="160" y2="36"></line><line class="pb-3" x1="166" y1="9" x2="166" y2="36"></line><line class="pb-3" x1="172" y1="9" x2="172" y2="36"></line><line class="pb-3" x1="178" y1="9" x2="178" y2="36"></line><line class="pb-3" x1="184" y1="9" x2="184" y2="36"></line><line class="pb-3" x1="190" y1="9" x2="190" y2="36"></line><line class="pb-3" x1="196" y1="9" x2="196" y2="36"></line><line class="pb-3" x1="202" y1="9" x2="202" y2="36"></line><line class="pb-3" x1="208" y1="9" x2="208" y2="36"></line><line class="pb-3" x1="214" y1="9" x2="214" y2="36"></line><line class="pb-3" x1="220" y1="9" x2="220" y2="36"></line><line class="pb-3" x1="226" y1="9" x2="226" y2="36"></line><line class="pb-3" x1="232" y1="9" x2="232" y2="36"></line><line class="pb-3" x1="238" y1="9" x2="238" y2="36"></line><line class="pb-3" x1="244" y1="9" x2="244" y2="36"></line><line class="pb-3" x1="250" y1="9" x2="250" y2="36"></line><line class="pb-3" x1="256" y1="9" x2="256" y2="36"></line><line class="pb-3" x1="262" y1="9" x2="262" y2="36"></line><line class="pb-3" x1="268" y1="9" x2="268" y2="36"></line><line class="pb-3" x1="274" y1="9" x2="274" y2="36"></line><line class="pb-3" x1="280" y1="9" x2="280" y2="36"></line><line class="pb-3" x1="286" y1="9" x2="286" y2="36"></line><line class="pb-3" x1="292" y1="9" x2="292" y2="36"></line><line class="pb-3" x1="298" y1="9" x2="298" y2="36"></line><line class="pb-3" x1="304" y1="9" x2="304" y2="36"></line><line class="pb-3" x1="310" y1="9" x2="310" y2="36"></line><line class="pb-3" x1="316" y1="9" x2="316" y2="36"></line><line class="pb-3" x1="322" y1="9" x2="322" y2="36"></line><line class="pb-3" x1="328" y1="9" x2="328" y2="36"></line><line class="pb-3" x1="334" y1="9" x2="334" y2="36"></line><line class="pb-3" x1="340" y1="9" x2="340" y2="36"></line><line class="pb-3" x1="346" y1="9" x2="346" y2="36"></line><line class="pb-3" x1="352" y1="9" x2="352" y2="36"></line><line class="pb-3" x1="358" y1="9" x2="358" y2="36"></line><line class="pb-3" x1="364" y1="9" x2="364" y2="36"></line><line class="pb-3" x1="370" y1="9" x2="370" y2="36"></line><line class="pb-3" x1="376" y1="9" x2="376" y2="36"></line><line class="pb-3" x1="382" y1="9" x2="382" y2="36"></line><line class="pb-3" x1="388" y1="9" x2="388" y2="36"></line><line class="pb-3" x1="394" y1="9" x2="394" y2="36"></line><line class="pb-3" x1="400" y1="9" x2="400" y2="36"></line><line class="pb-3" x1="406" y1="9" x2="406" y2="36"></line><line class="pb-3" x1="412" y1="9" x2="412" y2="36"></line><line class="pb-3" x1="418" y1="9" x2="418" y2="36"></line><line class="pb-3" x1="424" y1="9" x2="424" y2="36"></line><line class="pb-3" x1="430" y1="9" x2="430" y2="36"></line><line class="pb-3" x1="436" y1="9" x2="436" y2="36"></line></svg>';
    }

    /**
     * Returns true when a shortcode attribute value string is truthy.
     *
     * Accepts '1', 'true', 'yes', and 'on' (case-insensitive) as true;
     * everything else is false. Used to normalize WordPress shortcode
     * attribute values which arrive as strings.
     *
     * @param string $value The raw attribute value string.
     * @return bool True when the value represents a truthy state.
     */
    private function isTruthy(string $value): bool
    {
        return match(strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            default                  => false,
        };
    }

    /**
     * Validates and normalizes the multiplegridtext attribute value.
     *
     * Returns 'title' or 'description' when the trimmed, lowercased value
     * matches either of those strings. Returns '' for any other value, which
     * disables the grid text feature.
     *
     * @param string $value Raw attribute value.
     * @return string 'title', 'description', or ''.
     */
    private function sanitizeMultipleGridText(string $value): string
    {
        $v = strtolower(trim($value));
        return match($v) {
            'title', 'description' => $v,
            default                => '',
        };
    }

    /**
     * Returns true when any podcast player styling attribute is non-empty.
     *
     * Checks all nine podcast player styling keys in `$settings`. When at least
     * one is non-empty, the caller should emit podcast player data attributes on
     * the media item element so the front-end JS can pass them to the player
     * iframe.
     *
     * @param array<string,mixed> $settings Render settings array.
     * @return bool True when at least one podcast player styling attribute is set.
     */
    private function hasCustomPodcastPlayerSettings(array $settings): bool
    {
        $keys = [
            'podcastplayermode',
            'podcastplayerbuttoncolor',
            'podcastplayercolor',
            'podcastprogressplayerbarcolor',
            'podcastplayerhighlightcolor',
            'podcastplayerfont',
            'podcastplayerscrollcolor',
            'podcastplayertextcolor',
            'showepisodedateaftertitle',
        ];

        foreach ($keys as $key) {
            if (trim((string) ($settings[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns all stored admin shortcode fields as a flat key-value map.
     *
     * Used to populate default attribute values and to resolve `{{field}}`
     * references. Empty field names are skipped.
     *
     * @return array<string,string> Map of field name to value.
     */
    private function getAdminShortcodeValues(): array
    {
        $values = [];

        foreach (Options::getShortcodes() as $shortcode) {
            if (!is_array($shortcode)) {
                continue;
            }

            $field = sanitize_key((string) ($shortcode['field'] ?? ''));
            if ($field === '') {
                continue;
            }

            $values[$field] = (string) ($shortcode['value'] ?? '');
        }

        return $values;
    }

    /**
     * Merges admin shortcode field values into the shortcode defaults array.
     *
     * For each key in `$defaults`, if a matching non-empty admin value exists,
     * replaces the default with the admin value. This allows site-wide styling
     * to be managed via the admin Shortcodes panel rather than per-shortcode
     * attributes.
     *
     * @param array<string,mixed>  $defaults     The shortcode defaults map.
     * @param array<string,string> $adminValues  The admin shortcode values map.
     * @return array<string,mixed> Merged defaults.
     */
    private function mergeAdminShortcodeDefaults(array $defaults, array $adminValues): array
    {
        foreach ($defaults as $key => $value) {
            if (isset($adminValues[$key]) && $adminValues[$key] !== '') {
                $defaults[$key] = $adminValues[$key];
            }
        }

        return $defaults;
    }

    /**
     * Resolves admin shortcode field references in all attribute values.
     *
     * Iterates `$atts` and calls {@see self::resolveAdminShortcodeAttributeValue()}
     * on every string value. Non-string values are passed through unchanged.
     *
     * @param array<string,mixed>  $atts        Shortcode attributes.
     * @param array<string,string> $adminValues Admin shortcode values map.
     * @return array<string,mixed> Attributes with references resolved.
     */
    private function resolveAdminShortcodeAttributeValues(array $atts, array $adminValues): array
    {
        foreach ($atts as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $atts[$key] = $this->resolveAdminShortcodeAttributeValue($value, $adminValues);
        }

        return $atts;
    }

    /**
     * Resolves a single admin shortcode field reference in an attribute value.
     *
     * Supports two reference syntaxes:
     * - `{{field_name}}`                      — resolves to the stored value.
     * - `[media-api-widget field="field_name"]` — same, in full shortcode notation.
     *
     * Returns the original `$value` unchanged when no reference is found or
     * the referenced field does not exist in `$adminValues`.
     *
     * @param string               $value       Raw attribute value to resolve.
     * @param array<string,string> $adminValues Admin shortcode values map.
     * @return string Resolved value, or the original if no reference matched.
     */
    private function resolveAdminShortcodeAttributeValue(string $value, array $adminValues): string
    {
        $trimmed = trim($value);

        if (preg_match('/^\{\{\s*([a-z0-9_-]+)\s*\}\}$/i', $trimmed, $matches) === 1) {
            $field = sanitize_key((string) ($matches[1] ?? ''));
            if ($field !== '' && isset($adminValues[$field])) {
                return $adminValues[$field];
            }
            return $value;
        }

        if (preg_match('/^\[media-api-widget\s+field=(["\'])([a-z0-9_-]+)\1\s*\]$/i', $trimmed, $matches) === 1) {
            $field = sanitize_key((string) ($matches[2] ?? ''));
            if ($field !== '' && isset($adminValues[$field])) {
                return $adminValues[$field];
            }
            return $value;
        }

        return $value;
    }

    /**
     * Returns the default shortcode attribute map for [media-api-podcast-player].
     *
     * Provides podcast-friendly defaults that differ from the generic render
     * shortcode: orderdescending defaults to '1' (most recent episode),
     * playlist_name defaults to 'podcast', and overlay/play-button are off.
     *
     * @return array<string,string> Default attribute map.
     */
    private function getPodcastPlayerShortcodeDefaults(): array
    {
        return [
            'orderdescending' => '1',
            'playlist_name' => 'podcast',
            'media_platform' => 'podcast',
            'showtextoverlay' => 'false',
            'showplaybutton' => 'false',
            'lightboxshowplaylist' => 'true',
        ];
    }

    /**
     * Applies backend shortcode field values for podcast player styling attributes.
     *
     * Maps the eight podcast player shortcode attributes to their corresponding
     * admin shortcode field names. For each attribute that is either empty or
     * still contains an unresolved `{{}}` / `[media-api-widget]` placeholder,
     * substitutes the stored admin value when one is available.
     *
     * This runs before the final resolveAdminShortcodeAttributeValues() pass so
     * that admin field values can themselves contain references.
     *
     * @param array<string,mixed>  $atts        Shortcode attributes to fill in.
     * @param array<string,string> $adminValues Admin shortcode values map.
     * @return array<string,mixed> Attributes with admin field defaults applied.
     */
    private function applyPodcastPlayerAdminFieldDefaults(array $atts, array $adminValues): array
    {
        $fieldMap = [
            'podcastplayermode' => 'podcast_player_background_color',
            'podcastplayertextcolor' => 'podcast_player_text_color',
            'podcastplayerbuttoncolor' => 'podcast_player_play_icon_color',
            'podcastplayercolor' => 'podcast_player_color',
            'podcastprogressplayerbarcolor' => 'podcast_player_progress_bar_color',
            'podcastplayerhighlightcolor' => 'podcast_player_selected_color',
            'podcastplayerfont' => 'podcast_player_font',
            'podcastplayerscrollcolor' => 'podcast_player_scrollbar_color',
        ];

        foreach ($fieldMap as $attribute => $fieldName) {
            $currentValue = isset($atts[$attribute]) ? trim((string) $atts[$attribute]) : '';

            if ($currentValue !== '') {
                $resolvedValue = $this->resolveAdminShortcodeAttributeValue($currentValue, $adminValues);
                if ($resolvedValue !== $currentValue) {
                    $atts[$attribute] = $resolvedValue;
                    continue;
                }
            }

            if (($currentValue === '' || $this->looksLikeAdminShortcodePlaceholder($currentValue))
                && isset($adminValues[$fieldName]) && $adminValues[$fieldName] !== '') {
                $atts[$attribute] = $adminValues[$fieldName];
            }
        }

        return $atts;
    }

    /**
     * Resolves the RSS URL to pass to the podcast player iframe.
     *
     * For the 'custom' platform, returns `media_data` directly as the RSS URL.
     * For all other platforms, the RSS URL is retrieved from the cached media
     * data's `channel.rssUrl` field (populated during the iTunes lookup fetch).
     * Returns '' when the URL cannot be determined.
     *
     * @param string              $playlistName  Playlist_name slug.
     * @param array<string,mixed> $mediaConfig   Admin media config array.
     * @param string              $podcastPlatform Platform key.
     * @return string Resolved RSS URL, or ''.
     */
    private function resolvePodcastPlayerRssUrl(string $playlistName, array $mediaConfig, string $podcastPlatform): string
    {
        $mediaData = trim((string) ($mediaConfig['media_data'] ?? ''));
        if ($mediaData === '') {
            return '';
        }

        if ($podcastPlatform === 'custom') {
            return $mediaData;
        }

        $cachedData = $this->readCachedMediaData($playlistName, 'podcast', $mediaConfig);
        if (is_array($cachedData)) {
            $channel = $cachedData['channel'] ?? null;
            if (is_array($channel) && !empty($channel['rssUrl'])) {
                return (string) $channel['rssUrl'];
            }
        }

        return '';
    }

    /**
     * Returns true when the value looks like an unresolved admin shortcode reference.
     *
     * Used by {@see self::applyPodcastPlayerAdminFieldDefaults()} to detect
     * placeholder values that should be replaced by the stored admin value even
     * when the attribute is technically non-empty.
     *
     * @param string $value The attribute value to inspect.
     * @return bool True when the value matches `{{field}}` or `[media-api-widget field=""]`.
     */
    private function looksLikeAdminShortcodePlaceholder(string $value): bool
    {
        $trimmed = trim($value);

        return preg_match('/^\{\{\s*[a-z0-9_-]+\s*\}\}$/i', $trimmed) === 1
            || preg_match('/^\[media-api-widget\s+field=(["\'])[a-z0-9_-]+\1\s*\]$/i', $trimmed) === 1;
    }
}
