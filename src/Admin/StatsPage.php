<?php
namespace MediaApiWidget\Admin;

use MediaApiWidget\Stats\ApiCallLogger;
use MediaApiWidget\Stats\BackupInventory;

if (!defined('ABSPATH')) { exit; }

/**
 * Renders the API call statistics admin sub-page.
 *
 * Displays a read-only dashboard of every external API call recorded by
 * {@see ApiCallLogger} in the last 24 hours. Data is shown at three levels
 * of granularity: aggregate totals, per-playlist / per-endpoint breakdown,
 * and an hourly time-series. Log records older than 48 hours are pruned
 * automatically by the logger; this page only reads data.
 *
 * The per-playlist breakdown also reports on the local backup JSON file each
 * playlist falls back to when an API call fails — when it was last stored
 * successfully, and a download link served by {@see self::handleDownload()}.
 */
final class StatsPage
{
    /**
     * admin-post action name for the backup JSON download endpoint.
     *
     * Hooked as `admin_post_{action}` by {@see Menu::register()}.
     *
     * @var string
     */
    public const DOWNLOAD_ACTION = 'maw_download_backup';

    /**
     * Streams a playlist's backup JSON file to the browser as a download.
     *
     * Hooked to `admin_post_maw_download_backup`. Requires the manage_options
     * capability and a valid nonce, resolves the file through
     * {@see BackupInventory} (which sanitizes the slug and only accepts known
     * media types), and confirms the resolved real path is inside the backup
     * directory before sending a single byte. The file is only ever read.
     *
     * @return void
     */
    public function handleDownload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer(self::DOWNLOAD_ACTION);

        $playlistName = isset($_GET['maw_playlist']) ? sanitize_key((string) $_GET['maw_playlist']) : '';
        $mediaType    = isset($_GET['maw_type']) ? sanitize_key((string) $_GET['maw_type']) : '';

        $backup = BackupInventory::describe($playlistName, $mediaType);

        if (!$backup['exists']) {
            wp_die('No backup file is stored for this playlist yet.', 'Not Found', ['response' => 404]);
        }

        // Defense in depth: sanitize_key() already strips slashes and dots, so
        // this can only fail on a symlinked or relocated backup directory.
        $resolvedPath  = realpath($backup['path']);
        $resolvedDir   = realpath(BackupInventory::directory());

        if ($resolvedPath === false || $resolvedDir === false
            || strpos($resolvedPath, rtrim($resolvedDir, '/\\') . DIRECTORY_SEPARATOR) !== 0) {
            wp_die('The requested backup file could not be resolved.', 'Forbidden', ['response' => 403]);
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $backup['file_name'] . '"');
        header('Content-Length: ' . (string) $backup['size']);
        header('X-Content-Type-Options: nosniff');

        // Discard any buffered admin output so the JSON body is byte-exact.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        readfile($resolvedPath);
        exit;
    }

    /**
     * Renders the API statistics page HTML.
     *
     * Computes the "since" boundary as current GMT time minus 24 hours,
     * queries the log table for totals, a per-playlist/endpoint breakdown,
     * and an hourly breakdown, then renders all three as wp-admin-style
     * striped tables. Timestamps are converted from GMT to the site
     * timezone before display.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $sinceTimestampGmt = current_time('timestamp', true) - DAY_IN_SECONDS;
        $sinceGmt = gmdate('Y-m-d H:i:s', $sinceTimestampGmt);

        $totals    = ApiCallLogger::getTotalsSince($sinceGmt);
        $breakdown = ApiCallLogger::getBreakdownSince($sinceGmt);
        $hourly    = ApiCallLogger::getHourlySince($sinceGmt);

        $timezone = wp_timezone();
        ?>
        <div class="wrap maw-wrap">
            <h1>Media API Statistics</h1>
            <p class="maw-note">Showing API request activity recorded in the last 24 hours (since <?= esc_html(wp_date('M j, Y g:i A T', $sinceTimestampGmt, $timezone)) ?>).</p>

            <table class="widefat striped maw-table" style="max-width:720px;"><tbody>
                <tr><th>Total API calls</th><td><?= esc_html((string) $totals['total_calls']) ?></td></tr>
                <tr><th>Successful calls</th><td><?= esc_html((string) $totals['success_calls']) ?></td></tr>
                <tr><th>Errored calls</th><td><?= esc_html((string) $totals['error_calls']) ?></td></tr>
            </tbody></table>

            <h2>By Playlist and Endpoint</h2>
            <?php if (count($breakdown) === 0) : ?>
                <p>No API calls recorded in the last 24 hours.</p>
            <?php else : ?>
                <table class="widefat striped maw-table"><thead><tr>
                    <th>Playlist</th><th>Type</th><th>Endpoint</th><th>Total</th><th>Errors</th><th>Last Successful Backup</th><th>Backup File</th>
                </tr></thead><tbody>
                    <?php foreach ($breakdown as $row) :
                        $playlistName = (string) ($row['playlist_name'] ?? '');
                        $mediaType    = (string) ($row['media_type'] ?? '');
                        $backup       = BackupInventory::describe($playlistName, $mediaType);
                    ?>
                        <tr>
                            <td><?= esc_html($playlistName) ?></td>
                            <td><?= esc_html($mediaType) ?></td>
                            <td><?= esc_html((string) ($row['endpoint'] ?? '')) ?></td>
                            <td><?= esc_html((string) (int) ($row['total_calls'] ?? 0)) ?></td>
                            <td><?= esc_html((string) (int) ($row['error_calls'] ?? 0)) ?></td>
                            <td>
                                <?php if ($backup['exists'] && $backup['stored_at'] > 0) : ?>
                                    <?= esc_html(wp_date('M j, Y g:i A T', $backup['stored_at'], $timezone)) ?>
                                <?php else : ?>
                                    <em>No backup stored</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($backup['exists']) : ?>
                                    <a href="<?= esc_url($this->backupDownloadUrl($playlistName, $mediaType)) ?>">Download</a>
                                    <span class="description">&nbsp;(<?= esc_html(size_format($backup['size'])) ?>)</span>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <p class="description" style="margin-top: 20px;">
                    Backup JSON is stored in <code>uploads/media-api-widget/backups/</code> and is served when a live API call fails.
                    A YouTube backup is written only after a refresh completes every requested page, so the timestamp above is the last
                    <em>successful</em> store rather than the last attempt. Podcast backups are written for every platform that fetches an
                    RSS feed, including Apple-lookup platforms; only embed platforms have no backup file.
                </p>
            <?php endif; ?>

            <h2>By Hour</h2>
            <?php if (count($hourly) === 0) : ?>
                <p>No hourly data yet for the last 24 hours.</p>
            <?php else : ?>
                <table class="widefat striped maw-table"><thead><tr>
                    <th>Hour</th><th>Playlist</th><th>Type</th><th>Endpoint</th><th>Total</th><th>Errors</th>
                </tr></thead><tbody>
                    <?php foreach ($hourly as $row) :
                        $hourGmt       = (string) ($row['hour_gmt'] ?? '');
                        $hourTimestamp = strtotime($hourGmt . ' UTC');
                        $hourLabel     = $hourTimestamp ? wp_date('M j, Y g:i A T', $hourTimestamp, $timezone) : $hourGmt . ' UTC';
                    ?>
                        <tr>
                            <td><?= esc_html($hourLabel) ?></td>
                            <td><?= esc_html((string) ($row['playlist_name'] ?? '')) ?></td>
                            <td><?= esc_html((string) ($row['media_type'] ?? '')) ?></td>
                            <td><?= esc_html((string) ($row['endpoint'] ?? '')) ?></td>
                            <td><?= esc_html((string) (int) ($row['total_calls'] ?? 0)) ?></td>
                            <td><?= esc_html((string) (int) ($row['error_calls'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Builds the nonced admin-post URL that downloads one backup JSON file.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return string Nonced admin-post.php URL.
     */
    private function backupDownloadUrl(string $playlistName, string $mediaType): string
    {
        $url = add_query_arg(
            [
                'action'       => self::DOWNLOAD_ACTION,
                'maw_playlist' => sanitize_key($playlistName),
                'maw_type'     => sanitize_key($mediaType),
            ],
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, self::DOWNLOAD_ACTION);
    }
}
