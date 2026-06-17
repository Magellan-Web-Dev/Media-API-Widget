<?php
namespace MediaApiWidget\Support;

if (!defined('ABSPATH')) { exit; }

/**
 * SSRF-hardened wrapper around the WordPress HTTP API.
 *
 * Every outbound HTTP fetch in the plugin routes through here so a single
 * place can enforce four guarantees on each request:
 *
 *   1. Only the http and https schemes are allowed.
 *   2. The host must resolve to a public IP — checked on the initial URL
 *      AND on every redirect hop (loopback, RFC-1918, link-local and the
 *      common private hostnames are rejected).
 *   3. An explicit request timeout is always set.
 *   4. The response body is capped to a maximum size.
 *
 * Redirects are followed manually rather than by the HTTP transport, because
 * WordPress (like most HTTP clients) does not re-validate a redirect target
 * against private hosts — so a public URL could 302 to http://127.0.0.1/ or a
 * cloud metadata endpoint. Following each hop ourselves lets us re-run the
 * host check before fetching it, while still supporting podcast feeds that
 * legitimately redirect (Feedburner, Megaphone, Libsyn CDNs, and similar).
 */
final class SafeRemoteRequest
{
    /** Default request timeout in seconds. */
    private const TIMEOUT = 10;

    /** Maximum accepted response-body size in bytes (15 MB). */
    private const MAX_BYTES = 15728640;

    /** Maximum number of redirect hops to follow before giving up. */
    private const MAX_REDIRECTS = 5;

    /**
     * Performs a GET request with SSRF protection and resource limits.
     *
     * Returns the same shape as wp_remote_get() — an array on success or a
     * WP_Error on failure — so existing is_wp_error() / wp_remote_retrieve_*
     * call sites keep working unchanged. A blocked URL (bad scheme, or a
     * private/loopback/link-local host at any hop) comes back as a WP_Error,
     * which callers already treat as a failed fetch.
     *
     * @param string              $url  Absolute http/https URL to fetch.
     * @param array<string,mixed> $args Optional wp_remote_get() args to merge.
     *                                  timeout, limit_response_size, redirection
     *                                  and reject_unsafe_urls are managed here and
     *                                  cannot be overridden.
     * @return array<string,mixed>|\WP_Error
     */
    public static function get(string $url, array $args = [])
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $validated = self::validateUrl($current);
            if (is_wp_error($validated)) {
                return $validated;
            }
            $current = $validated;

            $requestArgs = array_merge([
                'timeout'             => self::TIMEOUT,
                'limit_response_size' => self::MAX_BYTES,
            ], $args);

            // Always managed here: follow redirects ourselves so each hop is
            // re-validated, and let WordPress apply its own private-host block.
            $requestArgs['redirection']        = 0;
            $requestArgs['reject_unsafe_urls'] = true;

            $response = wp_safe_remote_get($current, $requestArgs);
            if (is_wp_error($response)) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 300 || $code >= 400) {
                return $response;
            }

            // 3xx: resolve and re-validate the redirect target before following.
            $location = wp_remote_retrieve_header($response, 'location');
            if (is_array($location)) {
                $location = (string) end($location);
            }
            $location = (string) $location;
            if ($location === '') {
                return $response; // redirect with no target — hand it back as-is
            }

            $next = self::resolveUrl($current, $location);
            if ($next === '') {
                return new \WP_Error('maw_unresolvable_redirect', 'Could not resolve the redirect target URL.');
            }
            $current = $next;
        }

        return new \WP_Error('maw_too_many_redirects', 'Exceeded the maximum number of redirects.');
    }

    /**
     * Validates a URL's scheme and host before it is fetched.
     *
     * @param string $url URL to validate.
     * @return string|\WP_Error Sanitized URL on success, WP_Error if disallowed.
     */
    private static function validateUrl(string $url)
    {
        $safe = esc_url_raw($url, ['http', 'https']);
        if (!$safe) {
            return new \WP_Error('maw_bad_scheme', 'The URL must use http or https.');
        }
        if (!self::isHostPublic($safe)) {
            return new \WP_Error('maw_private_host', 'The URL host is not publicly accessible.');
        }
        return $safe;
    }

    /**
     * Returns true only if the host in $url resolves to a public IP address.
     *
     * Blocks bare private/loopback/link-local IP literals, the common private
     * hostnames (localhost, *.local, *.internal), and hostnames that resolve
     * to a private IPv4 address. Hosts that cannot be resolved are allowed
     * through (the request then fails at the network layer, exactly as before),
     * so DNS hiccups do not turn into hard failures for legitimate feeds.
     *
     * @param string $url Absolute URL whose host should be checked.
     * @return bool True when the host is considered public.
     */
    public static function isHostPublic(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = trim($host, '[]'); // strip IPv6 brackets

        if ($host === '') {
            return false;
        }

        // Bare IP literal: validate directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        // Reject private-network hostnames before resolution.
        $lower = strtolower($host);
        if (
            $lower === 'localhost' ||
            str_ends_with($lower, '.local') ||
            str_ends_with($lower, '.internal')
        ) {
            return false;
        }

        // Resolve hostname to IPv4 and reject private ranges.
        $resolved = gethostbyname($host);
        if ($resolved !== $host) {
            return (bool) filter_var(
                $resolved,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        return true;
    }

    /**
     * Resolves a (possibly relative) Location header against the request URL.
     *
     * Handles absolute http(s) URLs, protocol-relative (//host/path),
     * root-relative (/path), and simple relative paths. Returns '' when the
     * target cannot be resolved to an absolute URL.
     *
     * @param string $base     The URL the redirect response came from.
     * @param string $location The raw Location header value.
     * @return string Absolute URL, or '' if it cannot be resolved.
     */
    private static function resolveUrl(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return '';
        }

        // Already an absolute http(s) URL.
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return '';
        }

        // Protocol-relative: //host/path
        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'] . ':' . $location;
        }

        $authority = $baseParts['scheme'] . '://' . $baseParts['host']
            . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');

        // Root-relative: /path
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }

        // Relative path: resolve against the base path's directory.
        $basePath = (string) ($baseParts['path'] ?? '/');
        $slash    = strrpos($basePath, '/');
        $dir      = $slash === false ? '/' : substr($basePath, 0, $slash + 1);

        return $authority . $dir . $location;
    }
}
