<?php
/**
 * WordPress doubles for the shortcode rendering paths.
 *
 * bootstrap.php deliberately stops short of the shortcode API, because the
 * fetch and storage suites drive private methods directly. The Elementor
 * integration suite needs the real shortcode entry points instead — the
 * widgets must be proven to render through the registered callbacks — so this
 * file adds the shortcode registry, shortcode_atts() with its filter,
 * remove_filter(), and the URL, escaping, i18n and AJAX helpers those
 * renderers call.
 *
 * Loaded only by the case files that need it. Every function is guarded with
 * function_exists() so loading it twice, or alongside a fuller double, is safe,
 * and so suites that run before it are unaffected.
 */

declare(strict_types=1);

/**
 * Request-scoped state for these doubles.
 */
final class MawTestShortcodes
{
    /** @var string Value get_permalink() returns. */
    public static string $permalink = 'https://example.test/page/';

    /** @var bool Value wp_doing_ajax() returns. */
    public static bool $doingAjax = false;

    /**
     * Resets the doubles and the shortcode registry.
     *
     * @return void
     */
    public static function reset(): void
    {
        global $shortcode_tags;

        $shortcode_tags  = [];
        self::$permalink = 'https://example.test/page/';
        self::$doingAjax = false;
        $_GET            = [];
        $_POST           = [];
        $_REQUEST        = [];
    }
}

/**
 * Thrown by the wp_send_json_* doubles instead of exiting.
 */
final class MawTestJsonResponse extends RuntimeException
{
    /**
     * @param bool  $success Whether wp_send_json_success() was called.
     * @param mixed $data    Response data.
     * @param int   $status  HTTP status.
     */
    public function __construct(public bool $success, public $data, public int $status = 200)
    {
        parent::__construct('wp_send_json');
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        global $shortcode_tags;
        $shortcode_tags[$tag] = $callback;
    }
}

if (!function_exists('remove_shortcode')) {
    function remove_shortcode(string $tag): void
    {
        global $shortcode_tags;
        unset($shortcode_tags[$tag]);
    }
}

if (!function_exists('shortcode_atts')) {
    /**
     * Same algorithm as core: unknown attributes are dropped, then the
     * `shortcode_atts_{$shortcode}` filter runs with the raw attributes.
     */
    function shortcode_atts(array $pairs, $atts, string $shortcode = ''): array
    {
        $atts = (array) $atts;
        $out  = [];

        foreach ($pairs as $name => $default) {
            $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
        }

        if ($shortcode !== '') {
            $out = apply_filters("shortcode_atts_{$shortcode}", $out, $pairs, $atts, $shortcode);
        }

        return $out;
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $tag, callable $callback, int $priority = 10): bool
    {
        foreach (MawTestState::$hooks[$tag][$priority] ?? [] as $index => $registered) {
            if ($registered['callback'] === $callback) {
                unset(MawTestState::$hooks[$tag][$priority][$index]);

                // Like core, drop emptied buckets so has_filter() reports false.
                if (MawTestState::$hooks[$tag][$priority] === []) {
                    unset(MawTestState::$hooks[$tag][$priority]);
                }
                if (MawTestState::$hooks[$tag] === []) {
                    unset(MawTestState::$hooks[$tag]);
                }

                return true;
            }
        }

        return false;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr($text);
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        if ($url === '') {
            return '';
        }

        return str_replace(['&', "'"], ['&#038;', '&#039;'], $url);
    }
}

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $class): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $class) ?? '';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool
    {
        return MawTestShortcodes::$doingAjax;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(): string
    {
        return MawTestShortcodes::$permalink;
    }
}

if (!function_exists('add_query_arg')) {
    /**
     * Supports both core call shapes used by the renderer:
     * add_query_arg(array $args, string $url) and
     * add_query_arg(string $key, $value, string $url).
     */
    function add_query_arg(...$args): string
    {
        if (is_array($args[0])) {
            [$params, $url] = [$args[0], (string) ($args[1] ?? '')];
        } else {
            [$params, $url] = [[(string) $args[0] => $args[1]], (string) ($args[2] ?? '')];
        }

        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($query, $existing);

        foreach ($params as $key => $value) {
            $existing[$key] = (string) $value;
        }

        return $base . ($existing !== [] ? '?' . http_build_query($existing) : '');
    }
}

if (!function_exists('remove_query_arg')) {
    function remove_query_arg(string $key, string $url): string
    {
        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($query, $existing);
        unset($existing[$key]);

        return $base . ($existing !== [] ? '?' . http_build_query($existing) : '');
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer(string $action = '', string $queryArg = '', bool $stop = true): bool
    {
        return true;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, int $status = 200): void
    {
        throw new MawTestJsonResponse(true, $data, $status);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, int $status = 200): void
    {
        throw new MawTestJsonResponse(false, $data, $status);
    }
}
