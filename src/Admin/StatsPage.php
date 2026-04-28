<?php
namespace MediaApiWidget\Admin;

use MediaApiWidget\Stats\ApiCallLogger;

if (!defined('ABSPATH')) { exit; }

/**
 * Renders the API call statistics admin sub-page.
 *
 * Displays a read-only dashboard of every external API call recorded by
 * {@see ApiCallLogger} in the last 24 hours. Data is shown at three levels
 * of granularity: aggregate totals, per-playlist / per-endpoint breakdown,
 * and an hourly time-series. Log records older than 48 hours are pruned
 * automatically by the logger; this page only reads data.
 */
final class StatsPage
{
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

        echo '<div class="wrap maw-wrap">';
        echo '<h1>Media API Statistics</h1>';
        echo '<p class="maw-note">Showing API request activity recorded in the last 24 hours (since ' . esc_html(wp_date('M j, Y g:i A T', $sinceTimestampGmt, $timezone)) . ').</p>';

        echo '<table class="widefat striped maw-table" style="max-width:720px;"><tbody>';
        echo '<tr><th>Total API calls</th><td>' . esc_html((string) $totals['total_calls']) . '</td></tr>';
        echo '<tr><th>Successful calls</th><td>' . esc_html((string) $totals['success_calls']) . '</td></tr>';
        echo '<tr><th>Errored calls</th><td>' . esc_html((string) $totals['error_calls']) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>By Playlist and Endpoint</h2>';
        if (count($breakdown) === 0) {
            echo '<p>No API calls recorded in the last 24 hours.</p>';
        } else {
            echo '<table class="widefat striped maw-table"><thead><tr>';
            echo '<th>Playlist</th><th>Type</th><th>Endpoint</th><th>Total</th><th>Errors</th>';
            echo '</tr></thead><tbody>';
            foreach ($breakdown as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['playlist_name'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['media_type'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['endpoint'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) (int) ($row['total_calls'] ?? 0)) . '</td>';
                echo '<td>' . esc_html((string) (int) ($row['error_calls'] ?? 0)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>By Hour</h2>';
        if (count($hourly) === 0) {
            echo '<p>No hourly data yet for the last 24 hours.</p>';
        } else {
            echo '<table class="widefat striped maw-table"><thead><tr>';
            echo '<th>Hour</th><th>Playlist</th><th>Type</th><th>Endpoint</th><th>Total</th><th>Errors</th>';
            echo '</tr></thead><tbody>';
            foreach ($hourly as $row) {
                $hourGmt       = (string) ($row['hour_gmt'] ?? '');
                $hourTimestamp = strtotime($hourGmt . ' UTC');
                $hourLabel     = $hourTimestamp ? wp_date('M j, Y g:i A T', $hourTimestamp, $timezone) : $hourGmt . ' UTC';

                echo '<tr>';
                echo '<td>' . esc_html($hourLabel) . '</td>';
                echo '<td>' . esc_html((string) ($row['playlist_name'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['media_type'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['endpoint'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) (int) ($row['total_calls'] ?? 0)) . '</td>';
                echo '<td>' . esc_html((string) (int) ($row['error_calls'] ?? 0)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
