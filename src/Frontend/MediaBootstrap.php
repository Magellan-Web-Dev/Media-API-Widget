<?php
namespace MediaApiWidget\Frontend;

use MediaApiWidget\Config\Options;

if (!defined('ABSPATH')) { exit; }

/**
 * Bootstraps the client-side media data pipeline.
 *
 * Manages the cache-invalidation cookie and, on every front-end page
 * request, pushes the latest playlist data into the page via wp_head
 * inline scripts. The front-end JavaScript then reads that data from
 * browser localStorage without any additional round-trips.
 *
 * Two hooks are registered:
 * - template_redirect (priority 1) — reads / sets the cookie before headers
 *   are sent so the decision is available to the wp_head handler.
 * - wp_head (priority 20) — outputs the inline initialization scripts after
 *   WordPress core has run its own head output.
 */
final class MediaBootstrap
{
    /**
     * Registers the template_redirect and wp_head action hooks.
     *
     * Must be called once during the plugins_loaded phase. Priority 1 on
     * template_redirect ensures the cookie state is resolved before any
     * other plugin or theme code runs on that action.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('template_redirect', [$this, 'handleCookie'], 1);
        add_action('wp_head', [$this, 'renderMedia'], 20);
    }

    /**
     * Reads or sets the cache-invalidation cookie and stores the result globally.
     *
     * If the cookie is already present in the request, the browser's
     * localStorage is considered fresh and no new data script needs to be
     * emitted. If the cookie is absent, it is set for the duration of the
     * current media cache TTL and $GLOBALS['maw_cookie_expired'] is set to
     * true so renderMedia() knows to push fresh data.
     *
     * Bails early on admin requests because this logic is only relevant on
     * the public-facing front end.
     *
     * @return void
     */
    public function handleCookie(): void
    {
        if (is_admin()) {
            return;
        }

        $cookieName       = Options::getCookieName();
        $cacheExpirations = Options::getCacheExpirations();
        $expirationTime   = time() + (int) $cacheExpirations['media_cache_ttl'];

        $GLOBALS['maw_cookie_name'] = $cookieName;

        if (isset($_COOKIE[$cookieName])) {
            $GLOBALS['maw_cookie_expired'] = false;
        } else {
            $GLOBALS['maw_cookie_expired'] = true;

            // Keep legacy behavior as closely as possible
            setcookie($cookieName, (string) $expirationTime, $expirationTime, '/');
        }
    }

    /**
     * Iterates all configured media items and emits their data scripts into wp_head.
     *
     * For each item defined in admin settings (and any items declared via the
     * legacy MEDIA_CONTENT_DATA constant), calls {@see MediaContent::getMediaContent()}
     * which handles cache lookups, API fetches, and emitting the appropriate
     * inline script tag.
     *
     * Bails early on admin requests. Reads cookie state from $GLOBALS set by
     * {@see self::handleCookie()}; falls back to safe defaults if the globals
     * are not set (e.g. when wp_head fires before template_redirect in tests).
     *
     * @return void
     */
    public function renderMedia(): void
    {
        if (is_admin()) {
            return;
        }

        $cookieName    = isset($GLOBALS['maw_cookie_name']) ? (string) $GLOBALS['maw_cookie_name'] : Options::getCookieName();
        $cookieExpired = isset($GLOBALS['maw_cookie_expired']) ? (bool) $GLOBALS['maw_cookie_expired'] : true;

        // Pull items from admin settings
        $items = Options::getMediaItems();

        // Back-compat: if a theme/WPCode still defines MEDIA_CONTENT_DATA, merge it in.
        if (defined('MEDIA_CONTENT_DATA') && is_array(constant('MEDIA_CONTENT_DATA'))) {
            $items = array_merge($items, constant('MEDIA_CONTENT_DATA'));
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            MediaContent::getMediaContent([
                'type'             => $item['type'] ?? 'youtube',
                'podcast_platform' => $item['podcast_platform'] ?? 'custom',
                'playlist_name'    => $item['playlist_name'] ?? null,
                'api_key'          => $item['api_key'] ?? null,
                'media_data'       => $item['media_data'] ?? null,
                'sort_mode'        => $item['sort_mode'] ?? 'normal',
                'load_full_playlist' => $item['load_full_playlist'] ?? false,
                'cookie_name'      => $cookieName,
                'cookie_expired'   => $cookieExpired,
            ]);
        }
    }
}
