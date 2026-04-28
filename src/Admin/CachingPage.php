<?php
namespace MediaApiWidget\Admin;

use MediaApiWidget\Config\Options;

if (!defined('ABSPATH')) { exit; }

/**
 * Renders and handles the Caching settings admin sub-page.
 *
 * Exposes four configurable time-to-live (TTL) values that control how
 * aggressively the plugin caches YouTube API responses. Keeping these
 * values high reduces quota consumption on YouTube's 10,000-unit daily
 * limit. Changes take effect immediately on the next cache-miss.
 */
final class CachingPage
{
    /**
     * Processes the caching settings form submission.
     *
     * Hooked to admin_init. Runs on every admin request but returns early
     * unless the expected hidden field is present, the current user has
     * the manage_options capability, and the nonce validates. On success,
     * the sanitized values are persisted via {@see Options::setCacheExpirations()}
     * and the browser is redirected back to the Caching page with a status
     * query argument to display a success notice.
     *
     * @return void
     */
    public function handlePost(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['maw_save_cache_settings'])) {
            return;
        }

        if (!check_admin_referer('maw_save_cache_settings', 'maw_cache_nonce')) {
            return;
        }

        $raw = isset($_POST['maw_cache']) && is_array($_POST['maw_cache']) ? $_POST['maw_cache'] : [];
        Options::setCacheExpirations($raw);

        $redirectUrl = add_query_arg('maw_cache_status', 'saved', menu_page_url(Menu::CACHING_SLUG, false));
        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * Renders the Caching settings page HTML.
     *
     * Outputs a form-table with four numeric inputs (all in seconds):
     *
     * - Media cache transient TTL — how long fetched data is held in
     *   WordPress transients (and the client-side cookie).
     * - YouTube request-in-progress TTL — mutex window that prevents
     *   parallel duplicate API calls.
     * - YouTube error TTL — back-off window after a failed API call.
     * - YouTube backup window — if the last successful fetch was within
     *   this window, the local backup JSON is served rather than
     *   re-calling the API.
     *
     * On submission the form POSTs to admin-post and is handled by
     * {@see self::handlePost()} via the admin_init hook.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $status = isset($_GET['maw_cache_status']) ? sanitize_key((string) $_GET['maw_cache_status']) : '';
        $settings = Options::getCacheExpirations();

        echo '<div class="wrap maw-wrap">';
        echo '<h1>Caching</h1>';
        echo '<p class="maw-note">Set cache timing values in seconds. This is used primarily for the Youtube API to avoid making excessive API calls (Youtube API has a daily limit of 10,000 calls per day).</p>';

        if ($status === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Caching settings saved.</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('maw_save_cache_settings', 'maw_cache_nonce');

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="maw_media_cache_ttl">Media cache transient (seconds)</label></th>';
        echo '<td><input name="maw_cache[media_cache_ttl]" id="maw_media_cache_ttl" type="number" min="1" step="1" value="' . esc_attr((string) $settings['media_cache_ttl']) . '" class="regular-text" />';
        echo '<p class="description">Used for <code>{type}_{playlist_name}</code> transient cache.</p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="maw_youtube_request_in_progress_ttl">YouTube request-in-progress transient (seconds)</label></th>';
        echo '<td><input name="maw_cache[youtube_request_in_progress_ttl]" id="maw_youtube_request_in_progress_ttl" type="number" min="1" step="1" value="' . esc_attr((string) $settings['youtube_request_in_progress_ttl']) . '" class="regular-text" />';
        echo '<p class="description">Used for <code>{playlist_name}_youtube_request_in_progress</code>.</p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="maw_youtube_error_ttl">YouTube error transient (seconds)</label></th>';
        echo '<td><input name="maw_cache[youtube_error_ttl]" id="maw_youtube_error_ttl" type="number" min="1" step="1" value="' . esc_attr((string) $settings['youtube_error_ttl']) . '" class="regular-text" />';
        echo '<p class="description">Used for <code>{playlist_name}_youtube_error</code>.</p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="maw_youtube_backup_window_seconds">YouTube backup window (seconds)</label></th>';
        echo '<td><input name="maw_cache[youtube_backup_window_seconds]" id="maw_youtube_backup_window_seconds" type="number" min="1" step="1" value="' . esc_attr((string) $settings['youtube_backup_window_seconds']) . '" class="regular-text" />';
        echo '<p class="description">If the last successful fetch is within this window, backup JSON data can be used instead of refetching YouTube.</p></td>';
        echo '</tr>';
        echo '</tbody></table>';

        echo '<p><button type="submit" class="button button-primary" name="maw_save_cache_settings" value="1">Save caching settings</button></p>';
        echo '</form>';
        echo '</div>';
    }
}
