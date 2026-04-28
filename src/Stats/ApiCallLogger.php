<?php
namespace MediaApiWidget\Stats;

if (!defined('ABSPATH')) { exit; }

/**
 * Logs every external API call to a custom database table and exposes
 * aggregated query methods for the admin Stats page.
 *
 * Schema: one row per API call with playlist name, media type, endpoint,
 * HTTP status code, an error flag, and a GMT timestamp. Records older than
 * {@see self::RETENTION_SECONDS} (48 hours) are pruned automatically at most
 * once per {@see self::PRUNE_INTERVAL_SECONDS} (1 hour) to keep the table small.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class ApiCallLogger
{
    /**
     * wp_options key used to store the currently installed schema version.
     * A mismatch triggers {@see self::createTable()} to run dbDelta.
     *
     * @var string
     */
    private const OPTION_SCHEMA_VERSION = 'maw_api_call_log_schema_version';

    /**
     * Current schema version string. Increment when the table structure changes
     * so that existing installs are automatically upgraded via dbDelta.
     *
     * @var string
     */
    private const SCHEMA_VERSION = '1';

    /**
     * Suffix appended to the WordPress table prefix to form the full table name.
     * The full name is resolved via {@see self::tableName()}.
     *
     * @var string
     */
    private const TABLE_SUFFIX = 'maw_api_call_logs';

    /**
     * wp_options key storing the GMT timestamp of the last successful prune run.
     *
     * @var string
     */
    private const OPTION_LAST_PRUNE = 'maw_api_call_log_last_prune_gmt';

    /**
     * How long (in seconds) log records are retained before being pruned.
     * Defaults to 2 days (2 × DAY_IN_SECONDS).
     *
     * @var int
     */
    private const RETENTION_SECONDS = 2 * DAY_IN_SECONDS;

    /**
     * Minimum interval (in seconds) between prune operations.
     * Defaults to 1 hour (HOUR_IN_SECONDS) to avoid excessive DELETE queries.
     *
     * @var int
     */
    private const PRUNE_INTERVAL_SECONDS = HOUR_IN_SECONDS;

    /**
     * Creates the log table if the installed schema version does not match.
     *
     * Called on every plugins_loaded via {@see Plugin::register()} as a
     * lightweight guard. Reads the stored schema version from wp_options and
     * returns immediately if it matches {@see self::SCHEMA_VERSION}, so the
     * dbDelta overhead is only incurred on first activation or after an upgrade.
     *
     * @return void
     */
    public static function maybeInstall(): void
    {
        $installedVersion = (string) get_option(self::OPTION_SCHEMA_VERSION, '');
        if ($installedVersion === self::SCHEMA_VERSION) {
            return;
        }

        self::createTable();
    }

    /**
     * Creates or upgrades the API call log database table using dbDelta.
     *
     * Table columns:
     * - id           BIGINT UNSIGNED AUTO_INCREMENT primary key.
     * - playlist_name VARCHAR(191) — the slug of the media item being fetched.
     * - media_type    VARCHAR(32)  — 'youtube' or 'podcast'.
     * - endpoint      VARCHAR(64)  — e.g. 'youtube_playlist_items', 'podcast_rss'.
     * - http_status   SMALLINT     — HTTP response code, or NULL on WP_Error.
     * - is_error      TINYINT(1)   — 1 if the call failed (non-2xx or WP_Error).
     * - created_at    DATETIME     — GMT timestamp of the logged call.
     *
     * Indexed on created_at, playlist_name, media_type, and endpoint.
     * After a successful run, updates the schema version option.
     *
     * @return void
     */
    public static function createTable(): void
    {
        global $wpdb;

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            playlist_name VARCHAR(191) NOT NULL DEFAULT '',
            media_type VARCHAR(32) NOT NULL DEFAULT '',
            endpoint VARCHAR(64) NOT NULL DEFAULT '',
            http_status SMALLINT(5) UNSIGNED NULL,
            is_error TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY playlist_name (playlist_name),
            KEY media_type (media_type),
            KEY endpoint (endpoint)
        ) {$charset};";

        dbDelta($sql);
        update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION);
    }

    /**
     * Inserts a single API call log record and triggers a prune if due.
     *
     * Determines whether the response constitutes an error: WP_Error objects
     * are always errors; HTTP responses with a status outside 200–299 are also
     * treated as errors. The HTTP status is stored as NULL when a WP_Error
     * prevents a response code from being retrieved.
     *
     * @param string $playlistName The playlist_name slug of the media item.
     * @param string $mediaType    The media type ('youtube' or 'podcast').
     * @param string $endpoint     Identifier for the API endpoint called
     *                             (e.g. 'youtube_playlist_items', 'podcast_rss').
     * @param mixed  $response     The raw return value from wp_remote_get() — either
     *                             a WP_HTTP response array or a WP_Error instance.
     * @return void
     */
    public static function log(string $playlistName, string $mediaType, string $endpoint, $response): void
    {
        global $wpdb;

        self::maybePruneExpiredLogs();

        $statusCode = null;
        $isError    = 0;

        if (is_wp_error($response)) {
            $isError = 1;
        } else {
            $statusCode = (int) wp_remote_retrieve_response_code($response);
            if ($statusCode < 200 || $statusCode >= 300) {
                $isError = 1;
            }
        }

        $wpdb->insert(
            self::tableName(),
            [
                'playlist_name' => sanitize_key($playlistName),
                'media_type'    => sanitize_key($mediaType),
                'endpoint'      => sanitize_key($endpoint),
                'http_status'   => $statusCode ?: null,
                'is_error'      => $isError,
                'created_at'    => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%d', '%s']
        );
    }

    /**
     * Deletes log records older than RETENTION_SECONDS if PRUNE_INTERVAL_SECONDS
     * have elapsed since the last prune.
     *
     * Reads the last-prune timestamp from wp_options and returns early if the
     * interval has not passed, so this runs at most once per hour regardless of
     * how many API calls are logged per request.
     *
     * @return void
     */
    private static function maybePruneExpiredLogs(): void
    {
        global $wpdb;

        $now           = time();
        $lastPrunedAt  = (int) get_option(self::OPTION_LAST_PRUNE, 0);
        if ($lastPrunedAt > 0 && ($now - $lastPrunedAt) < self::PRUNE_INTERVAL_SECONDS) {
            return;
        }

        $cutoffGmt = gmdate('Y-m-d H:i:s', $now - self::RETENTION_SECONDS);

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::tableName() . ' WHERE created_at < %s',
                $cutoffGmt
            )
        );

        update_option(self::OPTION_LAST_PRUNE, $now, false);
    }

    /**
     * Returns aggregate call totals since a given GMT datetime string.
     *
     * @param string $sinceGmt GMT datetime in 'Y-m-d H:i:s' format.
     * @return array<string,int> Keys: 'total_calls', 'error_calls', 'success_calls'.
     */
    public static function getTotalsSince(string $sinceGmt): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_calls, SUM(is_error) AS error_calls
                 FROM ' . self::tableName() . '
                 WHERE created_at >= %s',
                $sinceGmt
            ),
            ARRAY_A
        );

        $totalCalls = isset($row['total_calls']) ? (int) $row['total_calls'] : 0;
        $errorCalls = isset($row['error_calls']) ? (int) $row['error_calls'] : 0;

        return [
            'total_calls'   => $totalCalls,
            'error_calls'   => $errorCalls,
            'success_calls' => max(0, $totalCalls - $errorCalls),
        ];
    }

    /**
     * Returns per-playlist, per-endpoint call counts since a given GMT datetime.
     *
     * Results are grouped by playlist_name, media_type, and endpoint, ordered
     * by total_calls descending then playlist_name ascending.
     *
     * @param string $sinceGmt GMT datetime in 'Y-m-d H:i:s' format.
     * @return array<int, array<string,mixed>> Each row: playlist_name, media_type,
     *                                         endpoint, total_calls, error_calls.
     */
    public static function getBreakdownSince(string $sinceGmt): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT playlist_name, media_type, endpoint, COUNT(*) AS total_calls, SUM(is_error) AS error_calls
                 FROM ' . self::tableName() . '
                 WHERE created_at >= %s
                 GROUP BY playlist_name, media_type, endpoint
                 ORDER BY total_calls DESC, playlist_name ASC',
                $sinceGmt
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Returns hourly call counts since a given GMT datetime.
     *
     * Results are grouped by truncated hour (GMT), playlist_name, media_type,
     * and endpoint. Ordered by hour descending, then total_calls descending,
     * then playlist_name ascending.
     *
     * @param string $sinceGmt GMT datetime in 'Y-m-d H:i:s' format.
     * @return array<int, array<string,mixed>> Each row: hour_gmt, playlist_name,
     *                                         media_type, endpoint, total_calls,
     *                                         error_calls.
     */
    public static function getHourlySince(string $sinceGmt): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:00:00') AS hour_gmt, playlist_name, media_type, endpoint, COUNT(*) AS total_calls, SUM(is_error) AS error_calls
                 FROM " . self::tableName() . '
                 WHERE created_at >= %s
                 GROUP BY hour_gmt, playlist_name, media_type, endpoint
                 ORDER BY hour_gmt DESC, total_calls DESC, playlist_name ASC',
                $sinceGmt
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Returns the fully-qualified database table name including the WordPress prefix.
     *
     * @return string e.g. 'wp_maw_api_call_logs'.
     */
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }
}
