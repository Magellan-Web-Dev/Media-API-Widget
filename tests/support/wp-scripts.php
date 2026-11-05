<?php
/**
 * WordPress script/style enqueue doubles.
 *
 * Records what {@see \MediaApiWidget\Frontend\Assets::enqueue()} registers so
 * a test can read the data a page exposes to the front-end script.
 *
 * Loaded only by the case files that need it. Every function is guarded with
 * function_exists() so loading it twice is safe.
 */

declare(strict_types=1);

if (!defined('MAW_PLUGIN_URL')) {
    define('MAW_PLUGIN_URL', 'https://example.test/wp-content/plugins/media-api-widget/');
}

if (!defined('MAW_PLUGIN_VERSION')) {
    define('MAW_PLUGIN_VERSION', 'test');
}

/**
 * Request-scoped state for these doubles.
 */
final class MawTestScripts
{
    /** @var array<int,string> Enqueued script handles, in order. */
    public static array $scripts = [];

    /** @var array<int,string> Enqueued style handles, in order. */
    public static array $styles = [];

    /** @var array<string,array<string,mixed>> Handle => object name => localized data. */
    public static array $localized = [];

    /**
     * Clears every recorded call.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$scripts   = [];
        self::$styles    = [];
        self::$localized = [];
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], $ver = false, $args = []): void
    {
        MawTestScripts::$scripts[] = $handle;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = false, string $media = 'all'): void
    {
        MawTestScripts::$styles[] = $handle;
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $objectName, array $l10n): bool
    {
        MawTestScripts::$localized[$handle][$objectName] = $l10n;

        return true;
    }
}
