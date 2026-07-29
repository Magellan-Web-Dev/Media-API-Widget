<?php
namespace MediaApiWidget\Admin;

use MediaApiWidget\Config\Options;
use MediaApiWidget\Support\YoutubeGuard;

if (!defined('ABSPATH')) { exit; }

/**
 * Renders and handles the Caching settings admin sub-page.
 *
 * Exposes four configurable time-to-live (TTL) values that control how
 * aggressively the plugin caches YouTube API responses, plus two runaway
 * guards — a maximum number of playlist pages per refresh and a daily
 * outbound-call circuit breaker. Keeping the TTLs high reduces quota
 * consumption on YouTube's 10,000-unit daily limit; the guards bound the
 * worst case if a playlist response ever misbehaves. Changes take effect
 * immediately on the next cache-miss.
 *
 * The page also shows read-only guard status: calls used against today's
 * budget, when that budget resets, and the most recent guard event.
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
     * Outputs a form-table with four TTL inputs (all in seconds):
     *
     * - Media cache transient TTL — how long fetched data is held in
     *   WordPress transients (and the client-side cookie).
     * - YouTube request-in-progress TTL — how long the per-playlist refresh
     *   lock stays valid before an abandoned one may be reclaimed.
     * - YouTube error TTL — back-off window after a failed API call.
     * - YouTube backup window — if the last successful fetch was within
     *   this window, the local backup JSON is served rather than
     *   re-calling the API.
     *
     * Plus two runaway guards (counts, not seconds):
     *
     * - YouTube maximum pages per refresh (1–100) — hard ceiling on
     *   playlistItems requests during one refresh.
     * - YouTube daily call limit (1–10,000) — circuit breaker on total
     *   outbound YouTube requests per quota day.
     *
     * Finally, a read-only status table reports calls used today, the quota
     * reset date/timezone, and the last guard event.
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

        $status   = isset($_GET['maw_cache_status']) ? sanitize_key((string) $_GET['maw_cache_status']) : '';
        $settings = Options::getCacheExpirations();

        $callsUsedToday = YoutubeGuard::getDailyCallCount();
        $dailyLimit     = (int) $settings['youtube_daily_call_limit'];
        $quotaDayLabel  = YoutubeGuard::getQuotaDayLabel();
        $guardStatus    = YoutubeGuard::getGuardStatus();
        ?>
        <div class="wrap maw-wrap">
            <h1>Caching</h1>
            <p class="maw-note">Set cache timing values in seconds. This is used primarily for the Youtube API to avoid making excessive API calls (Youtube API has a daily limit of 10,000 calls per day).</p>

            <?php if ($status === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Caching settings saved.</p></div>
            <?php endif; ?>

            <h2>YouTube guard status</h2>
            <table class="widefat striped" style="max-width:760px;"><tbody>
                <tr>
                    <th scope="row" style="width:280px;">YouTube calls used today</th>
                    <td>
                        <strong><?= esc_html(number_format_i18n($callsUsedToday)) ?></strong>
                        / <?= esc_html(number_format_i18n($dailyLimit)) ?>
                        <?php if ($callsUsedToday >= $dailyLimit): ?>
                            <span style="color:#b32d2e;">&nbsp;— daily limit reached; no further YouTube requests will be sent today.</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Quota day / reset</th>
                    <td>
                        <?= esc_html($quotaDayLabel) ?>
                        &nbsp;—&nbsp;resets at midnight <code><?= esc_html(YoutubeGuard::QUOTA_TIMEZONE) ?></code>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Last guard event</th>
                    <td>
                        <?php if ($guardStatus === null): ?>
                            <em>None recorded.</em>
                        <?php else: ?>
                            <strong><?= esc_html(YoutubeGuard::describeReason($guardStatus['reason'])) ?></strong>
                            <br />
                            <span class="description">
                                Reason code <code><?= esc_html($guardStatus['reason']) ?></code>
                                <?php if ($guardStatus['playlist'] !== ''): ?>
                                    &nbsp;|&nbsp;Playlist <code><?= esc_html($guardStatus['playlist']) ?></code>
                                <?php endif; ?>
                                <?php if ($guardStatus['pages'] > 0): ?>
                                    &nbsp;|&nbsp;<?= esc_html(number_format_i18n($guardStatus['pages'])) ?> page(s) requested
                                <?php endif; ?>
                                <?php if ($guardStatus['timestamp'] > 0): ?>
                                    &nbsp;|&nbsp;<?= esc_html($this->formatEventTime($guardStatus['timestamp'])) ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody></table>

            <h2>Settings</h2>

            <form method="post">
                <?php wp_nonce_field('maw_save_cache_settings', 'maw_cache_nonce'); ?>

                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="maw_media_cache_ttl">Media cache transient (seconds)</label></th>
                        <td>
                            <input name="maw_cache[media_cache_ttl]" id="maw_media_cache_ttl" type="number" min="1" step="1" value="<?= esc_attr((string) $settings['media_cache_ttl']) ?>" class="regular-text" />
                            <p class="description">Used for <code>{type}_{playlist_name}</code> transient cache.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="maw_youtube_request_in_progress_ttl">YouTube request-in-progress transient (seconds)</label></th>
                        <td>
                            <input name="maw_cache[youtube_request_in_progress_ttl]" id="maw_youtube_request_in_progress_ttl" type="number" min="1" step="1" value="<?= esc_attr((string) $settings['youtube_request_in_progress_ttl']) ?>" class="regular-text" />
                            <p class="description">Used for <code>{playlist_name}_youtube_request_in_progress</code> and for how long the atomic per-playlist refresh lock stays valid before an abandoned one can be reclaimed.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="maw_youtube_error_ttl">YouTube error transient (seconds)</label></th>
                        <td>
                            <input name="maw_cache[youtube_error_ttl]" id="maw_youtube_error_ttl" type="number" min="1" step="1" value="<?= esc_attr((string) $settings['youtube_error_ttl']) ?>" class="regular-text" />
                            <p class="description">Used for <code>{playlist_name}_youtube_error</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="maw_youtube_backup_window_seconds">YouTube backup window (seconds)</label></th>
                        <td>
                            <input name="maw_cache[youtube_backup_window_seconds]" id="maw_youtube_backup_window_seconds" type="number" min="1" step="1" value="<?= esc_attr((string) $settings['youtube_backup_window_seconds']) ?>" class="regular-text" />
                            <p class="description">If the last successful fetch is within this window, backup JSON data can be used instead of refetching YouTube.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="maw_youtube_max_pages_per_refresh">YouTube maximum pages per refresh</label></th>
                        <td>
                            <input name="maw_cache[youtube_max_pages_per_refresh]" id="maw_youtube_max_pages_per_refresh" type="number" min="<?= esc_attr((string) Options::MIN_YOUTUBE_MAX_PAGES) ?>" max="<?= esc_attr((string) Options::MAX_YOUTUBE_MAX_PAGES) ?>" step="1" value="<?= esc_attr((string) $settings['youtube_max_pages_per_refresh']) ?>" class="regular-text" />
                            <p class="description">
                                Hard ceiling on YouTube <code>playlistItems</code> requests during a single playlist refresh
                                (allowed <?= esc_html((string) Options::MIN_YOUTUBE_MAX_PAGES) ?>&ndash;<?= esc_html((string) Options::MAX_YOUTUBE_MAX_PAGES) ?>, default 20).
                                At 50 items per page, 20 pages covers about 1,000 items. If the ceiling is hit, the refresh is
                                abandoned and previously cached or backup data is kept rather than being replaced with a partial playlist.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="maw_youtube_daily_call_limit">YouTube daily call limit</label></th>
                        <td>
                            <input name="maw_cache[youtube_daily_call_limit]" id="maw_youtube_daily_call_limit" type="number" min="<?= esc_attr((string) Options::MIN_YOUTUBE_DAILY_CALL_LIMIT) ?>" max="<?= esc_attr((string) Options::MAX_YOUTUBE_DAILY_CALL_LIMIT) ?>" step="1" value="<?= esc_attr((string) $settings['youtube_daily_call_limit']) ?>" class="regular-text" />
                            <p class="description">
                                Circuit breaker on total outbound YouTube requests per quota day for this site
                                (allowed <?= esc_html((string) Options::MIN_YOUTUBE_DAILY_CALL_LIMIT) ?>&ndash;<?= esc_html(number_format_i18n(Options::MAX_YOUTUBE_DAILY_CALL_LIMIT)) ?>, default 200).
                                Every attempted request counts, including ones that return an error, because a failed call can still
                                consume quota. Once the limit is reached, cached or backup data is served and no request is sent.
                                Podcast, Apple, RSS, and plugin-update requests are not counted.
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <p><button type="submit" class="button button-primary" name="maw_save_cache_settings" value="1">Save caching settings</button></p>
            </form>
        </div>
        <?php
    }

    /**
     * Formats a guard event timestamp in the site's timezone.
     *
     * Uses wp_date() when available (WordPress 5.3+) so the value respects the
     * site timezone setting, and falls back to an explicit UTC rendering on
     * older installs, which this plugin still supports.
     *
     * @param int $timestamp Unix timestamp of the event.
     * @return string Human-readable date/time string.
     */
    private function formatEventTime(int $timestamp): string
    {
        if (function_exists('wp_date')) {
            $formatted = wp_date('Y-m-d H:i:s T', $timestamp);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
    }
}
