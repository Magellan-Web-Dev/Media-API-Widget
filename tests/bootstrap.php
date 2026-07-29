<?php
/**
 * Minimal WordPress test double for Media API Widget.
 *
 * The plugin ships without Composer and uses its own PSR-4 autoloader, so the
 * test suite deliberately adds no dependencies either. This file provides just
 * enough of WordPress for the YouTube fetch, guard, and options code paths to
 * run in plain PHP:
 *
 *   - the option, transient, and object-cache APIs, backed by arrays;
 *   - the escaping/sanitizing helpers, behaving like core for the inputs used;
 *   - a queue-driven HTTP double, so a test scripts the exact YouTube responses;
 *   - a $wpdb double whose options table enforces the unique option_name index,
 *     which is what makes the atomic lock and counter genuinely testable.
 *
 * Run the suite with: php tests/run-tests.php
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/maw-tests-content');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

// ---------------------------------------------------------------------------
// Global test state
// ---------------------------------------------------------------------------

/**
 * Mutable in-memory state shared by every stub below.
 *
 * Reset between tests by {@see maw_test_reset()}.
 */
final class MawTestState
{
    /** @var array<string,mixed> Transient name => value. */
    public static array $transients = [];

    /** @var array<int,array<string,mixed>> Queued HTTP responses, consumed in order. */
    public static array $httpQueue = [];

    /** @var array<int,string> Every URL requested, in order. */
    public static array $httpRequests = [];

    /** @var array<int,array<string,string>> Every API log row written. */
    public static array $apiLog = [];

    /** @var array<string,mixed> Object cache: group => name => value. */
    public static array $cache = [];

    /** @var int|null Frozen time() value, or null to use the real clock. */
    public static ?int $now = null;
}

/**
 * Clears all in-memory state so each test starts from a clean install.
 *
 * @return void
 */
function maw_test_reset(): void
{
    global $wpdb;

    MawTestState::$transients   = [];
    MawTestState::$httpQueue    = [];
    MawTestState::$httpRequests = [];
    MawTestState::$apiLog       = [];
    MawTestState::$cache        = [];
    MawTestState::$now          = null;

    $wpdb->reset();

    // Backup JSON files are written for real, into a temp directory, so the
    // "partial refresh must not overwrite a good backup" test exercises the
    // actual file path rather than a mock.
    $backupDir = sys_get_temp_dir() . '/maw-tests-uploads/media-api-widget/backups';
    if (is_dir($backupDir)) {
        foreach ((array) glob($backupDir . '/*.json') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
    }

    // The backup inventory memoizes file metadata for the life of a request;
    // clear it so a file deleted above is not reported as still present.
    MediaApiWidget\Stats\BackupInventory::flushCache();
}

/**
 * Returns the current test clock.
 *
 * @return int Unix timestamp.
 */
function maw_test_time(): int
{
    return MawTestState::$now ?? time();
}

// ---------------------------------------------------------------------------
// $wpdb double
// ---------------------------------------------------------------------------

/**
 * Test double for the WordPress database layer.
 *
 * Only the statements this plugin issues against wp_options are interpreted,
 * matched on the distinctive shape of each query. The backing store is a plain
 * PHP map keyed by option_name, which reproduces the real unique index: a bare
 * INSERT for an existing name fails, which is exactly the property the atomic
 * lock and the atomic counter increment rely on.
 */
final class MawTestWpdb
{
    /** @var string Options table name. */
    public string $options = 'wp_options';

    /** @var string Table prefix. */
    public string $prefix = 'wp_';

    /** @var string Last database error message. */
    public string $last_error = '';

    /** @var array<string,string> option_name => option_value. */
    private array $rows = [];

    /** @var bool Whether errors are currently suppressed. */
    private bool $suppress = false;

    /** @var int Statements executed, for assertions about query volume. */
    public int $queryCount = 0;

    /**
     * Empties the simulated options table.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->rows       = [];
        $this->last_error = '';
        $this->queryCount = 0;
    }

    /**
     * Returns the raw stored value for an option name, or null.
     *
     * @param string $name Option name.
     * @return string|null Stored value.
     */
    public function peek(string $name): ?string
    {
        return $this->rows[$name] ?? null;
    }

    /**
     * Directly seeds a row, bypassing the statement interpreter.
     *
     * @param string $name  Option name.
     * @param string $value Option value.
     * @return void
     */
    public function seed(string $name, string $value): void
    {
        $this->rows[$name] = $value;
    }

    /**
     * Returns every option name currently stored.
     *
     * @return array<int,string> Option names.
     */
    public function names(): array
    {
        return array_keys($this->rows);
    }

    /**
     * Toggles error suppression, returning the previous setting.
     *
     * @param bool $suppress New setting.
     * @return bool Previous setting.
     */
    public function suppress_errors(bool $suppress = true): bool
    {
        $previous       = $this->suppress;
        $this->suppress = $suppress;

        return $previous;
    }

    /**
     * Substitutes %s / %d placeholders, quoting string values.
     *
     * @param string $query Query with placeholders.
     * @param mixed  ...$args Values to bind.
     * @return string Prepared SQL.
     */
    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $replacement = is_int($arg) || is_float($arg)
                ? (string) $arg
                : "'" . str_replace("'", "''", (string) $arg) . "'";

            $query = preg_replace('/%[sdf]/', str_replace('$', '\\$', $replacement), $query, 1) ?? $query;
        }

        return $query;
    }

    /**
     * Executes a supported statement against the simulated options table.
     *
     * @param string $query Prepared SQL.
     * @return int|false Rows affected, or false on a duplicate key / unsupported statement.
     */
    public function query(string $query)
    {
        $this->queryCount++;
        $this->last_error = '';
        $normalized       = preg_replace('/\s+/', ' ', trim($query)) ?? $query;

        // INSERT ... ON DUPLICATE KEY UPDATE option_value = CAST(...) + 1
        if (stripos($normalized, 'ON DUPLICATE KEY UPDATE') !== false) {
            $name = $this->firstLiteral($normalized);
            if ($name === null) {
                return false;
            }
            if (isset($this->rows[$name])) {
                $this->rows[$name] = (string) ((int) $this->rows[$name] + 1);

                return 2;
            }
            $this->rows[$name] = '1';

            return 1;
        }

        // Bare INSERT — must fail when the unique option_name already exists.
        if (stripos($normalized, 'INSERT INTO') === 0) {
            $literals = $this->literals($normalized);
            $name     = $literals[0] ?? null;
            $value    = $literals[1] ?? '';
            if ($name === null) {
                return false;
            }
            if (isset($this->rows[$name])) {
                $this->last_error = "Duplicate entry '{$name}' for key 'option_name'";

                return false;
            }
            $this->rows[$name] = (string) $value;

            return 1;
        }

        // UPDATE ... SET option_value = %s WHERE option_name = %s AND option_value = %s
        if (stripos($normalized, 'UPDATE') === 0) {
            $literals = $this->literals($normalized);
            if (count($literals) < 3) {
                return false;
            }
            [$newValue, $name, $expected] = $literals;
            if (($this->rows[$name] ?? null) !== $expected) {
                return 0;
            }
            $this->rows[$name] = $newValue;

            return 1;
        }

        // DELETE ... WHERE option_name = %s AND option_value = %s
        if (stripos($normalized, 'DELETE') === 0 && stripos($normalized, 'AND option_value') !== false) {
            $literals = $this->literals($normalized);
            if (count($literals) < 2) {
                return false;
            }
            [$name, $expected] = $literals;
            if (($this->rows[$name] ?? null) !== $expected) {
                return 0;
            }
            unset($this->rows[$name]);

            return 1;
        }

        // DELETE ... WHERE option_name LIKE %s AND option_name < %s
        if (stripos($normalized, 'DELETE') === 0 && stripos($normalized, 'LIKE') !== false) {
            $literals = $this->literals($normalized);
            if (count($literals) < 2) {
                return false;
            }
            [$like, $cutoff] = $literals;
            $pattern = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($like, '/')) . '$/';
            // preg_quote escaped the wildcards, so undo that before use.
            $pattern = str_replace(['\\.\\*', '\\.'], ['.*', '.'], $pattern);
            $deleted = 0;
            foreach (array_keys($this->rows) as $name) {
                if (preg_match($pattern, $name) === 1 && strcmp($name, $cutoff) < 0) {
                    unset($this->rows[$name]);
                    $deleted++;
                }
            }

            return $deleted;
        }

        return false;
    }

    /**
     * Records an API-call log row instead of writing to a database table.
     *
     * Lets a test assert that a request blocked before going outbound produced
     * no log row at all.
     *
     * @param string             $table   Ignored.
     * @param array<string,mixed> $data    Row values.
     * @param array<int,string>   $formats Ignored.
     * @return int Always 1.
     */
    public function insert(string $table, array $data, array $formats = []): int
    {
        MawTestState::$apiLog[] = $data;

        return 1;
    }

    /**
     * Executes a SELECT option_value statement.
     *
     * @param string $query Prepared SQL.
     * @return string|null Stored value, or null when absent.
     */
    public function get_var(string $query): ?string
    {
        $this->queryCount++;
        $name = $this->firstLiteral(preg_replace('/\s+/', ' ', trim($query)) ?? $query);

        if ($name === null) {
            return null;
        }

        return $this->rows[$name] ?? null;
    }

    /**
     * Extracts every single-quoted literal from a prepared statement.
     *
     * @param string $query Prepared SQL.
     * @return array<int,string> Literal values in order.
     */
    private function literals(string $query): array
    {
        preg_match_all("/'((?:[^']|'')*)'/", $query, $matches);

        $out = [];
        foreach ($matches[1] as $literal) {
            $out[] = str_replace("''", "'", $literal);
        }

        // 'no' is the hardcoded autoload column value, never a bound parameter.
        return array_values(array_filter($out, static fn(string $v): bool => $v !== 'no'));
    }

    /**
     * Returns the first bound literal in a prepared statement.
     *
     * @param string $query Prepared SQL.
     * @return string|null First literal, or null when there is none.
     */
    private function firstLiteral(string $query): ?string
    {
        return $this->literals($query)[0] ?? null;
    }
}

$wpdb = new MawTestWpdb();

// ---------------------------------------------------------------------------
// WordPress function stubs
// ---------------------------------------------------------------------------

/** Minimal WP_Error stand-in. */
class WP_Error
{
    /**
     * @param string $code    Error code.
     * @param string $message Error message.
     */
    public function __construct(public string $code = '', public string $message = '') {}

    /**
     * @return string Error code.
     */
    public function get_error_code(): string
    {
        return $this->code;
    }

    /**
     * @return string Error message.
     */
    public function get_error_message(): string
    {
        return $this->message;
    }
}

/**
 * @param mixed $thing Value to test.
 * @return bool True when $thing is a WP_Error.
 */
function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

/**
 * Options API backed by the $wpdb double, so direct SQL and the Options API
 * observe the same rows — matching real WordPress.
 *
 * @param string $name    Option name.
 * @param mixed  $default Value returned when the option is absent.
 * @return mixed Stored value.
 */
function get_option(string $name, $default = false)
{
    global $wpdb;

    $raw = $wpdb->peek($name);
    if ($raw === null) {
        return $default;
    }

    $unserialized = @unserialize($raw);

    return $unserialized === false && $raw !== serialize(false) ? $raw : $unserialized;
}

/**
 * @param string $name     Option name.
 * @param mixed  $value    Value to store.
 * @param bool   $autoload Ignored by the double.
 * @return bool Always true.
 */
function update_option(string $name, $value, bool $autoload = true): bool
{
    global $wpdb;

    $wpdb->seed($name, is_string($value) ? $value : serialize($value));

    return true;
}

/**
 * @param string $name Option name.
 * @return bool True when a row was removed.
 */
function delete_option(string $name): bool
{
    global $wpdb;

    if ($wpdb->peek($name) === null) {
        return false;
    }

    $wpdb->query("DELETE FROM wp_options WHERE option_name = '" . $name . "' AND option_value = '" . $wpdb->peek($name) . "'");

    return true;
}

/**
 * @param string $name       Transient name.
 * @return mixed Stored value, or false when absent/expired.
 */
function get_transient(string $name)
{
    $entry = MawTestState::$transients[$name] ?? null;

    if ($entry === null) {
        return false;
    }

    if ($entry['expires'] > 0 && $entry['expires'] <= maw_test_time()) {
        unset(MawTestState::$transients[$name]);

        return false;
    }

    return $entry['value'];
}

/**
 * @param string $name  Transient name.
 * @param mixed  $value Value to store.
 * @param int    $ttl   Lifetime in seconds (0 = no expiry).
 * @return bool Always true.
 */
function set_transient(string $name, $value, int $ttl = 0): bool
{
    MawTestState::$transients[$name] = [
        'value'   => $value,
        'expires' => $ttl > 0 ? maw_test_time() + $ttl : 0,
    ];

    return true;
}

/**
 * @param string $name Transient name.
 * @return bool True when a value was removed.
 */
function delete_transient(string $name): bool
{
    $existed = isset(MawTestState::$transients[$name]);
    unset(MawTestState::$transients[$name]);

    return $existed;
}

/**
 * @param string $key   Cache key.
 * @param string $group Cache group.
 * @return mixed Cached value, or false.
 */
function wp_cache_get(string $key, string $group = '')
{
    return MawTestState::$cache[$group][$key] ?? false;
}

/**
 * @param string $key   Cache key.
 * @param mixed  $value Value to cache.
 * @param string $group Cache group.
 * @return bool Always true.
 */
function wp_cache_set(string $key, $value, string $group = ''): bool
{
    MawTestState::$cache[$group][$key] = $value;

    return true;
}

/**
 * @param string $key   Cache key.
 * @param string $group Cache group.
 * @return bool Always true.
 */
function wp_cache_delete(string $key, string $group = ''): bool
{
    unset(MawTestState::$cache[$group][$key]);

    return true;
}

/**
 * Queue-driven HTTP double used by SafeRemoteRequest's stand-in.
 *
 * @param string              $url  Requested URL.
 * @param array<string,mixed> $args Ignored.
 * @return array<string,mixed>|WP_Error Next queued response.
 */
function wp_safe_remote_get(string $url, array $args = [])
{
    MawTestState::$httpRequests[] = $url;

    if (MawTestState::$httpQueue === []) {
        return new WP_Error('maw_test_no_response', 'No queued HTTP response for ' . $url);
    }

    return array_shift(MawTestState::$httpQueue);
}

/**
 * @param mixed $response Response array or WP_Error.
 * @return int HTTP status code (0 for WP_Error).
 */
function wp_remote_retrieve_response_code($response): int
{
    if (is_wp_error($response) || !is_array($response)) {
        return 0;
    }

    return (int) ($response['response']['code'] ?? 0);
}

/**
 * @param mixed $response Response array or WP_Error.
 * @return string Response body.
 */
function wp_remote_retrieve_body($response): string
{
    if (is_wp_error($response) || !is_array($response)) {
        return '';
    }

    return (string) ($response['body'] ?? '');
}

/**
 * @param mixed  $response Response array or WP_Error.
 * @param string $header   Header name.
 * @return string Header value.
 */
function wp_remote_retrieve_header($response, string $header): string
{
    if (is_wp_error($response) || !is_array($response)) {
        return '';
    }

    return (string) ($response['headers'][strtolower($header)] ?? '');
}

/**
 * @param string $key Raw key.
 * @return string Lowercased key with only alphanumerics, dashes, and underscores.
 */
function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
}

/**
 * @param mixed $value Raw value.
 * @return int Non-negative integer.
 */
function absint($value): int
{
    return abs((int) $value);
}

/**
 * @param string $text Raw text.
 * @return string HTML-escaped text.
 */
function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * @param string $text Raw text.
 * @return string Attribute-escaped text.
 */
function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * @param string             $url     Raw URL.
 * @param array<int,string>  $schemes Allowed schemes.
 * @return string Sanitized URL, or '' when the scheme is not allowed.
 */
function esc_url_raw(string $url, array $schemes = ['http', 'https']): string
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return in_array($scheme, $schemes, true) ? $url : '';
}

/**
 * @param mixed $value Value to encode.
 * @return string|false JSON string.
 */
function wp_json_encode($value)
{
    return json_encode($value);
}

/**
 * @param int  $length             Password length.
 * @param bool $specialChars       Ignored.
 * @param bool $extraSpecialChars  Ignored.
 * @return string Random alphanumeric string.
 */
function wp_generate_password(int $length = 12, bool $specialChars = true, bool $extraSpecialChars = false): string
{
    return substr(str_replace('=', '', base64_encode(random_bytes($length))), 0, $length);
}

/**
 * @param int|float $number Number to format.
 * @return string Formatted number.
 */
function number_format_i18n($number): string
{
    return number_format((float) $number);
}

/**
 * @return array<string,string> Upload directory info pointing at a temp dir.
 */
function wp_upload_dir(): array
{
    return ['basedir' => sys_get_temp_dir() . '/maw-tests-uploads'];
}

/**
 * @param string $dir Directory path.
 * @return bool True on success.
 */
function wp_mkdir_p(string $dir): bool
{
    return is_dir($dir) || mkdir($dir, 0777, true);
}

/**
 * @param string $mysqlFormat Ignored.
 * @param bool   $gmt         Ignored.
 * @return string GMT datetime string.
 */
function current_time(string $mysqlFormat = 'mysql', bool $gmt = false): string
{
    return gmdate('Y-m-d H:i:s', maw_test_time());
}

/**
 * @param string $format    Date format.
 * @param int    $timestamp Unix timestamp.
 * @return string Formatted date.
 */
function wp_date(string $format, int $timestamp): string
{
    return gmdate($format, $timestamp);
}

// ---------------------------------------------------------------------------
// Plugin autoloader
// ---------------------------------------------------------------------------

require_once dirname(__DIR__) . '/src/Autoloader.php';
MediaApiWidget\Autoloader::boot(dirname(__DIR__));

// The real SafeRemoteRequest and ApiCallLogger reach out to the network and to
// a database table respectively. Both are already loaded by the autoloader when
// referenced; the HTTP double above intercepts wp_safe_remote_get, and the log
// table is replaced by a lightweight recorder in tests/support/overrides.php.
require_once __DIR__ . '/support/helpers.php';
