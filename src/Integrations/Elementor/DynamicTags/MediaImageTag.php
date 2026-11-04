<?php
namespace MediaApiWidget\Integrations\Elementor\DynamicTags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;
use MediaApiWidget\PostIndex\PostIndex;

if (!defined('ABSPATH')) { exit; }

/**
 * "Media API Image" — the item's remote thumbnail for image controls.
 *
 * Returns the image in the `['id' => …, 'url' => …]` shape Elementor image
 * controls take, with an empty id because the image is a remote URL rather than
 * a Media Library attachment. Nothing is downloaded or imported. A stored
 * thumbnail URL is not a WordPress featured image, so "Featured Image" tags and
 * widgets do not see it; use this tag instead (Image widget, backgrounds, …).
 *
 * Loaded only from {@see \MediaApiWidget\Integrations\Elementor\Integration::registerDynamicTags()}.
 */
final class MediaImageTag extends Data_Tag
{
    public function get_name()
    {
        return 'maw-media-image';
    }

    public function get_title()
    {
        return __('Media API Image', 'media-api-widget');
    }

    public function get_group()
    {
        return Groups::GROUP;
    }

    public function get_categories()
    {
        return [TagsModule::IMAGE_CATEGORY, TagsModule::MEDIA_CATEGORY];
    }

    protected function register_controls()
    {
        $this->add_control('fallback', [
            'label' => __('Fallback', 'media-api-widget'),
            'type'  => Controls_Manager::MEDIA,
        ]);
    }

    public function get_value(array $options = [])
    {
        $postId = (int) get_the_ID();
        $url    = get_post_type($postId) === PostIndex::POST_TYPE
            ? esc_url_raw((string) get_post_meta($postId, PostIndex::META_THUMBNAIL_URL, true))
            : '';

        if ($url !== '') {
            return ['id' => '', 'url' => $url];
        }

        $fallback = $this->get_settings('fallback');

        return is_array($fallback) ? $fallback : ['id' => '', 'url' => ''];
    }
}
