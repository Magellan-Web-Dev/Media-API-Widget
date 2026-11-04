<?php
namespace MediaApiWidget\Integrations\Elementor\DynamicTags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;
use MediaApiWidget\PostIndex\PostFields;

if (!defined('ABSPATH')) { exit; }

/**
 * "Media API Click Action" — makes any linkable element (Button, Image,
 * Heading, Icon, a Container link…) in a loop item behave like the item's own
 * media card.
 *
 * A click always dispatches the `mediaApiWidgetItemClick` event, exactly as a
 * card click does, and then either opens the plugin's lightbox / podcast
 * player for that item (with or without the playlist) or, with "Click event
 * only", nothing else — for scripts that handle the event themselves.
 *
 * The value is a non-navigating `#maw-media-…` link that the front-end script
 * reads; see {@see PostFields::clickUrl()}.
 *
 * Loaded only from {@see \MediaApiWidget\Integrations\Elementor\Integration::registerDynamicTags()}.
 */
final class MediaClickTag extends Data_Tag
{
    public function get_name()
    {
        return 'maw-media-click';
    }

    public function get_title()
    {
        return __('Media API Click Action', 'media-api-widget');
    }

    public function get_group()
    {
        return Groups::GROUP;
    }

    public function get_categories()
    {
        return [TagsModule::URL_CATEGORY];
    }

    public function get_panel_template_setting_key()
    {
        return 'action';
    }

    protected function register_controls()
    {
        $this->add_control('action', [
            'label'   => __('On click', 'media-api-widget'),
            'type'    => Controls_Manager::SELECT,
            'options' => PostFields::clickActions(),
            'default' => PostFields::CLICK_LIGHTBOX,
        ]);

        $this->add_control('note', [
            'type'            => Controls_Manager::RAW_HTML,
            'raw'             => esc_html__('Every click dispatches the mediaApiWidgetItemClick event. Podcasts open the episode player; "with the playlist" keeps its episode list.', 'media-api-widget'),
            'content_classes' => 'elementor-descriptor',
        ]);

        $playlistOnly = ['action' => PostFields::CLICK_PLAYLIST];

        $this->add_control('lightbox_theme_color', [
            'label'     => __('Playlist border color (YouTube)', 'media-api-widget'),
            'type'      => Controls_Manager::COLOR,
            'alpha'     => false,
            'global'    => ['active' => false],
            'condition' => $playlistOnly,
        ]);

        $this->add_control('lightbox_font', [
            'label'     => __('Lightbox font (YouTube)', 'media-api-widget'),
            'type'      => Controls_Manager::TEXT,
            'condition' => $playlistOnly,
        ]);

        $this->add_control('lightbox_logo', [
            'label'     => __('Playlist logo URL (YouTube)', 'media-api-widget'),
            'type'      => Controls_Manager::TEXT,
            'condition' => $playlistOnly,
        ]);
    }

    public function get_value(array $options = [])
    {
        return PostFields::clickUrl((int) get_the_ID(), (string) $this->get_settings('action'), [
            'color' => (string) $this->get_settings('lightbox_theme_color'),
            'font'  => (string) $this->get_settings('lightbox_font'),
            'logo'  => (string) $this->get_settings('lightbox_logo'),
        ]);
    }
}
