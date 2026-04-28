<?php
namespace MediaApiWidget\Routing;

if (!defined('ABSPATH')) { exit; }

/**
 * Registers and serves the /podcast/player custom URL route.
 *
 * The route is a standalone HTML document (not a WordPress template) that
 * renders a fully-themed podcast player for a given RSS feed URL. It is
 * consumed by the [media-api-podcast-player] shortcode (embedded as an
 * iframe) and by the podcast audio lightbox in the front-end JS.
 */
final class PodcastPlayerRoute
{
    /**
     * Hooks into WordPress to activate the route.
     *
     * - init            → registers the rewrite rule each request so it
     *                     persists even if flush_rewrite_rules has not been
     *                     called since activation.
     * - query_vars      → adds the 'podcast_player' query variable so
     *                     WordPress does not strip it from the parsed query.
     * - template_redirect → intercepts the request and serves the player
     *                       template when the query var is present.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('init', [$this, 'addRewriteRule']);
        add_filter('query_vars', [$this, 'addQueryVar']);
        add_action('template_redirect', [$this, 'maybeRender']);
    }

    /**
     * Adds the rewrite rule that maps /podcast/player to the internal query.
     *
     * The rule is added at the 'top' priority so it is evaluated before
     * WordPress's default rules. Handles an optional trailing slash.
     *
     * @return void
     */
    public function addRewriteRule(): void
    {
        add_rewrite_rule('^podcast/player/?$', 'index.php?podcast_player=1', 'top');
    }

    /**
     * Appends 'podcast_player' to the list of public query variables.
     *
     * Without this, WordPress would strip the query var during request
     * parsing and {@see self::maybeRender()} would never detect the route.
     *
     * @param array<int, string> $vars Existing list of public query variables.
     * @return array<int, string> Extended list including 'podcast_player'.
     */
    public function addQueryVar(array $vars): array
    {
        $vars[] = 'podcast_player';
        return $vars;
    }

    /**
     * Renders the podcast player template when the route is matched.
     *
     * Checks for the 'podcast_player' query variable; returns early if it
     * is not set. When matched, includes the standalone player PHP template
     * and terminates the WordPress request with exit(). Calls wp_die() if
     * the template file cannot be read.
     *
     * @return void
     */
    public function maybeRender(): void
    {
        if (!get_query_var('podcast_player')) {
            return;
        }

        $file = MAW_PLUGIN_DIR . 'src/Templates/podcast-player.php';
        if (is_readable($file)) {
            include $file;
            exit;
        }

        wp_die('Podcast player template not found.');
    }
}
