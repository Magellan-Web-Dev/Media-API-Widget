<?php
namespace MediaApiWidget\Seo;

use MediaApiWidget\Config\Options;
use MediaApiWidget\Frontend\MediaContent;

if (!defined('ABSPATH')) { exit; }

/**
 * Injects server-rendered Open Graph and Twitter Card meta tags into wp_head.
 *
 * On each front-end page load, scans the current post's content for
 * [media-api-widget-render], [media-api-widget-item], and
 * [media-api-podcast-player] shortcodes, resolves the first usable media item
 * from the transient cache (or backup JSON), and emits a complete set of
 * og:/twitter: meta tags before WordPress closes the <head>.
 *
 * Runs at wp_head priority 30, after MediaBootstrap (priority 20), so the
 * transient written on this request's cache warm-up is already available.
 *
 * All methods are instance methods; the class is instantiated once by
 * {@see \MediaApiWidget\Plugin::register()}.
 */
final class MetaUpdater
{
    /**
     * Registers the wp_head action hook that outputs meta tags.
     *
     * Priority 30 ensures this runs after MediaBootstrap (priority 20) so
     * any transient written during the current request's cache warm-up is
     * already in place when meta tags are resolved.
     *
     * @return void
     */
    public function register(): void
    {
        // Run after MediaBootstrap so cache warm-up on the current request can be used.
        add_action('wp_head', [$this, 'outputMeta'], 30);
    }

    /**
     * Resolves and outputs SEO meta tags for the current page.
     *
     * Iterates the media contexts found in the current post's content. For
     * each context, looks up the admin config, reads cached media data, and
     * builds the meta tag block for the first context that yields at least
     * one resolved item. Outputs nothing and returns on admin requests.
     *
     * @return void
     */
    public function outputMeta(): void
    {
        if (is_admin()) {
            return;
        }

        foreach ($this->resolveCurrentMediaContexts() as $context) {
            $config = $this->findMediaConfig($context['playlist_name'], $context['media_type']);
            if ($config === null) {
                continue;
            }

            if ($context['podcast_platform'] === '') {
                $context['podcast_platform'] = sanitize_key((string) ($config['podcast_platform'] ?? 'custom'));
            }

            $mediaData = $this->readCachedMediaData($context['playlist_name'], $context['media_type']);
            if ($mediaData === null) {
                continue;
            }

            $payload = $this->normalizeMediaPayload($mediaData, $context);
            if (count($payload['items']) === 0) {
                continue;
            }

            $selectedItem = $this->resolveSelectedItem($payload['items'], $context);
            if ($selectedItem === null) {
                continue;
            }

            echo $this->buildMetaTags($selectedItem, $payload, $context);
            return;
        }
    }

    /**
     * Finds all media shortcode contexts in the current page's post content.
     *
     * Uses WordPress's get_shortcode_regex() to locate the three media
     * shortcodes. For each match, extracts playlist_name, media_type,
     * podcast_platform, nameselect, episodenumber, and orderdescending from
     * the shortcode attributes. Skips escaped shortcodes (wrapped in [[]]).
     *
     * @return array<int, array<string,string>> Each entry: shortcode, playlist_name,
     *                                          media_type, podcast_platform, nameselect,
     *                                          episodenumber, orderdescending.
     */
    private function resolveCurrentMediaContexts(): array
    {
        $content = $this->getCurrentPostContent();
        if ($content === '') {
            return [];
        }

        $regex = get_shortcode_regex([
            'media-api-widget-render',
            'media-api-widget-item',
            'media-api-podcast-player',
        ]);

        if (!preg_match_all('/' . $regex . '/', $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $contexts = [];

        foreach ($matches as $match) {
            if (($match[1] ?? '') === '[' && ($match[6] ?? '') === ']') {
                continue;
            }

            $shortcode = (string) ($match[2] ?? '');
            $atts = shortcode_parse_atts((string) ($match[3] ?? ''));
            $atts = is_array($atts) ? $atts : [];

            $playlistName = sanitize_key((string) ($atts['playlist_name'] ?? ($shortcode === 'media-api-podcast-player' ? 'podcast' : '')));
            $mediaType = $shortcode === 'media-api-podcast-player'
                ? 'podcast'
                : sanitize_key((string) ($atts['media_platform'] ?? 'youtube'));

            if ($playlistName === '' || !in_array($mediaType, ['youtube', 'podcast'], true)) {
                continue;
            }

            $contexts[] = [
                'shortcode' => $shortcode,
                'playlist_name' => $playlistName,
                'media_type' => $mediaType,
                'podcast_platform' => sanitize_key((string) ($atts['podcast_platform'] ?? '')),
                'nameselect' => sanitize_text_field((string) ($atts['nameselect'] ?? '')),
                'episodenumber' => sanitize_text_field((string) ($atts['episodenumber'] ?? '')),
                'orderdescending' => sanitize_text_field((string) ($atts['orderdescending'] ?? ($shortcode === 'media-api-podcast-player' ? '1' : ''))),
            ];
        }

        return $contexts;
    }

    /**
     * Returns the raw post_content string for the current queried page.
     *
     * Uses get_queried_object_id() to find the current page, then get_post()
     * to retrieve it. Returns an empty string when no queried object ID is
     * found or the result is not a WP_Post.
     *
     * @return string The raw post content, or '' if unavailable.
     */
    private function getCurrentPostContent(): string
    {
        $postId = get_queried_object_id();
        if ($postId <= 0) {
            return '';
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return '';
        }

        return is_string($post->post_content) ? $post->post_content : '';
    }

    /**
     * Finds the admin-configured media item matching playlist name and type.
     *
     * Searches {@see Options::getMediaItems()} and, for back-compat, any items
     * declared via the legacy MEDIA_CONTENT_DATA constant. Returns the first
     * matching config array, or null if none is found.
     *
     * @param string $playlistName The sanitized playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return array<string,mixed>|null The matching config array, or null.
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
     * Reads cached media data from a WordPress transient or backup JSON file.
     *
     * Checks the `{mediaType}_{playlistName}` transient first. If the transient
     * is expired, falls back to the backup JSON file at
     * `{backup_dir}/{playlistName}_{mediaType}_backup_data.json`. Returns null
     * when no data is available from either source.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return array<string,mixed>|array<int,array<string,mixed>>|null Decoded data, or null.
     */
    private function readCachedMediaData(string $playlistName, string $mediaType)
    {
        $cached = get_transient($mediaType . '_' . $playlistName);
        if ($cached !== false && $cached !== null) {
            if ($mediaType === 'podcast' && is_string($cached)) {
                $decoded = json_decode($cached, true);
                return is_array($decoded) ? $decoded : null;
            }

            return is_array($cached) ? $cached : null;
        }

        if ($mediaType === 'youtube') {
            $path = MediaContent::backupDir() . $playlistName . '_youtube_backup_data.json';
            if (is_readable($path)) {
                $backup = json_decode((string) file_get_contents($path), true);
                if (is_array($backup) && isset($backup['data']) && is_array($backup['data'])) {
                    return $backup['data'];
                }
            }
        }

        if ($mediaType === 'podcast') {
            $path = MediaContent::backupDir() . $playlistName . '_podcast_backup_data.json';
            if (is_readable($path)) {
                $backup = json_decode((string) file_get_contents($path), true);
                if (is_array($backup) && isset($backup['data']) && is_array($backup['data'])) {
                    return $backup['data'];
                }
            }
        }

        return null;
    }

    /**
     * Normalizes raw YouTube or podcast media data into a common payload shape.
     *
     * For YouTube: iterates the flat array of video items, extracting title,
     * description, publishedDate, episode number, and thumbnail URL.
     * For podcast: reads the channel and item elements from the decoded RSS
     * JSON, extracting title, description, pubDate, and episode image (with
     * channel image as fallback).
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $mediaData Raw cached data.
     * @param array<string,string>                               $context   Media context from resolveCurrentMediaContexts().
     * @return array{items: array<int,array<string,string>>, series_title: string, series_description: string, image: string}
     */
    private function normalizeMediaPayload($mediaData, array $context): array
    {
        if ($context['media_type'] === 'youtube') {
            $seriesTitle = ucwords(str_replace(['-', '_'], ' ', $context['playlist_name']));
            $items = [];

            if (is_array($mediaData)) {
                foreach ($mediaData as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $items[] = [
                        'title' => $this->cleanText($item['title'] ?? ''),
                        'description' => $this->cleanText($item['description'] ?? ''),
                        'published_date' => $this->cleanText($item['publishedDate'] ?? ''),
                        'episode' => isset($item['episode']) ? (string) (int) $item['episode'] : '',
                        'image' => esc_url_raw((string) (($item['thumbnail']['url'] ?? ''))),
                    ];
                }
            }

            return [
                'items' => $items,
                'series_title' => $seriesTitle,
                'series_description' => '',
                'image' => isset($items[0]['image']) ? (string) $items[0]['image'] : '',
            ];
        }

        if (!is_array($mediaData) || !isset($mediaData['channel']) || !is_array($mediaData['channel'])) {
            return [
                'items' => [],
                'series_title' => '',
                'series_description' => '',
                'image' => '',
            ];
        }

        $channel = $mediaData['channel'];
        $channelImage = $this->extractPodcastImage($channel);
        $items = [];
        $rawItems = $channel['item'] ?? [];

        if (is_array($rawItems) && isset($rawItems['title'])) {
            $rawItems = [$rawItems];
        }

        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemImage = $this->extractPodcastImage($item);
                $items[] = [
                    'title' => $this->cleanText($item['title'] ?? ''),
                    'description' => $this->cleanText($item['description'] ?? ''),
                    'published_date' => $this->cleanText($item['pubDate'] ?? ''),
                    'episode' => '',
                    'image' => $itemImage !== '' ? $itemImage : $channelImage,
                ];
            }
        }

        return [
            'items' => $items,
            'series_title' => $this->cleanText($channel['title'] ?? ''),
            'series_description' => $this->cleanText($channel['description'] ?? ''),
            'image' => $channelImage,
        ];
    }

    /**
     * Extracts the best available image URL from a podcast channel or item node.
     *
     * Checks the 'image', 'itunes:image', 'itunes_image', and 'itunes-image'
     * keys in the node. For each found value, calls
     * {@see self::collectImageCandidates()} to flatten nested structures, then
     * returns the first non-empty validated URL.
     *
     * @param array<string,mixed> $node Decoded podcast channel or item array.
     * @return string Validated image URL, or '' if none found.
     */
    private function extractPodcastImage(array $node): string
    {
        $sources = [];

        foreach (['image', 'itunes:image', 'itunes_image', 'itunes-image'] as $key) {
            if (isset($node[$key])) {
                $sources[] = $node[$key];
            }
        }

        foreach ($sources as $source) {
            foreach ($this->collectImageCandidates($source) as $candidate) {
                $url = esc_url_raw((string) $candidate);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return '';
    }

    /**
     * Recursively extracts image URL candidate strings from a mixed value.
     *
     * Handles the varied shapes that podcast XML → JSON produces:
     * a plain string, an array with a 'url' or 'href' key, or an array
     * with an '@attributes' sub-array. Returns a flat list of string
     * candidates for the caller to validate.
     *
     * @param mixed $value The value to extract candidates from.
     * @return array<int,string> Flat list of string URL candidates.
     */
    private function collectImageCandidates($value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $candidates = [];

        foreach (['url', 'href'] as $key) {
            if (isset($value[$key])) {
                $candidates = array_merge($candidates, $this->collectImageCandidates($value[$key]));
            }
        }

        if (isset($value['@attributes']) && is_array($value['@attributes'])) {
            foreach (['url', 'href'] as $key) {
                if (isset($value['@attributes'][$key])) {
                    $candidates = array_merge($candidates, $this->collectImageCandidates($value['@attributes'][$key]));
                }
            }
        }

        return $candidates;
    }

    /**
     * Selects the best matching item from the normalized items array.
     *
     * Selection priority (first match wins):
     * 1. YouTube: match by episode number in `episodenumber` context attribute.
     * 2. Match by title substring in `nameselect` context attribute.
     * 3. Use `orderdescending` as a 1-based index into the items array.
     * 4. Fall back to items[0].
     *
     * @param array<int,array<string,string>> $items   Normalized media items.
     * @param array<string,string>            $context Media context from resolveCurrentMediaContexts().
     * @return array<string,string>|null The selected item, or null when $items is empty.
     */
    private function resolveSelectedItem(array $items, array $context): ?array
    {
        if (count($items) === 0) {
            return null;
        }

        if ($context['media_type'] === 'youtube' && $context['episodenumber'] !== '' && is_numeric($context['episodenumber'])) {
            $episodeNumber = (string) (int) $context['episodenumber'];
            foreach ($items as $item) {
                if (($item['episode'] ?? '') === $episodeNumber) {
                    return $item;
                }
            }
        }

        if ($context['nameselect'] !== '') {
            $needle = strtolower($context['nameselect']);
            foreach ($items as $item) {
                if (strpos(strtolower((string) ($item['title'] ?? '')), $needle) !== false) {
                    return $item;
                }
            }
        }

        if ($context['orderdescending'] !== '' && is_numeric($context['orderdescending'])) {
            $index = max(0, (int) $context['orderdescending'] - 1);
            return $items[$index] ?? $items[0];
        }

        return $items[0];
    }

    /**
     * Builds the complete HTML meta tag block for the selected item.
     *
     * Emits the following tags:
     * - name="description"
     * - property="og:type"        (video.other for YouTube, article for podcast)
     * - property="og:title"       (item title | series title | site name)
     * - property="og:description"
     * - property="og:site_name"
     * - property="og:url"         (current page permalink)
     * - name="twitter:card"       (summary_large_image or summary)
     * - name="twitter:title"
     * - name="twitter:description"
     * - property="og:image"       (when an image URL is available)
     * - property="og:image:alt"
     * - name="twitter:image"
     * - name="twitter:image:alt"
     * - property="article:published_time" (when a parseable pubDate is present)
     *
     * Returns an empty string when no non-empty tags can be generated.
     *
     * @param array<string,string>                                                                $selectedItem  Normalized media item.
     * @param array{items: array<int,array<string,string>>, series_title: string, series_description: string, image: string} $payload       Full normalized payload.
     * @param array<string,string>                                                                $context       Media context.
     * @return string HTML meta tag block (including surrounding HTML comments), or ''.
     */
    private function buildMetaTags(array $selectedItem, array $payload, array $context): string
    {
        $siteName = $this->cleanText(get_bloginfo('name'));
        $seriesTitle = $payload['series_title'] !== ''
            ? $payload['series_title']
            : ucwords(str_replace(['-', '_'], ' ', $context['playlist_name']));

        $itemTitle = $selectedItem['title'] !== '' ? $selectedItem['title'] : $seriesTitle;
        $description = $this->buildMetaDescription($selectedItem['description'] ?? '', $payload['series_description'], $seriesTitle, $itemTitle, $context['media_type']);
        $titleParts = array_filter(array_unique([$itemTitle, $seriesTitle, $siteName]));
        $metaTitle = implode(' | ', $titleParts);
        $image = $selectedItem['image'] !== '' ? $selectedItem['image'] : $payload['image'];
        $currentUrl = get_permalink(get_queried_object_id());
        $ogType = $context['media_type'] === 'youtube' ? 'video.other' : 'article';

        $tags = [];
        $tags[] = $this->renderMetaTag('name', 'description', $description);
        $tags[] = $this->renderMetaTag('property', 'og:type', $ogType);
        $tags[] = $this->renderMetaTag('property', 'og:title', $metaTitle);
        $tags[] = $this->renderMetaTag('property', 'og:description', $description);
        $tags[] = $this->renderMetaTag('property', 'og:site_name', $siteName);

        if (is_string($currentUrl) && $currentUrl !== '') {
            $tags[] = $this->renderMetaTag('property', 'og:url', $currentUrl);
        }

        $tags[] = $this->renderMetaTag('name', 'twitter:card', $image !== '' ? 'summary_large_image' : 'summary');
        $tags[] = $this->renderMetaTag('name', 'twitter:title', $metaTitle);
        $tags[] = $this->renderMetaTag('name', 'twitter:description', $description);

        if ($image !== '') {
            $tags[] = $this->renderMetaTag('property', 'og:image', $image);
            $tags[] = $this->renderMetaTag('property', 'og:image:alt', $itemTitle);
            $tags[] = $this->renderMetaTag('name', 'twitter:image', $image);
            $tags[] = $this->renderMetaTag('name', 'twitter:image:alt', $itemTitle);
        }

        $publishedDate = $selectedItem['published_date'] ?? '';
        $timestamp = $publishedDate !== '' ? strtotime($publishedDate) : false;
        if ($timestamp) {
            $tags[] = $this->renderMetaTag('property', 'article:published_time', gmdate('c', $timestamp));
        }

        $tags = array_values(array_filter($tags));
        if (count($tags) === 0) {
            return '';
        }

        return "\n<!-- Media API Widget SEO -->\n" . implode("\n", $tags) . "\n";
    }

    /**
     * Builds a meta description up to 160 characters.
     *
     * Priority: item description → series description → generated fallback
     * ("Watch {title} from {series}." for YouTube, "Listen to …" for podcast).
     *
     * @param string $itemDescription   Description of the selected item.
     * @param string $seriesDescription Description of the podcast/series channel.
     * @param string $seriesTitle       Series/channel title.
     * @param string $itemTitle         Title of the selected item.
     * @param string $mediaType         'youtube' or 'podcast'.
     * @return string Truncated meta description string.
     */
    private function buildMetaDescription(string $itemDescription, string $seriesDescription, string $seriesTitle, string $itemTitle, string $mediaType): string
    {
        if ($itemDescription !== '') {
            return $this->truncateText($itemDescription, 160);
        }

        if ($seriesDescription !== '') {
            return $this->truncateText($seriesDescription, 160);
        }

        $fallback = match($mediaType) {
            'youtube' => 'Watch ' . $itemTitle . ' from ' . $seriesTitle . '.',
            default   => 'Listen to ' . $itemTitle . ' from ' . $seriesTitle . '.',
        };

        return $this->truncateText($fallback, 160);
    }

    /**
     * Truncates a plain-text string to at most $limit characters.
     *
     * Prefers the multibyte functions mb_strlen/mb_substr when available.
     * Appends a single '…' (UTF-8 ellipsis) or '...' respectively when the
     * text is longer than the limit. Leading and trailing whitespace is trimmed
     * before length is evaluated.
     *
     * @param string $text  The text to truncate.
     * @param int    $limit Maximum character count (inclusive of the ellipsis).
     * @return string Truncated text, or '' when the input is empty.
     */
    private function truncateText(string $text, int $limit): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $limit) {
                return $text;
            }

            return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
        }

        if (strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(substr($text, 0, $limit - 1)) . '...';
    }

    /**
     * Strips HTML tags and trims whitespace from a mixed value.
     *
     * Delegates text extraction to {@see self::extractText()} then strips any
     * remaining HTML via wp_strip_all_tags().
     *
     * @param mixed $value The raw value to clean.
     * @return string Clean plain text string.
     */
    private function cleanText($value): string
    {
        return trim(wp_strip_all_tags($this->extractText($value), true));
    }

    /**
     * Flattens XML-derived arrays into plain text while skipping attribute metadata.
     *
     * Handles strings, numerics, booleans, and recursive arrays. Array keys
     * starting with '@' (e.g. '@attributes' from SimpleXML JSON encoding) are
     * skipped so attribute metadata does not bleed into content text.
     *
     * @param mixed $value The value to extract text from.
     * @return string Flattened plain text with normalized whitespace.
     */
    private function extractText($value): string
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

            $text = $this->extractText($child);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    /**
     * Renders a single HTML `<meta>` tag string.
     *
     * Returns an empty string when the content value is empty after trimming,
     * so the caller can filter out no-op tags with array_filter().
     *
     * @param string $attributeName  The attribute name: 'name' or 'property'.
     * @param string $attributeValue The attribute value (e.g. 'og:title').
     * @param string $content        The meta content value.
     * @return string Escaped `<meta>` tag, or '' when content is empty.
     */
    private function renderMetaTag(string $attributeName, string $attributeValue, string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        return '<meta ' . esc_attr($attributeName) . '="' . esc_attr($attributeValue) . '" content="' . esc_attr($content) . '">';
    }
}
