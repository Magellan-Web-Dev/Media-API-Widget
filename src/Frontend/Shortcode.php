<?php
namespace MediaApiWidget\Frontend;

use MediaApiWidget\Config\Options;
use MediaApiWidget\PostIndex\RefreshManifest;
use MediaApiWidget\Support\MediaStore;
use MediaApiWidget\Support\PodcastNumbering;
use MediaApiWidget\Support\SafeRemoteRequest;
use MediaApiWidget\Support\XmlText;

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
    /** Collection layout values accepted by `multiplegridlayout`. */
    public const LAYOUT_GRID     = 'grid';
    public const LAYOUT_CAROUSEL = 'carousel';

    /**
     * Carousel attribute defaults.
     *
     * Also the safe fallbacks used when a supplied value is invalid or unusable.
     * `multiplegridgap` and `multiplegridminsize` keep their existing shortcode
     * defaults (48px / 400px), which the carousel reuses.
     */
    public const CAROUSEL_DEFAULTS = [
        'carouselstep'            => 'item',
        'carouselarrowsize'       => '48px',
        'carouselarrowiconsize'   => '24px',
        'carouselarrowcolor'      => '#ffffff',
        'carouselarrowbackground' => 'rgba(0, 0, 0, 0.6)',
        'carouselarrowoffset'     => '12px',
    ];

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
        add_shortcode('media-api-widget-grid-search', [$this, 'renderGridSearchShortcode']);
        add_action('wp_ajax_maw_grid_search', [$this, 'handleGridSearchAjax']);
        add_action('wp_ajax_nopriv_maw_grid_search', [$this, 'handleGridSearchAjax']);
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
     *   With `multiplegridlayout="carousel"` the same cards are returned in a
     *   horizontally scrolling carousel instead (also for a single item).
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
            'multiplegridusersearch' => 'false',
            'multiplegridperpage' => '12',
            'multiplegridmaxpages' => '',
            'multiplegridmaxpagedisplay' => '',
            'multiplegridlayout' => self::LAYOUT_GRID,
            'carouselstep' => self::CAROUSEL_DEFAULTS['carouselstep'],
            'carouselarrowleftimgurl' => '',
            'carouselarrowrightimgurl' => '',
            'carouselarrowsize' => self::CAROUSEL_DEFAULTS['carouselarrowsize'],
            'carouselarrowiconsize' => self::CAROUSEL_DEFAULTS['carouselarrowiconsize'],
            'carouselarrowcolor' => self::CAROUSEL_DEFAULTS['carouselarrowcolor'],
            'carouselarrowbackground' => self::CAROUSEL_DEFAULTS['carouselarrowbackground'],
            'carouselarrowoffset' => self::CAROUSEL_DEFAULTS['carouselarrowoffset'],
            'nostyling' => 'false',
            'noresults' => '',
            'podcastplayermode' => '',
            'podcastplayerbuttoncolor' => '',
            'podcastplayercolor' => '',
            'podcastprogressplayerbarcolor' => '',
            'podcastplayerhighlightcolor' => '',
            'podcastplayerfont' => '',
            'podcastplayerscrollcolor' => '',
            'podcastplayertextcolor' => '',
            'showepisodedateaftertitle' => '',
            'showlightbox' => 'true',
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
            'multiplegridusersearch' => $this->isTruthy((string) $atts['multiplegridusersearch']),
            'multiplegridperpage' => max(1, (int) ($atts['multiplegridperpage'] ?: 12)),
            'multiplegridmaxpages' => (int) ($atts['multiplegridmaxpages'] ?? 0) > 0 ? (int) $atts['multiplegridmaxpages'] : 0,
            'multiplegridmaxpagedisplay' => (int) ($atts['multiplegridmaxpagedisplay'] ?? 0) > 0 ? (int) $atts['multiplegridmaxpagedisplay'] : 0,
            'multiplegridlayout' => $this->sanitizeCollectionLayout((string) $atts['multiplegridlayout']),
            'carouselstep' => (string) $atts['carouselstep'],
            'carouselarrowleftimgurl' => (string) $atts['carouselarrowleftimgurl'],
            'carouselarrowrightimgurl' => (string) $atts['carouselarrowrightimgurl'],
            'carouselarrowsize' => (string) $atts['carouselarrowsize'],
            'carouselarrowiconsize' => (string) $atts['carouselarrowiconsize'],
            'carouselarrowcolor' => (string) $atts['carouselarrowcolor'],
            'carouselarrowbackground' => (string) $atts['carouselarrowbackground'],
            'carouselarrowoffset' => (string) $atts['carouselarrowoffset'],
            'nostyling' => $this->isTruthy((string) $atts['nostyling']),
            'noresults' => sanitize_text_field((string) $atts['noresults']),
            'podcastplayermode' => (string) $atts['podcastplayermode'],
            'podcastplayerbuttoncolor' => (string) $atts['podcastplayerbuttoncolor'],
            'podcastplayercolor' => (string) $atts['podcastplayercolor'],
            'podcastprogressplayerbarcolor' => (string) $atts['podcastprogressplayerbarcolor'],
            'podcastplayerhighlightcolor' => (string) $atts['podcastplayerhighlightcolor'],
            'podcastplayerfont' => (string) $atts['podcastplayerfont'],
            'podcastplayerscrollcolor' => (string) $atts['podcastplayerscrollcolor'],
            'podcastplayertextcolor' => (string) $atts['podcastplayertextcolor'],
            'showepisodedateaftertitle' => (string) $atts['showepisodedateaftertitle'],
            'showlightbox' => $this->isTruthy((string) $atts['showlightbox']),
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
                    $cls = $this->classAttr('maw-media-description-text', $itemData['nostyling'] ? '' : 'media-description-text');
                    return '<p' . $cls . $style . '>' . esc_html($descriptionOrTitle) . '</p>';
                }
            }
            return '';
        }

        if ($itemData['multiplegridusersearch'] && count($renderData) >= 1) {
            $settings = $this->buildRenderSettings($itemData, $mediaType);
            return $this->renderUserSearchGrid($renderData, $itemData, $settings, $playlistName, $mediaType);
        }

        // A grid has always required more than one source item (a one-item
        // playlist falls through to the single-card path below). A carousel
        // renders as a carousel for a single item too, so its markup, sizing
        // and hooks do not change when a playlist shrinks to one entry.
        $isCarousel = $itemData['multiplegridlayout'] === self::LAYOUT_CAROUSEL;
        if ($itemData['multiplegrid'] && (count($renderData) > 1 || ($isCarousel && count($renderData) === 1))) {
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
            $cards = [];
            foreach ($renderGridData as $row) {
                $gridSettings = $settings;
                if ($itemData['multiplegridtext'] !== '') {
                    $gridSettings['multipleGridText'] = (string) $itemData['multiplegridtext'];
                }

                $cards[] = $this->renderMediaItem($row, $gridSettings, false, $playlistName, $mediaType);
            }

            return $this->renderCollection($cards, $this->buildCollectionLayout($itemData), (bool) $itemData['nostyling']);
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

        $podcastData = $this->readCachedMediaData($playlistName, 'podcast', $mediaConfig);
        $iframeTitle = is_array($podcastData) && isset($podcastData['channel']['title']) && $podcastData['channel']['title'] !== ''
            ? wp_strip_all_tags((string) $podcastData['channel']['title'])
            : 'Podcast player';

        return '<iframe style="width: 100%; min-height: 32.5rem; max-height: 100%; display: block;" src="' . esc_url($src) . '" loading="lazy" title="' . esc_attr($iframeTitle) . '"></iframe>';
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
     * with SimpleXML, and normalizes items (strip tags, extract guid/pubDate).
     *
     * Persistence is handed to {@see MediaStore::store()}, so this path fires the
     * same `media_api_widget_data_before_store` filter and
     * `media_api_widget_data_stored` action as a wp_head refresh — with a `source`
     * of `shortcode_warmup` — writes the same backup JSON file, and honors the
     * configured `media_cache_ttl` rather than a fixed lifetime. The value
     * returned here is the *filtered* payload that was stored, so this request
     * renders exactly what a later request will read back.
     *
     * Returns an empty array for embed platforms (no RSS to fetch, no hooks).
     * Returns null on any network or parse error, and when a filter refuses the
     * data — in which case nothing was stored and the previous data is intact.
     *
     * @param string              $playlistName The playlist_name slug.
     * @param array<string,mixed> $mediaConfig  Admin media config array for this playlist.
     * @return array<string,mixed>|null Stored podcast data, or null on failure.
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
            $lookupRes = SafeRemoteRequest::get($lookupUrl);
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

        // $feedUrl is admin-configured (custom) or derived from the iTunes
        // lookup; the hardened wrapper applies SSRF, timeout and size limits.
        $rssRes = SafeRemoteRequest::get($feedUrl);
        if (is_wp_error($rssRes) || wp_remote_retrieve_response_code($rssRes) !== 200) {
            return null;
        }

        $xml = @simplexml_load_string((string) wp_remote_retrieve_body($rssRes), 'SimpleXMLElement', XmlText::RSS_PARSE_OPTIONS);
        if ($xml === false) {
            return null;
        }

        // Keep CDATA text and attributed GUIDs through the JSON conversion.
        XmlText::normalizeFeedItems($xml);

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

        // Persist through the shared store rather than writing the transient
        // directly, so this path cannot bypass the developer hooks, the backup
        // file, or the configured cache TTL.
        // Optional season/episode numbering, exactly as the wp_head refresh applies it.
        $parsed = PodcastNumbering::apply($parsed, $mediaConfig);

        $store = MediaStore::store(
            MediaStore::buildContext($playlistName, 'podcast', MediaStore::SOURCE_SHORTCODE_WARMUP, $platform),
            $parsed,
            [
                'ttl'          => (int) (Options::getCacheExpirations()['media_cache_ttl'] ?? 7200),
                'write_backup' => true,
                // A complete feed fetch: its membership may authorize post removal.
                'after_write'  => RefreshManifest::afterWriteCallback($playlistName, 'podcast', $parsed),
            ]
        );

        // Return what was accepted for storage, not the pre-filter value, so the
        // response this request renders matches what was stored.
        if (!is_array($store['data'])) {
            return null;
        }

        return $store['data'];
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
            'noStyling' => (bool) $itemData['nostyling'],
            'showLightbox' => (bool) $itemData['showlightbox'],
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
                'showLightbox' => true,
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

        $noStyling = !empty($settings['noStyling']);
        $styleClass = $noStyling ? '' : ('media_item' . ($settings['showTextOverlay'] ? '-text-overlay-enabled' : ''));
        $hookClass = 'maw-media-item' . ($settings['showTextOverlay'] ? ' maw-media-item-text-overlay-enabled' : '');
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
        $showLightboxAttr = ($settings['showLightbox'] ?? true) ? '' : ' data-showlightbox="false"';
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
            $pbClass = $this->classAttr('maw-media-item-play-button', $noStyling ? '' : 'media-item-play-button');
            $playButtonHtml = '<div' . $pbClass . ' style="' . esc_attr((string) $settings['playButtonStyling']) . '">' . $playButtonInner . '</div>';
        }

        $textOverlay = '';
        if ($settings['showTextOverlay']) {
            $audioTextStyle = $mediaType === 'audio' ? ' style="padding-bottom: max(10vw, 48px);"' : '';
            $toClass = $this->classAttr('maw-media-item-text-overlay', $noStyling ? '' : 'media-item-text-overlay');
            $textOverlay =
                '<div' . $toClass . $audioTextStyle . '>' .
                    '<h3>' . $this->renderOverlayTitle($title, $episode) . '</h3>' .
                    '<p>' . esc_html((string) $settings['instructionMessage']) . '</p>' .
                '</div>';
        }

        $playlistHeading = '';
        if ($playlistItem) {
            $phClass = $this->classAttr('maw-playlist-episode-text', $noStyling ? '' : 'playlist-episode-text');
            $playlistHeading = '<h3' . $phClass . '>' . esc_html($episode !== -1 ? ('Episode ' . $episode) : $title) . '</h3>';
        }

        $playBarHtml = '';
        if ($mediaType === 'audio' && $settings['showPlaybar']) {
            $playBarHtml = $this->audioPlayBarSvg((string) $settings['playbarColor'], $noStyling);
        }

        $multipleGridTextHtml = '';
        if (!$playlistItem && isset($settings['multipleGridText'])) {
            $multipleGridTextHtml = $this->renderMultipleGridText($item, (string) $settings['multipleGridText'], $noStyling);
        }

        $aClassAttr    = $this->classAttr($hookClass, $styleClass);
        $wrapperClass  = $this->classAttr('maw-media-item-thumbnail-text-wrapper', $noStyling ? '' : 'media-item-thumbnail-text-wrapper');
        $imgClass      = $this->classAttr('maw-media-item-thumbnail', $noStyling ? '' : 'media-item-thumbnail');
        $mediaItemHtml =
            '<a' . $style .
                $aClassAttr .
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
                $showLightboxAttr .
            '>' .
                '<div' . $wrapperClass . '>' .
                    '<img' . $imgClass . ' src="' . esc_url($thumbnailUrl) . '" width="' . esc_attr((string) $thumbnailWidth) . '" height="' . esc_attr((string) $thumbnailHeight) . '" alt="' . esc_attr($title) . '">' .
                    $playButtonHtml .
                    $textOverlay .
                '</div>' .
                $playlistHeading .
                $playBarHtml .
            '</a>';

        $itemComment = '<!-- ' . esc_html($name) . ' ' . esc_html($type) . ' item - ' . esc_html($title) . ' (Published On - ' . esc_html($publishedDate) . ') -->';

        if ($multipleGridTextHtml !== '') {
            $entryClass = $this->classAttr('maw-media-item-multiple-grid-entry', $noStyling ? '' : 'media-item-multiple-grid-entry');
            return $itemComment .
                '<div' . $entryClass . '>' .
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
            return esc_html($first) . '<br><span class="maw-sub-text sub-text">' . esc_html($rest) . '</span>';
        }

        return esc_html($title);
    }

    /**
     * Renders a title and/or description text block below a grid item.
     *
     * Supported modes:
     * - 'title' / 'description' — the item's title or description text.
     * - 'both' — both title and description.
     * - 'numberedtitle' — episode/season-aware title (see {@see self::numberedTitleText()}).
     * - 'numberedtitleanddescription' — the numbered title plus the description.
     *
     * Returns '' for any other mode value or when the resulting text is empty.
     *
     * @param array<string,mixed> $item The normalized media item.
     * @param string              $mode One of the supported mode strings.
     * @return string HTML `<div>` block(s), or ''.
     */
    private function renderMultipleGridText(array $item, string $mode, bool $noStyling = false): string
    {
        $cls = function (string $suffix) use ($noStyling): string {
            $hook    = 'maw-media-item-multiple-grid-text maw-media-item-multiple-grid-text-' . $suffix;
            $styling = $noStyling ? '' : ('media-item-multiple-grid-text media-item-multiple-grid-text-' . $suffix);
            return $this->classAttr($hook, $styling);
        };

        if ($mode === 'both') {
            $titleText = trim((string) ($item['title'] ?? ''));
            $descText  = trim((string) ($item['description'] ?? ''));
            if ($titleText === '' && $descText === '') {
                return '';
            }
            $out = '';
            if ($titleText !== '') {
                $out .= '<div' . $cls('title') . '>' . esc_html($titleText) . '</div>';
            }
            if ($descText !== '') {
                $out .= '<div' . $cls('description') . '>' . esc_html($descText) . '</div>';
            }
            return $out;
        }

        if ($mode === 'numberedtitle' || $mode === 'numberedtitleanddescription') {
            $titleText = $this->numberedTitleText($item);
            $descText  = trim((string) ($item['description'] ?? ''));
            $out = '';
            if ($titleText !== '') {
                $out .= '<div' . $cls('title') . '>' . esc_html($titleText) . '</div>';
            }
            if ($mode === 'numberedtitleanddescription' && $descText !== '') {
                $out .= '<div' . $cls('description') . '>' . esc_html($descText) . '</div>';
            }
            return $out;
        }

        $text = '';
        if ($mode === 'title') {
            $text = trim((string) ($item['title'] ?? ''));
        } elseif ($mode === 'description') {
            $text = trim((string) ($item['description'] ?? ''));
        }

        if ($text === '') {
            return '';
        }

        return '<div' . $cls($mode) . '>' . esc_html($text) . '</div>';
    }

    /**
     * Builds the episode/season-aware title text for a numbered grid item.
     *
     * Mirrors how the playlist resolves numbering: when the item has no episode
     * number (episode === -1, i.e. the playlist's sort mode is "Normal" or no
     * number was detected), the plain video title is returned. When an episode
     * number is present and a valid season was parsed via the season/episode
     * regex, returns "Season {s}, Episode {e}"; otherwise returns "Episode {e}".
     *
     * @param array<string,mixed> $item The normalized media item.
     * @return string The display title text (unescaped; caller must escape).
     */
    private function numberedTitleText(array $item): string
    {
        $episode = isset($item['episode']) ? (int) $item['episode'] : -1;
        if ($episode === -1) {
            return trim((string) ($item['title'] ?? ''));
        }

        $season = isset($item['season']) ? (int) $item['season'] : -1;
        if ($season !== -1) {
            return 'Season ' . $season . ' Episode ' . $episode;
        }

        return 'Episode ' . $episode;
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
    private function audioPlayBarSvg(string $color = '#fff', bool $noStyling = false): string
    {
        $svgClass = $this->classAttr('maw-audio-play-bar', $noStyling ? '' : 'audio-play-bar');
        return '<svg xmlns="http://www.w3.org/2000/svg" id="audio_play_bar"' . $svgClass . ' data-name="Layer 1" viewBox="0 0 453 45"><defs><style>.pb-2{fill:#231f20}.pb-3{fill:none;stroke:#231f20;stroke-width:3px}</style></defs><rect style="fill:' . esc_attr($color) . '" width="453" height="45"></rect><polygon class="pb-2" points="42.99 22 17.01 7 17.01 37 42.99 22"></polygon><line class="pb-3" x1="52" y1="9" x2="52" y2="36"></line><line class="pb-3" x1="58" y1="9" x2="58" y2="36"></line><line class="pb-3" x1="64" y1="9" x2="64" y2="36"></line><line class="pb-3" x1="70" y1="9" x2="70" y2="36"></line><line class="pb-3" x1="76" y1="9" x2="76" y2="36"></line><line class="pb-3" x1="82" y1="9" x2="82" y2="36"></line><line class="pb-3" x1="88" y1="9" x2="88" y2="36"></line><line class="pb-3" x1="94" y1="9" x2="94" y2="36"></line><line class="pb-3" x1="100" y1="9" x2="100" y2="36"></line><line class="pb-3" x1="106" y1="9" x2="106" y2="36"></line><line class="pb-3" x1="112" y1="9" x2="112" y2="36"></line><line class="pb-3" x1="118" y1="9" x2="118" y2="36"></line><line class="pb-3" x1="124" y1="9" x2="124" y2="36"></line><line class="pb-3" x1="130" y1="9" x2="130" y2="36"></line><line class="pb-3" x1="136" y1="9" x2="136" y2="36"></line><line class="pb-3" x1="142" y1="9" x2="142" y2="36"></line><line class="pb-3" x1="148" y1="9" x2="148" y2="36"></line><line class="pb-3" x1="154" y1="9" x2="154" y2="36"></line><line class="pb-3" x1="160" y1="9" x2="160" y2="36"></line><line class="pb-3" x1="166" y1="9" x2="166" y2="36"></line><line class="pb-3" x1="172" y1="9" x2="172" y2="36"></line><line class="pb-3" x1="178" y1="9" x2="178" y2="36"></line><line class="pb-3" x1="184" y1="9" x2="184" y2="36"></line><line class="pb-3" x1="190" y1="9" x2="190" y2="36"></line><line class="pb-3" x1="196" y1="9" x2="196" y2="36"></line><line class="pb-3" x1="202" y1="9" x2="202" y2="36"></line><line class="pb-3" x1="208" y1="9" x2="208" y2="36"></line><line class="pb-3" x1="214" y1="9" x2="214" y2="36"></line><line class="pb-3" x1="220" y1="9" x2="220" y2="36"></line><line class="pb-3" x1="226" y1="9" x2="226" y2="36"></line><line class="pb-3" x1="232" y1="9" x2="232" y2="36"></line><line class="pb-3" x1="238" y1="9" x2="238" y2="36"></line><line class="pb-3" x1="244" y1="9" x2="244" y2="36"></line><line class="pb-3" x1="250" y1="9" x2="250" y2="36"></line><line class="pb-3" x1="256" y1="9" x2="256" y2="36"></line><line class="pb-3" x1="262" y1="9" x2="262" y2="36"></line><line class="pb-3" x1="268" y1="9" x2="268" y2="36"></line><line class="pb-3" x1="274" y1="9" x2="274" y2="36"></line><line class="pb-3" x1="280" y1="9" x2="280" y2="36"></line><line class="pb-3" x1="286" y1="9" x2="286" y2="36"></line><line class="pb-3" x1="292" y1="9" x2="292" y2="36"></line><line class="pb-3" x1="298" y1="9" x2="298" y2="36"></line><line class="pb-3" x1="304" y1="9" x2="304" y2="36"></line><line class="pb-3" x1="310" y1="9" x2="310" y2="36"></line><line class="pb-3" x1="316" y1="9" x2="316" y2="36"></line><line class="pb-3" x1="322" y1="9" x2="322" y2="36"></line><line class="pb-3" x1="328" y1="9" x2="328" y2="36"></line><line class="pb-3" x1="334" y1="9" x2="334" y2="36"></line><line class="pb-3" x1="340" y1="9" x2="340" y2="36"></line><line class="pb-3" x1="346" y1="9" x2="346" y2="36"></line><line class="pb-3" x1="352" y1="9" x2="352" y2="36"></line><line class="pb-3" x1="358" y1="9" x2="358" y2="36"></line><line class="pb-3" x1="364" y1="9" x2="364" y2="36"></line><line class="pb-3" x1="370" y1="9" x2="370" y2="36"></line><line class="pb-3" x1="376" y1="9" x2="376" y2="36"></line><line class="pb-3" x1="382" y1="9" x2="382" y2="36"></line><line class="pb-3" x1="388" y1="9" x2="388" y2="36"></line><line class="pb-3" x1="394" y1="9" x2="394" y2="36"></line><line class="pb-3" x1="400" y1="9" x2="400" y2="36"></line><line class="pb-3" x1="406" y1="9" x2="406" y2="36"></line><line class="pb-3" x1="412" y1="9" x2="412" y2="36"></line><line class="pb-3" x1="418" y1="9" x2="418" y2="36"></line><line class="pb-3" x1="424" y1="9" x2="424" y2="36"></line><line class="pb-3" x1="430" y1="9" x2="430" y2="36"></line><line class="pb-3" x1="436" y1="9" x2="436" y2="36"></line></svg>';
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
     * Builds a ` class="..."` attribute string from one or more class names.
     *
     * Empty/blank arguments are dropped, so callers can pass an always-present
     * namespaced hook class (e.g. "maw-media-item") alongside a styling class
     * that is suppressed when `nostyling="true"` is in effect. The hook classes
     * carry no plugin CSS of their own; they exist purely as stable targets for
     * site-authored styles, which is why they are emitted regardless of the
     * nostyling flag. Returns '' only when every argument is empty.
     *
     * @param string ...$classes Class names (hook first, styling second by convention).
     * @return string ` class="..."` attribute, or '' when no class names remain.
     */
    private function classAttr(string ...$classes): string
    {
        $list = array_filter(
            array_map('trim', $classes),
            static fn(string $c): bool => $c !== ''
        );

        if ($list === []) {
            return '';
        }

        return ' class="' . esc_attr(implode(' ', $list)) . '"';
    }

    /**
     * Validates and normalizes the multiplegridtext attribute value.
     *
     * Returns the value when the trimmed, lowercased input matches one of the
     * supported modes ('title', 'description', 'both', 'numberedtitle',
     * 'numberedtitleanddescription'). Returns '' for any other value, which
     * disables the grid text feature.
     *
     * @param string $value Raw attribute value.
     * @return string A supported mode string, or ''.
     */
    private function sanitizeMultipleGridText(string $value): string
    {
        $v = strtolower(trim($value));
        return match($v) {
            'title', 'description', 'both', 'numberedtitle', 'numberedtitleanddescription' => $v,
            default                        => '',
        };
    }

    /**
     * Normalizes the multiplegridlayout attribute value.
     *
     * Only `carousel` selects the carousel; every other value — including an
     * empty or unknown one — keeps the grid, so existing content is unchanged.
     *
     * @param string $value Raw attribute value.
     * @return string self::LAYOUT_CAROUSEL or self::LAYOUT_GRID.
     */
    private function sanitizeCollectionLayout(string $value): string
    {
        return strtolower(trim($value)) === self::LAYOUT_CAROUSEL ? self::LAYOUT_CAROUSEL : self::LAYOUT_GRID;
    }

    /**
     * Builds the layout description shared by every collection render path.
     *
     * A grid keeps `multiplegridgap` / `multiplegridminsize` exactly as given,
     * because the grid has always passed them through to its inline style. A
     * carousel resolves them (and its own settings) to validated values.
     *
     * @param array<string,mixed> $itemData Resolved shortcode item options.
     * @return array<string,string> Layout description.
     */
    private function buildCollectionLayout(array $itemData): array
    {
        if (($itemData['multiplegridlayout'] ?? self::LAYOUT_GRID) !== self::LAYOUT_CAROUSEL) {
            return [
                'layout'  => self::LAYOUT_GRID,
                'gap'     => (string) $itemData['multiplegridgap'],
                'minsize' => (string) $itemData['multiplegridminsize'],
            ];
        }

        return $this->normalizeCarouselConfig([
            'gap'             => (string) $itemData['multiplegridgap'],
            'minsize'         => (string) $itemData['multiplegridminsize'],
            'step'            => (string) ($itemData['carouselstep'] ?? ''),
            'arrowleft'       => (string) ($itemData['carouselarrowleftimgurl'] ?? ''),
            'arrowright'      => (string) ($itemData['carouselarrowrightimgurl'] ?? ''),
            'arrowsize'       => (string) ($itemData['carouselarrowsize'] ?? ''),
            'arrowiconsize'   => (string) ($itemData['carouselarrowiconsize'] ?? ''),
            'arrowcolor'      => (string) ($itemData['carouselarrowcolor'] ?? ''),
            'arrowbackground' => (string) ($itemData['carouselarrowbackground'] ?? ''),
            'arrowoffset'     => (string) ($itemData['carouselarrowoffset'] ?? ''),
        ]);
    }

    /**
     * Validates a carousel configuration, substituting defaults for bad values.
     *
     * Used when a carousel is first rendered and again when a stored searchable
     * carousel configuration is read back for an AJAX response, so both paths
     * render from identical, safe values.
     *
     * @param array<string,mixed> $raw Unvalidated configuration.
     * @return array<string,string> Validated carousel layout description.
     */
    private function normalizeCarouselConfig(array $raw): array
    {
        $text = static fn(string $key): string => is_scalar($raw[$key] ?? null) ? trim((string) $raw[$key]) : '';

        return [
            'layout'          => self::LAYOUT_CAROUSEL,
            'gap'             => $this->sanitizeCssLength($text('gap'), '48px', true),
            'minsize'         => $this->sanitizeCssLength($text('minsize'), '400px', false),
            'step'            => strtolower($text('step')) === 'group' ? 'group' : 'item',
            'arrowleft'       => $text('arrowleft'),
            'arrowright'      => $text('arrowright'),
            'arrowsize'       => $this->sanitizeCssLength($text('arrowsize'), self::CAROUSEL_DEFAULTS['carouselarrowsize'], false),
            'arrowiconsize'   => $this->sanitizeCssLength($text('arrowiconsize'), self::CAROUSEL_DEFAULTS['carouselarrowiconsize'], false),
            'arrowcolor'      => $this->sanitizeCssColor($text('arrowcolor'), self::CAROUSEL_DEFAULTS['carouselarrowcolor']),
            'arrowbackground' => $this->sanitizeCssColor($text('arrowbackground'), self::CAROUSEL_DEFAULTS['carouselarrowbackground']),
            'arrowoffset'     => $this->sanitizeCssLength($text('arrowoffset'), self::CAROUSEL_DEFAULTS['carouselarrowoffset'], true),
        ];
    }

    /**
     * Wraps rendered media cards in the collection's layout.
     *
     * The grid branch produces exactly the markup every grid path produced
     * before carousel support, including placing an empty-state message inside
     * the grid container. A carousel with no cards returns the message alone,
     * without carousel controls.
     *
     * @param array<int,string>    $cards     Card HTML from renderMediaItem(), in order.
     * @param array<string,string> $layout    From buildCollectionLayout() / normalizeCarouselConfig().
     * @param bool                 $noStyling Whether nostyling is in effect.
     * @param string               $emptyHtml Markup shown when there are no cards.
     * @return string Collection HTML.
     */
    private function renderCollection(array $cards, array $layout, bool $noStyling, string $emptyHtml = ''): string
    {
        if (($layout['layout'] ?? self::LAYOUT_GRID) === self::LAYOUT_CAROUSEL) {
            return $cards === [] ? $emptyHtml : $this->renderCarousel($cards, $layout, $noStyling);
        }

        $gridClass = $this->classAttr('maw-media-items-grid-layout', $noStyling ? '' : 'media_items_multiple_grid_layout');

        return '<div' . $gridClass . ' style="gap: ' . esc_attr((string) $layout['gap']) . '; grid-template-columns: repeat(auto-fill, minmax(min(100%, ' . esc_attr((string) $layout['minsize']) . '), 1fr));">'
            . ($cards === [] ? $emptyHtml : implode('', $cards))
            . '</div>';
    }

    /**
     * Renders media cards as a carousel.
     *
     * Each card is the unchanged output of renderMediaItem(), wrapped in a
     * slide element — cards are never cloned. Structure and behavior are
     * carried by `data-maw-carousel*` attributes, which the functional CSS and
     * the front-end script target and which nostyling leaves in place. The
     * `media-carousel*` styling classes carry only the arrow appearance and are
     * omitted with nostyling; the `maw-carousel*` hook classes carry no plugin
     * CSS, like every other `maw-` hook class.
     *
     * The arrows are rendered hidden: the script reveals them only when some
     * cards do not fit, so without JavaScript the row simply scrolls natively.
     *
     * @param array<int,string>    $cards     Card HTML, in order (at least one).
     * @param array<string,string> $layout    Validated carousel layout.
     * @param bool                 $noStyling Whether nostyling is in effect.
     * @return string Carousel HTML.
     */
    private function renderCarousel(array $cards, array $layout, bool $noStyling): string
    {
        $total  = count($cards);
        $slides = '';

        foreach (array_values($cards) as $i => $card) {
            $slides .=
                '<div class="maw-carousel-slide" data-maw-carousel-slide="" role="group" aria-roledescription="' . esc_attr__('slide', 'media-api-widget') . '"'
                    . ' aria-label="' . esc_attr(sprintf(
                        /* translators: 1: slide number, 2: total number of slides. */
                        __('%1$d of %2$d', 'media-api-widget'),
                        $i + 1,
                        $total
                    )) . '">' .
                    $card .
                '</div>';
        }

        $style =
            '--maw-carousel-gap: ' . $layout['gap'] . ';' .
            ' --maw-carousel-min: ' . $layout['minsize'] . ';' .
            ' --maw-carousel-arrow-size: ' . $layout['arrowsize'] . ';' .
            ' --maw-carousel-arrow-icon-size: ' . $layout['arrowiconsize'] . ';' .
            ' --maw-carousel-arrow-offset: ' . $layout['arrowoffset'] . ';' .
            ' --maw-carousel-arrow-color: ' . $layout['arrowcolor'] . ';' .
            ' --maw-carousel-arrow-background: ' . $layout['arrowbackground'] . ';';

        $group = $layout['step'] === 'group';

        return
            '<div' . $this->classAttr('maw-carousel', $noStyling ? '' : 'media-carousel') .
                ' data-maw-carousel=""' .
                ' data-maw-carousel-step="' . esc_attr($layout['step']) . '"' .
                ' style="' . esc_attr($style) . '"' .
            '>' .
                $this->renderCarouselArrow('left', $group ? __('Previous items', 'media-api-widget') : __('Previous item', 'media-api-widget'), $layout['arrowleft'], $noStyling) .
                '<div class="maw-carousel-viewport" data-maw-carousel-viewport="" role="group" aria-roledescription="' . esc_attr__('carousel', 'media-api-widget') . '" aria-label="' . esc_attr__('Media items', 'media-api-widget') . '" tabindex="0">' .
                    '<div class="maw-carousel-track" data-maw-carousel-track="">' . $slides . '</div>' .
                '</div>' .
                $this->renderCarouselArrow('right', $group ? __('Next items', 'media-api-widget') : __('Next item', 'media-api-widget'), $layout['arrowright'], $noStyling) .
            '</div>';
    }

    /**
     * Renders one carousel arrow button.
     *
     * A custom image replaces the built-in chevron for that direction only.
     * The image is decorative (empty alt): the button's own label names it.
     *
     * @param string $side      'left' or 'right'.
     * @param string $label     Accessible button label.
     * @param string $imageUrl  Custom arrow image URL, or '' for the built-in arrow.
     * @param bool   $noStyling Whether nostyling is in effect.
     * @return string Button HTML.
     */
    private function renderCarouselArrow(string $side, string $label, string $imageUrl, bool $noStyling): string
    {
        $imageUrl = trim($imageUrl) !== '' ? esc_url($imageUrl) : '';

        if ($imageUrl !== '') {
            $icon = '<img class="maw-carousel-arrow-icon" src="' . $imageUrl . '" alt="" draggable="false">';
        } else {
            $path = $side === 'left' ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7';
            $icon = '<svg class="maw-carousel-arrow-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">'
                . '<path d="' . $path . '" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>'
                . '</svg>';
        }

        return
            '<button type="button"' . $this->classAttr('maw-carousel-arrow maw-carousel-arrow-' . $side, $noStyling ? '' : 'media-carousel-arrow') .
                ' data-maw-carousel-arrow="' . esc_attr($side) . '"' .
                ' aria-label="' . esc_attr($label) . '"' .
                ' hidden' .
            '>' . $icon . '</button>';
    }

    /**
     * Validates a CSS length for carousel sizing.
     *
     * Accepts a non-negative number with an absolute, font-relative, viewport,
     * container or percentage unit (px, rem, em, %, vw, …), a bare number
     * (read as pixels), or a calc() / min() / max() / clamp() expression made
     * only of numbers, units and arithmetic. Anything else — including values
     * that could break out of the inline style — yields the default.
     *
     * @param string $value     Raw value.
     * @param string $default   Fallback length.
     * @param bool   $allowZero Whether zero is a usable value.
     * @return string Validated CSS length.
     */
    private function sanitizeCssLength(string $value, string $default, bool $allowZero): string
    {
        $v = strtolower(trim($value));
        if ($v === '' || strlen($v) > 120) {
            return $default;
        }

        if (preg_match('/^\d*\.?\d+$/', $v) === 1) {
            $v .= 'px';
        }

        $units = 'px|rem|em|%|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|ch|ex|cqw|cqh|cqi|cqb|cqmin|cqmax|pt|pc|cm|mm|in|q';
        if (preg_match('/^(\d*\.?\d+)(' . $units . ')$/', $v, $m) === 1) {
            return ($allowZero || (float) $m[1] > 0) ? $v : $default;
        }

        if (preg_match('/^(calc|min|max|clamp)\([0-9a-z.%+\-*\/\s,()]+\)$/', $v) === 1
            && substr_count($v, '(') === substr_count($v, ')')) {
            return $v;
        }

        return $default;
    }

    /**
     * Validates a CSS color for the carousel arrows.
     *
     * Accepts hex (with or without '#'), rgb()/rgba()/hsl()/hsla(), a named
     * color or keyword such as `transparent` or `currentcolor`, and a
     * `var(--name)` reference (for example an Elementor global color).
     *
     * @param string $value   Raw value.
     * @param string $default Fallback color.
     * @return string Validated CSS color.
     */
    private function sanitizeCssColor(string $value, string $default): string
    {
        $v = trim($value);
        if ($v === '' || strlen($v) > 80) {
            return $default;
        }

        if (preg_match('/^#?(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v) === 1) {
            return '#' . ltrim($v, '#');
        }

        if (preg_match('/^(?:rgba?|hsla?)\(\s*[0-9a-z.,%\s\/+\-]+\)$/i', $v) === 1
            || preg_match('/^var\(\s*--[a-z0-9_-]+\s*\)$/i', $v) === 1) {
            return $v;
        }

        if (preg_match('/^[a-z]{3,30}$/i', $v) === 1) {
            return strtolower($v);
        }

        return $default;
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

    /**
     * Returns the identifier that links a user-search grid to its search bar.
     *
     * The default, `{playlist_name}_{media_platform}`, is the identifier every
     * grid and [media-api-widget-grid-search] bar has always used; it is also
     * part of the grid's `maw_page_{id}` query parameter. The
     * `media_api_widget_grid_search_id` filter lets the Elementor integration
     * give one widget instance its own identifier, so two grids for the same
     * playlist — and their separately placed search bars — stay independent.
     * The integration only attaches the filter while that widget renders. With
     * no callback registered, the default is returned unchanged.
     *
     * @param string $playlistName Sanitized playlist_name slug.
     * @param string $mediaType    Sanitized media type.
     * @return string Grid search identifier (sanitize_key-safe).
     */
    private function resolveGridSearchId(string $playlistName, string $mediaType): string
    {
        $default  = $playlistName . '_' . $mediaType;
        $filtered = apply_filters('media_api_widget_grid_search_id', $default, $playlistName, $mediaType);
        $filtered = is_string($filtered) ? sanitize_key($filtered) : '';

        return $filtered !== '' ? $filtered : $default;
    }

    /**
     * Renders the search bar for a user-searchable grid.
     *
     * Associates with a [media-api-widget-render] grid that has
     * multiplegridusersearch="true" on the same page, matching by
     * playlist_name + media_platform. The JS binds them together.
     *
     * Usage: [media-api-widget-grid-search playlist_name="my_show" media_platform="youtube"]
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     * @return string HTML search bar string, or ''.
     */
    public function renderGridSearchShortcode($atts = []): string
    {
        $atts = is_array($atts) ? $atts : [];
        $atts = shortcode_atts([
            'playlist_name'     => '',
            'media_platform'    => 'youtube',
            'placeholder'       => 'Search...',
            'searchbyenabled'   => 'true',
            'clearsearchbutton' => 'true',
        ], $atts, 'media-api-widget-grid-search');

        $playlistName      = sanitize_key((string) $atts['playlist_name']);
        $mediaType         = sanitize_key((string) $atts['media_platform']);
        $placeholder       = sanitize_text_field((string) $atts['placeholder']);
        $searchByEnabled   = filter_var($atts['searchbyenabled'], FILTER_VALIDATE_BOOLEAN);
        $clearButtonEnabled = filter_var($atts['clearsearchbutton'], FILTER_VALIDATE_BOOLEAN);

        if ($playlistName === '' || $mediaType === '') {
            return '';
        }

        $gridId = $this->resolveGridSearchId($playlistName, $mediaType);
        $fieldId = sanitize_html_class($gridId);

        $selectId = 'maw-search-by-' . $fieldId;
        $selectHtml = $searchByEnabled
            ? '<select id="' . esc_attr($selectId) . '" name="' . esc_attr($selectId) . '" class="maw-search-by" aria-label="' . esc_attr__('Search by', 'media-api-widget') . '">' .
                  '<option value="any">Any</option>' .
                  '<option value="title">Title</option>' .
                  '<option value="description">Description</option>' .
              '</select>'
            : '';

        $inputId = 'maw-search-input-' . $fieldId;

        $clearButtonHtml = $clearButtonEnabled
            ? '<button type="button" class="maw-search-clear" aria-label="' . esc_attr__('Clear search', 'media-api-widget') . '">&times;</button>'
            : '';

        return
            '<div class="maw-grid-search-bar" data-maw-for="' . esc_attr($gridId) . '">' .
                '<input type="text" id="' . esc_attr($inputId) . '" name="' . esc_attr($inputId) . '" class="maw-search-input" placeholder="' . esc_attr($placeholder) . '" aria-label="' . esc_attr__('Search', 'media-api-widget') . '">' .
                $clearButtonHtml .
                $selectHtml .
            '</div>';
    }

    /**
     * AJAX handler for the user-searchable paginated grid.
     *
     * Reads the grid configuration stored in a transient by renderUserSearchGrid(),
     * applies the user search term and pagination, renders the grid items and
     * pagination HTML server-side, and returns them as JSON. A collection stored
     * with carousel layout is returned as carousel markup for the requested
     * result page, with the same carousel settings it was first rendered with.
     *
     * Accepts POST fields: nonce, playlist_name, media_platform, grid_key,
     * search_term, search_by (any|title|description), page, per_page.
     *
     * @return void Outputs JSON and exits via wp_send_json_*.
     */
    public function handleGridSearchAjax(): void
    {
        if (!check_ajax_referer('maw_grid_search', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce.'], 403);
            return;
        }

        $playlistName = sanitize_key((string) wp_unslash($_POST['playlist_name'] ?? ''));
        $mediaType    = sanitize_key((string) wp_unslash($_POST['media_platform'] ?? ''));
        $gridKey      = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['grid_key'] ?? ''));
        $searchTerm   = sanitize_text_field((string) wp_unslash($_POST['search_term'] ?? ''));
        $searchBy     = in_array((string) ($_POST['search_by'] ?? ''), ['title', 'description', 'any'], true)
                            ? (string) $_POST['search_by'] : 'any';
        $page         = max(1, (int) ($_POST['page'] ?? 1));
        $perPage      = max(1, min(100, (int) ($_POST['per_page'] ?? 12)));
        $baseUrl      = esc_url_raw((string) wp_unslash($_POST['base_url'] ?? ''));

        if ($playlistName === '' || $mediaType === '' || $gridKey === '') {
            wp_send_json_error(['message' => 'Missing required parameters.'], 400);
            return;
        }

        $gridConfig = get_transient('maw_grid_' . $gridKey);
        if (!is_array($gridConfig)) {
            wp_send_json_error(['message' => 'Grid configuration not found. Please reload the page.'], 404);
            return;
        }

        $settings         = is_array($gridConfig['settings'] ?? null) ? $gridConfig['settings'] : [];
        $gap              = sanitize_text_field((string) ($gridConfig['gap'] ?? '48px'));
        $minsize          = sanitize_text_field((string) ($gridConfig['minsize'] ?? '400px'));
        $multiplegridtext = $this->sanitizeMultipleGridText((string) ($gridConfig['multiplegridtext'] ?? ''));
        $episodeRange     = sanitize_text_field((string) ($gridConfig['episodeRange'] ?? ''));
        $showall          = (bool) ($gridConfig['showall'] ?? false);
        $noresults        = sanitize_text_field((string) ($gridConfig['noresults'] ?? ''));
        $noStyling        = (bool) ($gridConfig['noStyling'] ?? false);
        $maxPages         = (int) ($gridConfig['maxPages'] ?? 0);
        $maxDisplay       = (int) ($gridConfig['maxDisplay'] ?? 0);
        $instanceGridId   = sanitize_key((string) ($gridConfig['gridId'] ?? ''));
        $settings['noStyling'] = $noStyling;

        // Grids stored before carousel support (and every grid since) have no
        // 'carousel' entry and keep rendering exactly as before. A carousel's
        // stored configuration is normalized again on read.
        $layout = is_array($gridConfig['carousel'] ?? null)
            ? $this->normalizeCarouselConfig($gridConfig['carousel'])
            : ['layout' => self::LAYOUT_GRID, 'gap' => $gap, 'minsize' => $minsize];

        $mediaConfig = $this->findMediaConfig($playlistName, $mediaType);
        if ($mediaConfig === null) {
            wp_send_json_error(['message' => 'Playlist not found.'], 404);
            return;
        }

        $podcastPlatform = sanitize_key((string) ($mediaConfig['podcast_platform'] ?? 'custom'));
        $mediaData       = $this->readCachedMediaData($playlistName, $mediaType, $mediaConfig);
        if ($mediaType === 'podcast' && $podcastPlatform === 'embed' && $mediaData === null) {
            $mediaData = [];
        }
        if ($mediaData === null) {
            wp_send_json_error(['message' => 'No cached data found. Please try reloading the page.'], 404);
            return;
        }

        $renderData = $this->normalizeRenderData($mediaData, $mediaType, $podcastPlatform, '', '');

        if (!$showall && preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $episodeRange, $m) === 1) {
            $min        = (int) $m[1];
            $max        = (int) $m[2];
            $renderData = array_values(array_filter($renderData, static function ($item) use ($min, $max) {
                $episode = isset($item['episode']) ? (int) $item['episode'] : -1;
                return $episode >= $min && $episode <= $max;
            }));
        }

        if ($searchTerm !== '') {
            $term       = $searchTerm;
            $by         = $searchBy;
            $renderData = array_values(array_filter($renderData, static function ($item) use ($term, $by) {
                if ($by === 'description') {
                    return stripos((string) ($item['description'] ?? ''), $term) !== false;
                }
                if ($by === 'title') {
                    return stripos((string) ($item['title'] ?? ''), $term) !== false;
                }
                return stripos((string) ($item['title'] ?? ''), $term) !== false
                    || stripos((string) ($item['description'] ?? ''), $term) !== false;
            }));
        }

        $total      = count($renderData);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($maxPages > 0) {
            $totalPages = min($totalPages, $maxPages);
        }
        $page   = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $pageData   = array_slice($renderData, $offset, $perPage);

        $gridSettings = $settings;
        if ($multiplegridtext !== '') {
            $gridSettings['multipleGridText'] = $multiplegridtext;
        }

        $cards = [];
        foreach ($pageData as $row) {
            $cards[] = $this->renderMediaItem($row, $gridSettings, false, $playlistName, $mediaType);
        }

        $emptyHtml = $noresults !== ''
            ? '<p class="maw-grid-noresults">' . esc_html($noresults) . '</p>'
            : '<h3 class="media-api-widget-err-msg">No ' . esc_html($mediaType) . ' items found.</h3>';

        $gridWrapperHtml = $this->renderCollection($cards, $layout, $noStyling, $emptyHtml);

        // A grid rendered with its own search id (see resolveGridSearchId())
        // stored that id with its settings; its links keep its page parameter.
        $pageParam = 'maw_page_' . ($instanceGridId !== '' ? $instanceGridId : $playlistName . '_' . $mediaType);

        wp_send_json_success([
            'html'        => $gridWrapperHtml,
            'pagination'  => $this->renderPaginationHtml($page, $totalPages, $baseUrl, $pageParam, $maxDisplay),
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
        ]);
    }

    /**
     * Renders the initial paginated wrapper for a user-searchable grid.
     *
     * Stores the render settings in a transient (keyed by a short hash) so the
     * AJAX handler can retrieve them without trusting client-supplied data.
     * Outputs page 1 of the base-filtered results, the pagination controls,
     * and the wrapper div with data attributes the front-end JS reads.
     *
     * @param array<int,array<string,mixed>> $renderData   Normalized item array.
     * @param array<string,mixed>            $itemData     Resolved shortcode item options.
     * @param array<string,mixed>            $settings     Render settings from buildRenderSettings().
     * @param string                         $playlistName Playlist_name slug.
     * @param string                         $mediaType    'youtube' or 'podcast'.
     * @return string HTML wrapper string.
     */
    private function renderUserSearchGrid(array $renderData, array $itemData, array $settings, string $playlistName, string $mediaType): string
    {
        $perPage      = (int) $itemData['multiplegridperpage'];
        $maxPages     = (int) ($itemData['multiplegridmaxpages'] ?? 0);
        $maxDisplay   = (int) ($itemData['multiplegridmaxpagedisplay'] ?? 0);
        $gap          = (string) $itemData['multiplegridgap'];
        $minsize      = (string) $itemData['multiplegridminsize'];
        $gridtext     = (string) $itemData['multiplegridtext'];
        $episodeRange = (string) $itemData['multiplegridepisoderange'];
        $showall      = (bool) $itemData['multiplegridshowall'];
        $noresults    = (string) $itemData['noresults'];
        $noStyling    = (bool) $itemData['nostyling'];
        $gridId       = $this->resolveGridSearchId($playlistName, $mediaType);

        // Non-empty only when the grid was given its own search id. Such a grid
        // gets its own settings transient and records the id for the AJAX
        // handler; a grid with the default id hashes and stores as it always has.
        $instanceGridId = $gridId !== $playlistName . '_' . $mediaType ? $gridId : '';

        $gridSettings = $settings;
        if ($gridtext !== '') {
            $gridSettings['multipleGridText'] = $gridtext;
        }

        // Carousel settings are part of the stored configuration and of its
        // key, so two collections differing only in layout or arrow settings
        // never share a transient. The identity is empty for a grid, which
        // keeps every grid's key and transient exactly as before.
        $layout           = $this->buildCollectionLayout($itemData);
        $isCarousel       = $layout['layout'] === self::LAYOUT_CAROUSEL;
        $layoutIdentity   = $isCarousel ? (string) wp_json_encode($layout) : '';

        $gridKey = substr(md5(wp_json_encode($gridSettings) . $playlistName . $mediaType . $gap . $minsize . $episodeRange . (string) $perPage . (string) $maxPages . (string) $maxDisplay . $instanceGridId . $layoutIdentity), 0, 16);
        $gridConfig = [
            'settings'         => $gridSettings,
            'gap'              => $gap,
            'minsize'          => $minsize,
            'multiplegridtext' => $gridtext,
            'episodeRange'     => $episodeRange,
            'showall'          => $showall,
            'noresults'        => $noresults,
            'noStyling'        => $noStyling,
            'maxPages'         => $maxPages,
            'maxDisplay'       => $maxDisplay,
        ];
        if ($instanceGridId !== '') {
            $gridConfig['gridId'] = $instanceGridId;
        }
        if ($isCarousel) {
            $gridConfig['carousel'] = $layout;
        }
        set_transient('maw_grid_' . $gridKey, $gridConfig, 86400);

        $filteredData = $renderData;
        if (!$showall && preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $episodeRange, $m) === 1) {
            $min          = (int) $m[1];
            $max          = (int) $m[2];
            $filteredData = array_values(array_filter($renderData, static function ($item) use ($min, $max) {
                $episode = isset($item['episode']) ? (int) $item['episode'] : -1;
                return $episode >= $min && $episode <= $max;
            }));
        }

        $total      = count($filteredData);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($maxPages > 0) {
            $totalPages = min($totalPages, $maxPages);
        }

        // Each grid gets its own page param so multiple grids on one page
        // never paginate together. The page is read from the URL so crawlers
        // (and no-JS visitors) get the correct server-rendered slice.
        $pageParam = 'maw_page_' . $gridId;
        $page      = isset($_GET[$pageParam]) ? max(1, (int) $_GET[$pageParam]) : 1;
        $page      = min($page, $totalPages);
        $baseUrl   = (string) get_permalink();

        $offset   = ($page - 1) * $perPage;
        $pageData = array_slice($filteredData, $offset, $perPage);

        $cards = [];
        foreach ($pageData as $row) {
            $cards[] = $this->renderMediaItem($row, $gridSettings, false, $playlistName, $mediaType);
        }

        $emptyHtml = $noresults !== ''
            ? '<p class="maw-grid-noresults">' . esc_html($noresults) . '</p>'
            : '<h3 class="media-api-widget-err-msg">No ' . esc_html($mediaType) . ' items found in playlist based upon search parameters provided.</h3>';

        $gridLayoutHtml = $this->renderCollection($cards, $layout, $noStyling, $emptyHtml);

        return
            '<div class="maw-grid-search-wrapper"' .
                ' data-maw-grid-id="' . esc_attr($gridId) . '"' .
                ' data-maw-playlist="' . esc_attr($playlistName) . '"' .
                ' data-maw-mediatype="' . esc_attr($mediaType) . '"' .
                ' data-maw-perpage="' . esc_attr((string) $perPage) . '"' .
                ' data-maw-grid-key="' . esc_attr($gridKey) . '"' .
                ' data-maw-page-param="' . esc_attr($pageParam) . '"' .
            '>' .
                '<div class="maw-grid-items">' . $gridLayoutHtml . '</div>' .
                '<div class="maw-grid-pagination">' . $this->renderPaginationHtml($page, $totalPages, $baseUrl, $pageParam, $maxDisplay) . '</div>' .
            '</div>';
    }

    /**
     * Renders prev/page-number/next pagination HTML.
     *
     * Returns an empty string when $totalPages <= 1.
     *
     * Two display modes:
     * - Default ($maxDisplay <= 0): lists every page number (1..$totalPages)
     *   with no ellipsis truncation.
     * - Windowed ($maxDisplay > 0): shows at most $maxDisplay page numbers
     *   centred on the current page, with no fixed first/last anchors. The
     *   ellipsis on each side (shown only when pages exist beyond the window) is
     *   a navigable control that jumps one "set" — i.e. $maxDisplay pages — in
     *   that direction, so every page stays reachable without flooding the bar.
     *
     * When $baseUrl and $pageParam are supplied, navigable controls are
     * rendered as crawlable <a href> links (page 1 drops the param so it maps
     * to the clean base URL); the front-end JS intercepts clicks for AJAX as a
     * progressive enhancement. Without them it falls back to <button> markup.
     * The current page and disabled prev/next render as non-navigable <span>s.
     * Class names are identical across all variants.
     *
     * @param int    $currentPage Current 1-based page number.
     * @param int    $totalPages  Total number of pages.
     * @param string $baseUrl     Page URL used to build link hrefs.
     * @param string $pageParam   Query-arg name holding the page number.
     * @param int    $maxDisplay  Max numbered buttons to show (0 = default mode).
     * @return string HTML string of pagination controls.
     */
    private function renderPaginationHtml(int $currentPage, int $totalPages, string $baseUrl = '', string $pageParam = '', int $maxDisplay = 0): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $useLinks = $baseUrl !== '' && $pageParam !== '';

        $urlFor = static function (int $page) use ($baseUrl, $pageParam): string {
            return $page <= 1
                ? remove_query_arg($pageParam, $baseUrl)
                : add_query_arg($pageParam, $page, $baseUrl);
        };

        // Navigable control: an <a> when links are enabled (crawlable + JS
        // enhanced), otherwise a <button> for the legacy AJAX-only path.
        $control = static function (int $page, string $extraClass, string $label, string $rel = '') use ($useLinks, $urlFor): string {
            $class = 'maw-page-btn ' . $extraClass;
            if ($useLinks) {
                $relAttr = $rel !== '' ? ' rel="' . esc_attr($rel) . '"' : '';
                return '<a class="' . esc_attr($class) . '" href="' . esc_url($urlFor($page)) . '" data-page="' . esc_attr((string) $page) . '"' . $relAttr . '>' . esc_html($label) . '</a>';
            }
            return '<button class="' . esc_attr($class) . '" data-page="' . esc_attr((string) $page) . '">' . esc_html($label) . '</button>';
        };

        // Non-navigable control: the current page or a disabled prev/next.
        $staticControl = static function (string $extraClass, string $label, bool $isCurrent) use ($useLinks): string {
            $class = 'maw-page-btn ' . $extraClass;
            if ($useLinks) {
                $aria = $isCurrent ? ' aria-current="page"' : ' aria-disabled="true"';
                return '<span class="' . esc_attr($class) . '"' . $aria . '>' . esc_html($label) . '</span>';
            }
            return '<button class="' . esc_attr($class) . '" disabled>' . esc_html($label) . '</button>';
        };

        $html = $currentPage <= 1
            ? $staticControl('maw-page-prev', 'Prev', false)
            : $control($currentPage - 1, 'maw-page-prev', 'Prev', 'prev');

        // Windowed mode: a fixed-width sliding window of $maxDisplay numbers with
        // clickable "set jump" ellipses, and no first/last anchors. Falls through
        // to the default centred-with-anchors behavior when $maxDisplay <= 0 or
        // every page already fits inside the window.
        if ($maxDisplay > 0 && $totalPages > $maxDisplay) {
            // Centre the window on the current page, then clamp so it always
            // shows exactly $maxDisplay numbers even at the first/last pages.
            $start = $currentPage - intdiv($maxDisplay - 1, 2);
            if ($start < 1) {
                $start = 1;
            }
            $end = $start + $maxDisplay - 1;
            if ($end > $totalPages) {
                $end   = $totalPages;
                $start = $totalPages - $maxDisplay + 1;
            }

            // Leading ellipsis jumps back one full set ($maxDisplay pages).
            if ($start > 1) {
                $html .= $control(max(1, $currentPage - $maxDisplay), 'maw-page-ellipsis maw-page-ellipsis-prev', '…');
            }

            for ($i = $start; $i <= $end; $i++) {
                $html .= $i === $currentPage
                    ? $staticControl('maw-page-num active', (string) $i, true)
                    : $control($i, 'maw-page-num', (string) $i);
            }

            // Trailing ellipsis jumps forward one full set ($maxDisplay pages).
            if ($end < $totalPages) {
                $html .= $control(min($totalPages, $currentPage + $maxDisplay), 'maw-page-ellipsis maw-page-ellipsis-next', '…');
            }

            $html .= $currentPage >= $totalPages
                ? $staticControl('maw-page-next', 'Next', false)
                : $control($currentPage + 1, 'maw-page-next', 'Next', 'next');

            return $html;
        }

        // Default mode: list every page number with no ellipsis truncation.
        // Set multiplegridmaxpagedisplay to switch to the compact windowed bar.
        for ($i = 1; $i <= $totalPages; $i++) {
            $html .= $i === $currentPage
                ? $staticControl('maw-page-num active', (string) $i, true)
                : $control($i, 'maw-page-num', (string) $i);
        }

        $html .= $currentPage >= $totalPages
            ? $staticControl('maw-page-next', 'Next', false)
            : $control($currentPage + 1, 'maw-page-next', 'Next', 'next');

        return $html;
    }
}
