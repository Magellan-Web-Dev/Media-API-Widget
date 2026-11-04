<?php
namespace MediaApiWidget\Integrations\Elementor\DynamicTags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;
use MediaApiWidget\PostIndex\PostFields;

if (!defined('ABSPATH')) { exit; }

/**
 * "Media API URL" — a stored URL field for link controls.
 *
 * Media items have no front-end page of their own, so link a loop card with
 * this (the YouTube watch URL or podcast episode page by default) rather than
 * the post's permalink. A link is plain navigation: it does not invoke the
 * plugin's lightbox or player (use "Media API Click Action" for that).
 *
 * Loaded only from {@see \MediaApiWidget\Integrations\Elementor\Integration::registerDynamicTags()}.
 */
final class MediaUrlTag extends Data_Tag
{
    public function get_name()
    {
        return 'maw-media-url';
    }

    public function get_title()
    {
        return __('Media API URL', 'media-api-widget');
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
        return 'key';
    }

    protected function register_controls()
    {
        $this->add_control('key', [
            'label'   => __('Field', 'media-api-widget'),
            'type'    => Controls_Manager::SELECT,
            'options' => PostFields::urlOptions(),
            'default' => 'maw_media_url',
        ]);
    }

    public function get_value(array $options = [])
    {
        return esc_url_raw(PostFields::value((int) get_the_ID(), (string) $this->get_settings('key')));
    }
}
