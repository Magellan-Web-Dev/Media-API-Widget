<?php
namespace MediaApiWidget\Integrations\Elementor;

if (!defined('ABSPATH')) { exit; }

/**
 * The one render entry point both Elementor widgets call.
 *
 * Builds the plan with {@see SettingsAdapter}, runs it through
 * {@see ShortcodeDispatcher}, and — in editor requests only — explains empty
 * or unrenderable states with a notice instead of an invisible widget. On a
 * published page an unrenderable widget outputs nothing, exactly as the
 * equivalent shortcode would.
 */
final class WidgetRenderer
{
    /**
     * Renders one widget instance.
     *
     * @param array<string,mixed> $settings   Flat widget settings.
     * @param string              $instanceId Elementor element ID.
     * @param bool|null           $editor     Editor request override; null detects it.
     * @return string HTML.
     */
    public static function render(array $settings, string $instanceId, ?bool $editor = null): string
    {
        $editor ??= EditorContext::isEditorRequest();
        $plan     = SettingsAdapter::plan($settings, $instanceId);

        if ($plan['error'] !== '') {
            return $editor ? EditorContext::notice(self::message($plan['error'])) : '';
        }

        if ($editor && self::readsPodcastData($plan) && EditorContext::wouldFetchPodcast($plan['playlist'])) {
            return EditorContext::notice(sprintf(
                /* translators: %s: podcast playlist name. */
                __('Podcast data for "%s" is not cached yet. The preview appears once the feed has been cached, for example after the page is viewed on the front end. The editor does not request the feed itself.', 'media-api-widget'),
                $plan['playlist']
            ));
        }

        $html = ShortcodeDispatcher::render($plan);

        if (!$editor) {
            return $html;
        }

        $notices = '';
        foreach ($plan['notices'] as $code) {
            $notices .= EditorContext::notice(self::message($code));
        }

        if (trim($html) === '') {
            $notices .= EditorContext::notice(__('Nothing to display for the current settings.', 'media-api-widget'));
        }

        return $notices . $html;
    }

    /**
     * Returns whether a plan's renderer reads podcast media data.
     *
     * @param array<string,mixed> $plan Render plan.
     * @return bool True for podcast render and player modes.
     */
    private static function readsPodcastData(array $plan): bool
    {
        return $plan['platform'] === 'podcast'
            && !in_array($plan['mode'], [WidgetSchema::MODE_SEARCH_BAR, WidgetSchema::MODE_FIELD], true);
    }

    /**
     * Returns the editor message for a plan error or notice code.
     *
     * @param string $code Code from {@see SettingsAdapter}.
     * @return string Message.
     */
    private static function message(string $code): string
    {
        switch ($code) {
            case SettingsAdapter::ERROR_NO_FIELD:
                return __('Select a stored field to display.', 'media-api-widget');
            case SettingsAdapter::ERROR_NO_SOURCE:
                return __('Select a configured playlist or podcast.', 'media-api-widget');
            case SettingsAdapter::ERROR_PLAYER_PLATFORM:
                return __('The embedded podcast player needs a podcast source. Set Media type to Podcast.', 'media-api-widget');
            case SettingsAdapter::ERROR_NO_CONNECTION:
                return __('Enter the Connection ID of the searchable grid this bar should search, or choose the shared playlist link.', 'media-api-widget');
            case SettingsAdapter::NOTICE_GRID_NO_CONNECTION:
                return __('No Connection ID is set, so this grid links only to its own search bar.', 'media-api-widget');
            default:
                return $code;
        }
    }
}
