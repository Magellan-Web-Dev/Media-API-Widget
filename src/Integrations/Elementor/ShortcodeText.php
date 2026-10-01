<?php
namespace MediaApiWidget\Integrations\Elementor;

if (!defined('ABSPATH')) { exit; }

/**
 * Serializes a shortcode call back into shortcode text.
 *
 * Used for two things only: the informational `$m` match array handed to the
 * `pre_do_shortcode_tag` / `do_shortcode_tag` filters, and the widgets' plain
 * content — the text Elementor writes to `post_content` on save. Following the
 * precedent of Elementor's own Shortcode widget, the plain content is the
 * shortcode itself, so if Elementor is ever deactivated the page still renders
 * through the plugin's shortcodes instead of showing a stale HTML snapshot.
 *
 * Widget-only behavior (an isolated search id, podcast player overrides) has
 * no shortcode equivalent and is not represented. A value that cannot be
 * written inside a shortcode attribute — one containing `[` or `]`, or both
 * quote characters — is left out rather than corrupting the text.
 */
final class ShortcodeText
{
    /**
     * Builds `[tag name="value" ...]` from an attribute array.
     *
     * @param string               $tag  Shortcode tag.
     * @param array<string,string> $atts Attribute array.
     * @return string Shortcode text.
     */
    public static function build(string $tag, array $atts): string
    {
        $parts = [];

        foreach ($atts as $name => $value) {
            $name  = (string) $name;
            $value = (string) $value;

            if (preg_match('/^[a-z0-9_-]+$/i', $name) !== 1 || strpbrk($value, '[]') !== false) {
                continue;
            }

            if (!str_contains($value, '"')) {
                $parts[] = $name . '="' . $value . '"';
            } elseif (!str_contains($value, "'")) {
                $parts[] = $name . "='" . $value . "'";
            }
        }

        return '[' . $tag . ($parts !== [] ? ' ' . implode(' ', $parts) : '') . ']';
    }

    /**
     * Returns the plain-content shortcode text for a set of widget settings.
     *
     * @param array<string,mixed> $settings   Flat widget settings.
     * @param string              $instanceId Elementor element ID.
     * @return string Shortcode text, or '' when the settings render nothing.
     */
    public static function forSettings(array $settings, string $instanceId): string
    {
        $plan = SettingsAdapter::plan($settings, $instanceId);

        if ($plan['error'] !== '') {
            return '';
        }

        $text = [];
        foreach ($plan['calls'] as $call) {
            $text[] = self::build($call['tag'], $call['atts']);
        }

        return implode("\n", $text);
    }
}
