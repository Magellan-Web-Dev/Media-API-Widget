<?php
namespace MediaApiWidget\Integrations\Elementor\DynamicTags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;
use MediaApiWidget\PostIndex\PostFields;

if (!defined('ABSPATH')) { exit; }

/**
 * "Media API Field" — any stored media item field as text or a number.
 *
 * Offers every field in one dropdown, the way Elementor Pro's ACF tags list
 * ACF fields. Elementor's own "Post Custom Field" tag can read the same meta
 * keys, but its dropdown only lists keys of the post being edited — inside a
 * Loop Item template that is the template, not a media item — so this tag is
 * what makes the fields pickable while building a loop.
 *
 * Loaded only from {@see \MediaApiWidget\Integrations\Elementor\Integration::registerDynamicTags()}.
 */
final class MediaFieldTag extends Tag
{
    public function get_name()
    {
        return 'maw-media-field';
    }

    public function get_title()
    {
        return __('Media API Field', 'media-api-widget');
    }

    public function get_group()
    {
        return Groups::GROUP;
    }

    public function get_categories()
    {
        return [TagsModule::TEXT_CATEGORY, TagsModule::NUMBER_CATEGORY, TagsModule::POST_META_CATEGORY];
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
            'options' => PostFields::options(),
            'default' => 'post_title',
        ]);
    }

    public function render()
    {
        $key   = (string) $this->get_settings('key');
        $value = PostFields::value((int) get_the_ID(), $key);

        // Descriptions keep their line breaks.
        echo $key === 'post_excerpt' ? nl2br(esc_html($value)) : esc_html($value);
    }
}
