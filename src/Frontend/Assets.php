<?php
namespace MediaApiWidget\Frontend;

if (!defined('ABSPATH')) { exit; }

/**
 * Enqueues the plugin's front-end stylesheet and JavaScript bundle.
 *
 * Assets are registered on the wp_enqueue_scripts action and are only
 * loaded on non-admin (front-end) page requests. The versioned handles
 * allow cache-busting whenever the plugin version constant changes.
 */
final class Assets
{
    /**
     * Registers the wp_enqueue_scripts action hook.
     *
     * Must be called once during the plugins_loaded phase (before
     * wp_enqueue_scripts fires) so the hook is in place in time.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Enqueues the plugin stylesheet and script on front-end pages.
     *
     * Bails early when called in an admin context to avoid loading
     * front-end assets inside wp-admin. The script is loaded in the
     * footer (last argument true) so it does not block page rendering.
     *
     * @return void
     */
    public function enqueue(): void
    {
        // Only on front-end
        if (is_admin()) {
            return;
        }

        wp_enqueue_style('maw-media-api-widget', MAW_PLUGIN_URL . 'assets/media-api-widget.css', [], MAW_PLUGIN_VERSION);
        wp_enqueue_script('maw-media-api-widget', MAW_PLUGIN_URL . 'assets/media-api-widget.js', [], MAW_PLUGIN_VERSION, true);
    }
}
