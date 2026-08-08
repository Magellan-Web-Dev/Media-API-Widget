<?php
/**
 * Plugin Name:  Media API Widget
 * Description:  Sync YouTube playlists and podcast RSS data to the front end, with admin-managed Media API settings.
 * Version:      5.3.0
 * Requires at least: 5.0
 * Requires PHP: 8.1
 * Author:       Chris Paschall
 * License:      GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

/**
 * PHP version guard.
 *
 * This file must not use PHP 8.1+ syntax directly. PHP parses the entire file
 * before executing any branch, so 8.1+ syntax here would cause a fatal parse
 * error on older runtimes before this guard ever runs. PHP 8.1+ code is safely
 * isolated in the separately required files inside the else block below.
 */
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        printf(
            '<strong>Media API Widget</strong> requires PHP 8.1 or higher. '
                . 'Your server is running PHP %s. Please contact your host to upgrade PHP before activating this plugin.',
            esc_html(PHP_VERSION)
        );
        echo '</p></div>';
    });

/**
 * Main plugin constants, autoloader, and lifecycle hooks.
 *
 * All PHP 8.1+ code is confined to this branch so that the version guard
 * above can fire cleanly on older runtimes without a parse error.
 */
} else {

    define('MAW_PLUGIN_VERSION', '5.3.0');
    define('MAW_PLUGIN_FILE', __FILE__);
    define('MAW_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('MAW_PLUGIN_URL', plugin_dir_url(__FILE__));

    require_once MAW_PLUGIN_DIR . 'src/Autoloader.php';

    MediaApiWidget\Autoloader::boot(MAW_PLUGIN_DIR);

    /**
     * Public global functions for the data enrichment API.
     *
     * Required after the autoloader so the functions can reference plugin
     * classes lazily. The autoloader maps class names only, so this file has to
     * be included explicitly for media_api_widget_update_stored_data() and
     * media_api_widget_get_stored_data() to exist once the plugin has loaded.
     */
    require_once MAW_PLUGIN_DIR . 'src/functions.php';

    /**
     * Plugin activation: register the rewrite rule, create the API log table,
     * seed the default podcast-player shortcode fields, and flush rewrite rules.
     */
    register_activation_hook(__FILE__, static function (): void {
        MediaApiWidget\Plugin::activate();
    });

    /**
     * Plugin deactivation: flush rewrite rules to remove the /podcast/player route.
     */
    register_deactivation_hook(__FILE__, static function (): void {
        MediaApiWidget\Plugin::deactivate();
    });

    add_action('plugins_loaded', static function (): void {
        (new MediaApiWidget\Plugin())->register();
    });

}
