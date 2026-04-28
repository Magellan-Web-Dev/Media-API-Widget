<?php
namespace MediaApiWidget;

use MediaApiWidget\Admin\Menu;
use MediaApiWidget\Config\Options;
use MediaApiWidget\Frontend\Assets;
use MediaApiWidget\Frontend\MediaBootstrap;
use MediaApiWidget\Frontend\Shortcode;
use MediaApiWidget\Routing\PodcastPlayerRoute;
use MediaApiWidget\Seo\MetaUpdater;
use MediaApiWidget\Stats\ApiCallLogger;

if (!defined('ABSPATH')) { exit; }

/**
 * Central bootstrap class for the Media API Widget plugin.
 *
 * Owns the plugin lifecycle (activation / deactivation) and wires all
 * subsystems together when WordPress fires the plugins_loaded action.
 * Declared final to prevent subclassing of the root plugin object.
 */
final class Plugin
{
    /**
     * Runs when the plugin is activated via the Plugins screen.
     *
     * - Registers the /podcast/player rewrite rule so it is available
     *   immediately without a manual Permalinks flush.
     * - Creates (or upgrades) the API call log database table.
     * - Seeds the eight default podcast-player shortcode fields if they
     *   are not already present in the database.
     * - Records the first-activation timestamp for compatibility with
     *   older installs that relied on that option.
     * - Flushes WordPress rewrite rules so the new route takes effect.
     *
     * @return void
     */
    public static function activate(): void
    {
        (new PodcastPlayerRoute())->addRewriteRule();
        ApiCallLogger::createTable();
        Options::maybeSeedDefaultShortcodes();

        // Keep the legacy activation timestamp for compatibility with older installs.
        if (!get_option('media_api_widget_first_activated')) {
            update_option('media_api_widget_first_activated', time());
        }

        flush_rewrite_rules();
    }

    /**
     * Runs when the plugin is deactivated via the Plugins screen.
     *
     * Flushes WordPress rewrite rules so the /podcast/player route is
     * removed from the active rule set immediately.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Wires up all plugin subsystems on the plugins_loaded action.
     *
     * Performs a lightweight schema-version check for the log table,
     * ensures default shortcode fields are present, then instantiates and
     * registers every subsystem:
     *
     * - {@see Menu}              — admin sidebar menu and sub-pages.
     * - {@see Assets}            — front-end CSS / JS enqueueing.
     * - {@see MediaBootstrap}    — cookie management and wp_head data injection.
     * - {@see Shortcode}         — all four shortcode tags.
     * - {@see MetaUpdater}       — Open Graph / Twitter Card meta tags.
     * - {@see PodcastPlayerRoute} — /podcast/player custom route.
     *
     * @return void
     */
    public function register(): void
    {
        ApiCallLogger::maybeInstall();
        Options::maybeSeedDefaultShortcodes();

        // Admin
        (new Menu())->register();

        // Front-end
        (new Assets())->register();
        (new MediaBootstrap())->register();
        (new Shortcode())->register();

        // SEO meta updater (REST + wp_head output)
        (new MetaUpdater())->register();

        // Custom podcast player route
        (new PodcastPlayerRoute())->register();
    }
}
