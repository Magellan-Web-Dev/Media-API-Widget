<?php

namespace MediaApiWidget\Support;

if (!defined('ABSPATH')) { exit; }

/**
 * Renders a media-library SVG as inline, sanitized SVG markup.
 *
 * Inline SVG — rather than an `<img>` — is what lets an uploaded icon take
 * the surrounding text color, the way Elementor's Loop Carousel arrows and its
 * atomic SVG element do. Inlining a file means its markup becomes part of the
 * page, so the file is never echoed as uploaded: it is parsed and rebuilt from
 * an allowlist of drawing elements and presentation attributes. Everything
 * else is dropped — scripts, event handlers, links and `href`s, `<style>`
 * (which would apply to the whole page), `<foreignObject>`, external `url()`
 * references, comments, processing instructions and DTDs. A document that
 * declares entities or fails to parse is rejected outright.
 *
 * Only SVG attachments in the media library are used: the reference is an
 * attachment ID or that attachment's URL. Remote files are never fetched.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class InlineSvg
{
    /** Largest file inlined, in bytes. Arrow icons are a few KB. */
    public const MAX_BYTES = 262144;

    /** Most elements kept from one file. */
    private const MAX_ELEMENTS = 2000;

    private const SVG_NS = 'http://www.w3.org/2000/svg';

    /** Elements kept, in their canonical case. */
    private const ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect',
        'defs', 'title', 'desc', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask',
        'text', 'tspan',
    ];

    /** Elements whose text content is kept. */
    private const TEXT_ELEMENTS = ['title', 'desc', 'text', 'tspan'];

    /** Attributes kept, in their canonical case. */
    private const ATTRIBUTES = [
        'id', 'viewBox', 'preserveAspectRatio', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
        'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr', 'd', 'points', 'pathLength', 'transform',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity',
        'color', 'clip-path', 'clip-rule', 'mask', 'display', 'visibility', 'vector-effect', 'shape-rendering',
        'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
        'clipPathUnits', 'maskUnits', 'maskContentUnits',
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'dominant-baseline',
        'dx', 'dy', 'letter-spacing',
    ];

    /** @var array<string,string> Per-request cache: "id|file|mtime|size|class" => markup. */
    private static array $cache = [];

    /**
     * Resolves an attachment ID or attachment URL to an SVG attachment ID.
     *
     * @param string $reference Attachment ID, or the URL of a media-library file.
     * @return int Attachment ID, or 0 when the reference is not an SVG in the media library.
     */
    public static function attachmentId(string $reference): int
    {
        $reference = trim($reference);
        if ($reference === '') {
            return 0;
        }

        if (preg_match('/^\d+$/', $reference) === 1) {
            $id = (int) $reference;
        } elseif (preg_match('#^https?://#i', $reference) === 1) {
            $id = (int) attachment_url_to_postid($reference);
        } else {
            return 0;
        }

        return $id > 0 && get_post_mime_type($id) === 'image/svg+xml' ? $id : 0;
    }

    /**
     * Returns an SVG attachment as sanitized inline markup.
     *
     * The root `<svg>` gets the given class, `aria-hidden="true"` and
     * `focusable="false"` (the icon is decorative), a `viewBox` derived from
     * its width and height when it has none (so it scales), and
     * `fill="currentColor"` when it sets no fill of its own (so it takes the
     * text color; an SVG that sets its own colors keeps them).
     *
     * @param int    $attachmentId SVG attachment ID.
     * @param string $class        Class for the root element.
     * @return string Markup, or '' when the file is missing, too large or unusable.
     */
    public static function forAttachment(int $attachmentId, string $class = ''): string
    {
        if ($attachmentId <= 0 || get_post_mime_type($attachmentId) !== 'image/svg+xml') {
            return '';
        }

        $file = (string) get_attached_file($attachmentId);
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            return '';
        }

        $size = (int) filesize($file);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return '';
        }

        $key = $attachmentId . '|' . $file . '|' . (int) filemtime($file) . '|' . $size . '|' . $class;
        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = self::sanitize((string) file_get_contents($file), $class);
        }

        return self::$cache[$key];
    }

    /**
     * Sanitizes SVG source into inline markup (see {@see self::forAttachment()}).
     *
     * @param string $source SVG document.
     * @param string $class  Class for the root element.
     * @return string Markup, or '' when the source is not a usable SVG.
     */
    public static function sanitize(string $source, string $class = ''): string
    {
        if ($source === '' || strlen($source) > self::MAX_BYTES || !class_exists(\DOMDocument::class)) {
            return '';
        }

        // Entities are how XML expands or pulls in content; an icon never
        // needs them. Without a DTD, any entity reference fails to parse.
        if (stripos($source, '<!ENTITY') !== false) {
            return '';
        }
        $source = (string) preg_replace('/<!DOCTYPE[^>\[]*(?:\[[^\]]*\])?\s*>/i', '', $source);

        $doc      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadXML($source, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $doc->documentElement;
        if (!$loaded || !$root instanceof \DOMElement || strtolower($root->localName) !== 'svg' || !self::isSvgNamespace($root)) {
            return '';
        }

        $budget = self::MAX_ELEMENTS;
        $attrs  = self::attributes($root);

        if (!isset($attrs['viewBox']) && isset($attrs['width'], $attrs['height'])
            && preg_match('/^\d*\.?\d+(?:px)?$/', $attrs['width']) === 1 && preg_match('/^\d*\.?\d+(?:px)?$/', $attrs['height']) === 1) {
            $attrs['viewBox'] = '0 0 ' . (float) $attrs['width'] . ' ' . (float) $attrs['height'];
        }
        if (!isset($attrs['fill'])) {
            $attrs['fill'] = 'currentColor';
        }

        $head = ['aria-hidden' => 'true', 'focusable' => 'false'];
        if ($class !== '') {
            $head = ['class' => $class] + $head;
        }

        return '<svg' . self::attributeString($head + $attrs) . '>' . self::children($root, $budget) . '</svg>';
    }

    /**
     * Serializes an element's allowed children.
     *
     * @param \DOMElement $element Parent element.
     * @param int         $budget  Remaining element allowance, decremented.
     * @return string Markup.
     */
    private static function children(\DOMElement $element, int &$budget): string
    {
        $out      = '';
        $keepText = in_array(self::canonical($element->localName, self::ELEMENTS), self::TEXT_ELEMENTS, true);

        foreach ($element->childNodes as $node) {
            if ($node instanceof \DOMText && !$node instanceof \DOMCdataSection) {
                if ($keepText) {
                    $out .= htmlspecialchars($node->data, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                continue;
            }

            if (!$node instanceof \DOMElement || !self::isSvgNamespace($node)) {
                continue; // Comments, PIs, CDATA, entity references, foreign namespaces.
            }

            $name = self::canonical($node->localName, self::ELEMENTS);
            if ($name === null || $name === 'svg' || --$budget < 0) {
                continue;
            }

            $out .= '<' . $name . self::attributeString(self::attributes($node)) . '>' . self::children($node, $budget) . '</' . $name . '>';
        }

        return $out;
    }

    /**
     * Returns an element's allowed attributes with safe values.
     *
     * @param \DOMElement $element Element.
     * @return array<string,string> Canonical name => value.
     */
    private static function attributes(\DOMElement $element): array
    {
        $out = [];

        foreach ($element->attributes as $attribute) {
            // Namespaced attributes (xlink:href, xml:space, …) are never kept.
            if ($attribute->namespaceURI !== null && $attribute->namespaceURI !== '') {
                continue;
            }

            $name = self::canonical($attribute->localName, self::ATTRIBUTES);
            if ($name === null) {
                continue;
            }

            $value = trim((string) $attribute->value);
            if ($value === '' || strlen($value) > 10000 || !self::isSafeValue($value)) {
                continue;
            }

            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * Whether an attribute value is free of external references and script.
     *
     * The only `url()` allowed is a same-document fragment, as used by
     * gradients, clip paths and masks: `url(#id)`.
     *
     * @param string $value Attribute value.
     * @return bool True when safe.
     */
    private static function isSafeValue(string $value): bool
    {
        if (preg_match('/[<>\\\\]|javascript:|expression\s*\(|@import/i', $value) === 1) {
            return false;
        }

        $withoutFragments = (string) preg_replace('/url\(\s*#[a-z0-9_.:-]+\s*\)/i', '', $value);

        return stripos($withoutFragments, 'url(') === false;
    }

    /**
     * Builds an attribute string, escaping each value.
     *
     * @param array<string,string> $attributes Name => value.
     * @return string Attribute string with a leading space per attribute.
     */
    private static function attributeString(array $attributes): string
    {
        $out = '';
        foreach ($attributes as $name => $value) {
            $out .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"';
        }
        return $out;
    }

    /**
     * Finds a name in an allowlist, ignoring case.
     *
     * @param string            $name Name as written.
     * @param array<int,string> $list Canonical names.
     * @return string|null Canonical name, or null when not allowed.
     */
    private static function canonical(string $name, array $list): ?string
    {
        foreach ($list as $allowed) {
            if (strcasecmp($allowed, $name) === 0) {
                return $allowed;
            }
        }
        return null;
    }

    /**
     * Whether a node is in the SVG namespace (or in none, as hand-written
     * files without an xmlns are).
     *
     * @param \DOMElement $element Element.
     * @return bool
     */
    private static function isSvgNamespace(\DOMElement $element): bool
    {
        return $element->namespaceURI === null || $element->namespaceURI === '' || $element->namespaceURI === self::SVG_NS;
    }
}
