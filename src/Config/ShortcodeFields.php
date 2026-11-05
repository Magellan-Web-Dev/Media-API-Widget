<?php
namespace MediaApiWidget\Config;

if (!defined('ABSPATH')) { exit; }

/**
 * Reads the admin "Shortcode Fields" and resolves references to them.
 *
 * Any shortcode attribute value may name a stored field instead of a literal:
 * - `{{field_name}}`
 * - `[media-api-widget field="field_name"]`
 *
 * Shared by the shortcode renderers and by {@see \MediaApiWidget\PodcastPlayer\PlayerStyle},
 * so every path resolves a reference the same way.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class ShortcodeFields
{
    /**
     * Returns all stored shortcode fields as a flat field => value map.
     *
     * Empty field names are skipped.
     *
     * @return array<string,string> Map of field name to value.
     */
    public static function values(): array
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
     * Resolves field references in every non-empty string value of `$atts`.
     *
     * Non-string values are passed through unchanged.
     *
     * @param array<string,mixed>  $atts   Shortcode attributes.
     * @param array<string,string> $values Field map from {@see self::values()}.
     * @return array<string,mixed> Attributes with references resolved.
     */
    public static function resolveAll(array $atts, array $values): array
    {
        foreach ($atts as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $atts[$key] = self::resolve($value, $values);
        }

        return $atts;
    }

    /**
     * Resolves a single field reference.
     *
     * Returns `$value` unchanged when it is not a reference or the referenced
     * field does not exist.
     *
     * @param string               $value  Raw attribute value.
     * @param array<string,string> $values Field map from {@see self::values()}.
     * @return string Resolved value, or the original if no reference matched.
     */
    public static function resolve(string $value, array $values): string
    {
        $field = self::referencedField($value);

        return $field !== null && isset($values[$field]) ? $values[$field] : $value;
    }

    /**
     * Returns true when the value is a field reference (resolvable or not).
     *
     * @param string $value Value to inspect.
     * @return bool True for `{{field}}` or `[media-api-widget field="field"]`.
     */
    public static function isReference(string $value): bool
    {
        return self::referencedField($value) !== null;
    }

    /**
     * Returns the field a reference names, or null when `$value` is not a reference.
     *
     * @param string $value Value to inspect.
     * @return string|null Sanitized field name, or null.
     */
    private static function referencedField(string $value): ?string
    {
        $trimmed = trim($value);

        if (preg_match('/^\{\{\s*([a-z0-9_-]+)\s*\}\}$/i', $trimmed, $matches) === 1
            || preg_match('/^\[media-api-widget\s+field=(["\'])([a-z0-9_-]+)\1\s*\]$/i', $trimmed, $matches) === 1) {
            $field = sanitize_key((string) end($matches));

            return $field !== '' ? $field : null;
        }

        return null;
    }
}
