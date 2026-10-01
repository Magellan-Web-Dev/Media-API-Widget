<?php
namespace MediaApiWidget\Integrations\Elementor;

use MediaApiWidget\Support\MediaStore;

if (!defined('ABSPATH')) { exit; }

/**
 * Editor-request detection and the editor's no-remote-request guard.
 *
 * The YouTube renderer only ever reads the transient and the backup file, so
 * previews of YouTube output can never reach the API. The podcast renderer,
 * however, performs a live RSS warm-up when its transient is missing — the
 * documented shortcode behavior on the front end. A failed warm-up stores
 * nothing, so in the editor that path would repeat on every control change.
 * The widgets therefore check, in editor requests only, whether rendering would
 * take that path and show a notice instead. Published pages are unaffected and
 * behave exactly like the shortcode.
 */
final class EditorContext
{
    /**
     * Returns whether the current request is an Elementor editor request.
     *
     * Covers the editor document load (initial HTML cache), the preview iframe,
     * and every `elementor_ajax` request (widget re-renders, atomic renders,
     * and the plain-content pass on save).
     *
     * @return bool True for editor-originated requests.
     */
    public static function isEditorRequest(): bool
    {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : '';
            if ($action === 'elementor_ajax') {
                return true;
            }
        }

        if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance)) {
            return false;
        }

        try {
            $elementor = \Elementor\Plugin::$instance;

            if (isset($elementor->editor) && $elementor->editor->is_edit_mode()) {
                return true;
            }

            if (isset($elementor->preview) && $elementor->preview->is_preview_mode()) {
                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * Returns whether rendering this podcast would trigger a live feed request.
     *
     * Mirrors the renderer's cache read: the warm-up runs only when the
     * transient is absent, the configured platform is not `embed`, and a feed
     * source is configured.
     *
     * @param string $playlist Sanitized playlist name.
     * @return bool True when the renderer would fetch remotely.
     */
    public static function wouldFetchPodcast(string $playlist): bool
    {
        if ($playlist === '' || MediaSources::podcastPlatform($playlist) === 'embed') {
            return false;
        }

        if (!MediaSources::hasMediaData($playlist, 'podcast')) {
            return false;
        }

        $cached = get_transient(MediaStore::transientName('podcast', $playlist));

        return $cached === false || $cached === null;
    }

    /**
     * Returns an editor-only notice block.
     *
     * @param string $message Plain-text message.
     * @return string Notice HTML.
     */
    public static function notice(string $message): string
    {
        return '<div class="maw-elementor-notice" style="padding:12px 16px;margin:0 0 8px;border:1px dashed #9da5ae;border-radius:4px;background:#f6f7f8;color:#3f444b;font:13px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;">'
            . esc_html($message)
            . '</div>';
    }
}
