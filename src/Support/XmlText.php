<?php

namespace MediaApiWidget\Support;

if (!defined('ABSPATH')) { exit; }

/**
 * Flattens XML-derived values into plain text.
 *
 * Podcast RSS reaches the plugin as a SimpleXMLElement and is stored as the
 * array tree that `json_decode(json_encode($xml), true)` produces. That tree is
 * awkward to read directly: a bare element becomes a string, an element with
 * attributes becomes `['@attributes' => [...]]`, an empty element becomes `[]`,
 * and a nested element becomes another array. Every consumer that wants a
 * feed field as text has to collapse all four shapes the same way, and any two
 * implementations that disagree produce subtly different output for the same
 * feed — so the collapse lives here once.
 *
 * The `@`-prefix skip rule is the load-bearing detail: without it, a GUID
 * carrying `isPermaLink="false"` would flatten to the string `"false"` and
 * become an episode's identity. Attribute metadata is never text content.
 *
 * Attributes that genuinely *are* the value — an enclosure's `url` above all —
 * are therefore unreachable through {@see self::flatten()} by design, and have
 * their own explicit accessor in {@see self::enclosureUrl()}.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class XmlText
{
    /**
     * How deep {@see self::flatten()} will recurse before giving up.
     *
     * A malformed or hostile feed can nest arbitrarily; a bound keeps a deep
     * structure from exhausting the stack during a front-end page render.
     *
     * @var int
     */
    private const MAX_DEPTH = 16;

    /**
     * Flattens an XML-derived value into a whitespace-normalized string.
     *
     * Accepts strings, numerics, booleans, arrays, SimpleXMLElement instances,
     * and arbitrary objects. Anything else — null, resources — yields an empty
     * string rather than a notice.
     *
     * @param mixed $value The value to extract text from.
     * @param int   $depth Current recursion depth; callers pass nothing.
     * @return string Flattened text, or an empty string when there is none.
     */
    public static function flatten($value, int $depth = 0): string
    {
        if ($depth >= self::MAX_DEPTH) {
            return '';
        }

        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // A pre-store filter may hand back a SimpleXMLElement rather than an
        // array: MediaStore::normalizePodcastData() accepts either, and
        // MediaStore::validate() only inspects the container. Round-trip it
        // into the array shape the rest of this method understands.
        if ($value instanceof \SimpleXMLElement) {
            $encoded = json_encode($value);
            if (!is_string($encoded)) {
                return '';
            }

            $decoded = json_decode($encoded, true);

            return is_array($decoded) || is_string($decoded)
                ? self::flatten($decoded, $depth + 1)
                : '';
        }

        if (is_object($value)) {
            return self::flatten(get_object_vars($value), $depth + 1);
        }

        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $key => $child) {
            // Attribute metadata is never text content. See the class docblock:
            // without this a GUID's isPermaLink attribute would become the
            // episode's identity.
            if (is_string($key) && strpos($key, '@') === 0) {
                continue;
            }

            $text = self::flatten($child, $depth + 1);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    /**
     * Resolves the audio URL from an RSS `<enclosure>` node.
     *
     * Deliberately does not use {@see self::flatten()}. An enclosure carries no
     * text content at all — its value lives entirely in attributes, which
     * flatten() skips — so flattening one correctly but uselessly returns an
     * empty string. This reads the `url` attribute directly.
     *
     * Handles a single enclosure and a list of them (some feeds ship siblings
     * for multiple formats), returning the first usable http(s) URL.
     *
     * @param mixed $enclosure The `enclosure` value from a normalized item.
     * @return string An http(s) URL, or an empty string when there is none.
     */
    public static function enclosureUrl($enclosure): string
    {
        if ($enclosure instanceof \SimpleXMLElement) {
            $encoded = json_encode($enclosure);
            $enclosure = is_string($encoded) ? json_decode($encoded, true) : null;
        }

        if (is_string($enclosure)) {
            return self::httpUrl($enclosure);
        }

        if (!is_array($enclosure) || $enclosure === []) {
            return '';
        }

        // A single enclosure is an associative array; multiple enclosures are a
        // list of them. Normalize to a list so one loop covers both.
        $candidates = array_is_list($enclosure) ? $enclosure : [$enclosure];

        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $url = self::httpUrl($candidate);
                if ($url !== '') {
                    return $url;
                }

                continue;
            }

            if (!is_array($candidate)) {
                continue;
            }

            $attributes = $candidate['@attributes'] ?? null;
            $raw = is_array($attributes) ? ($attributes['url'] ?? '') : ($candidate['url'] ?? '');

            if (!is_string($raw)) {
                continue;
            }

            $url = self::httpUrl($raw);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * libxml options every RSS parse uses.
     *
     * LIBXML_NOCDATA merges CDATA sections into text. Without it SimpleXML keeps
     * CDATA as a separate node type that json_encode() silently drops, so an
     * episode whose title, description, or GUID is wrapped in CDATA — which is
     * how most hosting platforms publish them — would be stored blank.
     *
     * @var int
     */
    public const RSS_PARSE_OPTIONS = LIBXML_NOCDATA;

    /**
     * Item fields whose attributes are dropped before the feed is converted.
     *
     * SimpleXML's JSON conversion loses the text of an element that also has
     * attributes in some shapes (a `<guid isPermaLink="false">` is the common
     * one), leaving only `{"@attributes": …}`. These fields are pure text for
     * the plugin, so their attributes carry nothing worth keeping.
     *
     * @var array<int,string>
     */
    private const TEXT_ITEM_FIELDS = ['guid', 'title', 'description', 'link', 'pubDate'];

    /**
     * Namespaced elements used when an episode's <description> is empty, in
     * order of preference: itunes:summary, then content:encoded.
     *
     * @var array<int,array{0:string,1:string}>
     */
    private const DESCRIPTION_FALLBACKS = [
        ['http://www.itunes.com/dtds/podcast-1.0.dtd', 'summary'],
        ['http://purl.org/rss/1.0/modules/content/', 'encoded'],
    ];

    /**
     * Makes an RSS document's per-episode text fields survive JSON conversion.
     *
     * Strips attributes from each item's text fields in place, so the stored
     * feed holds `"guid": "abc"` rather than an attribute array with the
     * identifier missing. A stable GUID is what keeps an episode's stored post
     * (and its enrichment) attached to the same episode across refreshes.
     *
     * @param \SimpleXMLElement $xml Parsed RSS document.
     * @return void
     */
    public static function normalizeFeedItems(\SimpleXMLElement $xml): void
    {
        if (!isset($xml->channel) || !isset($xml->channel->item)) {
            return;
        }

        foreach ($xml->channel->item as $item) {
            // Many feeds put the episode text only in iTunes or content
            // namespaces, which the stored array shape cannot carry. Promote it
            // into an empty <description> so the description survives.
            if (trim((string) ($item->description ?? '')) === '') {
                foreach (self::DESCRIPTION_FALLBACKS as [$namespace, $element]) {
                    $text = trim((string) $item->children($namespace)->{$element});
                    if ($text !== '') {
                        $item->description = $text;
                        break;
                    }
                }
            }

            foreach (self::TEXT_ITEM_FIELDS as $field) {
                if (!isset($item->{$field})) {
                    continue;
                }

                $node = $item->{$field};
                if (count($node->attributes()) === 0) {
                    continue;
                }

                if (function_exists('dom_import_simplexml')) {
                    $dom = dom_import_simplexml($node);
                    while ($dom->attributes !== null && $dom->attributes->length > 0) {
                        $dom->removeAttributeNode($dom->attributes->item(0));
                    }
                    continue;
                }

                // Without ext-dom: replace the element with a text-only one.
                // Property assignment escapes the text itself.
                $text = trim((string) $node);
                unset($item->{$field});
                $item->{$field} = $text;
            }
        }
    }

    /**
     * Returns a trimmed http(s) URL, or an empty string when unusable.
     *
     * @param string $raw Candidate URL.
     * @return string Sanitized URL, or an empty string.
     */
    private static function httpUrl(string $raw): string
    {
        $url = esc_url_raw(trim($raw));

        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }
}
