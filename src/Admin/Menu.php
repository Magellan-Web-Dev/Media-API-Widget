<?php
namespace MediaApiWidget\Admin;

use MediaApiWidget\PostIndex\PostIndex;

if (!defined('ABSPATH')) { exit; }

/**
 * Registers the "Media API" top-level admin menu and all sub-pages.
 *
 * Instantiates each page class once via constructor property promotion so the
 * same instances are used for both hook registration and rendering callbacks.
 * Admin CSS and JS are enqueued only on the plugin's own pages.
 */
final class Menu
{
    /**
     * Slug for the main Settings page (also the top-level menu item).
     *
     * @var string
     */
    public const SLUG = 'maw-media-api';

    /**
     * Slug for the API Stats sub-page.
     *
     * @var string
     */
    public const STATS_SLUG = 'maw-media-api-stats';

    /**
     * Slug for the Caching settings sub-page.
     *
     * @var string
     */
    public const CACHING_SLUG = 'maw-media-api-caching';

    /**
     * Slug for the About / Help documentation sub-page.
     *
     * @var string
     */
    public const ABOUT_SLUG = 'maw-media-api-about';

    /**
     * Instantiates all four page controllers via constructor property promotion.
     *
     * Each property is declared `readonly` because page objects are created once
     * and never reassigned. The `new` expressions in the default values are a
     * PHP 8.1 feature; they are evaluated fresh for each `new Menu()` call, not
     * shared between instances.
     */
    public function __construct(
        private readonly SettingsPage $settingsPage = new SettingsPage(),
        private readonly StatsPage    $statsPage    = new StatsPage(),
        private readonly CachingPage  $cachingPage  = new CachingPage(),
        private readonly AboutPage    $aboutPage    = new AboutPage(),
    ) {}

    /**
     * Registers WordPress hooks required by the admin menu.
     *
     * - admin_menu              → {@see self::addMenu()} builds the menu structure.
     * - admin_enqueue_scripts   → {@see self::enqueueAssets()} loads CSS/JS on plugin pages.
     * - admin_init (×2)         → form POST handlers for Settings and Caching pages.
     * - admin_post_{action}     → backup JSON download endpoint for the Stats page.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        // After every entry (including core's "All Media Items") is added.
        add_action('admin_menu', [self::class, 'orderSubmenu'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this->settingsPage, 'handlePost']);
        add_action('admin_init', [$this->cachingPage, 'handlePost']);
        add_action('admin_post_' . StatsPage::DOWNLOAD_ACTION, [$this->statsPage, 'handleDownload']);
    }

    /**
     * Registers the top-level menu page and all sub-pages with WordPress.
     *
     * The top-level entry ("Media API") opens Playlist Settings, which is also
     * the first sub-page. When playlists store their items as posts, "All
     * Media Items" and one "Media API - {playlist}" view per playlist follow it
     * (see {@see self::orderSubmenu()}), then API Stats, Caching, and About /
     * Help.
     *
     * @return void
     */
    public function addMenu(): void
    {
        add_menu_page(
            'Media API',
            'Media API',
            'manage_options',
            self::SLUG,
            [$this->settingsPage, 'render'],
            'dashicons-format-video',
            80
        );

        // Added explicitly rather than left to WordPress: core only creates the
        // parent's own submenu link when the parent has no submenu yet, and
        // core's "All Media Items" entry for the stored-posts type is added
        // before this callback runs.
        add_submenu_page(
            self::SLUG,
            'Media API',
            'Playlist Settings',
            'manage_options',
            self::SLUG,
            [$this->settingsPage, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Media API Stats',
            'API Stats',
            'manage_options',
            self::STATS_SLUG,
            [$this->statsPage, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Media API Caching',
            'Caching',
            'manage_options',
            self::CACHING_SLUG,
            [$this->cachingPage, 'render']
        );

        add_submenu_page(
            self::SLUG,
            'Media API — About & Usage Guide',
            'About / Help',
            'manage_options',
            self::ABOUT_SLUG,
            [$this->aboutPage, 'render']
        );
    }

    /**
     * Puts the Media API submenu in a fixed order: Playlist Settings first
     * (the page the top-level "Media API" link opens), then All Media Items
     * and the per-playlist views, then everything else in the order added.
     *
     * Also drops the "Re-Order" page Post Types Order adds for stored media
     * items: their order is the playlist's own and is rewritten on every
     * synchronization, so a manual reorder would silently be undone.
     *
     * @return void
     */
    public static function orderSubmenu(): void
    {
        global $submenu;

        if (!is_array($submenu) || empty($submenu[self::SLUG]) || !is_array($submenu[self::SLUG])) {
            return;
        }

        $allItems = 'edit.php?post_type=' . PostIndex::POST_TYPE;
        $rank     = static function ($item) use ($allItems): int {
            $slug = is_array($item) ? (string) ($item[2] ?? '') : '';

            if ($slug === self::SLUG) {
                return 0;
            }
            if ($slug === $allItems) {
                return 1;
            }

            return str_starts_with($slug, $allItems . '&') ? 2 : 3;
        };

        $entries = [];
        foreach (array_values($submenu[self::SLUG]) as $index => $item) {
            if (is_array($item) && ($item[2] ?? '') === 'order-post-types-' . PostIndex::POST_TYPE) {
                continue;
            }
            $entries[] = [$rank($item), $index, $item];
        }
        usort($entries, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $submenu[self::SLUG] = array_column($entries, 2);
    }

    /**
     * Enqueues the plugin admin stylesheet and script on plugin pages only.
     *
     * Reads the current page slug from $_GET['page'] and bails early if the
     * request is not for one of the four plugin admin pages. This prevents
     * the assets from being loaded on unrelated wp-admin screens.
     *
     * @param string $_hook The current admin page hook suffix (not used directly;
     *                      the page slug from $_GET is used instead for reliability).
     * @return void
     */
    public function enqueueAssets(string $_hook): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if (!in_array($page, [self::SLUG, self::STATS_SLUG, self::CACHING_SLUG, self::ABOUT_SLUG], true)) {
            return;
        }

        wp_enqueue_style('maw-admin', MAW_PLUGIN_URL . 'assets/admin/admin.css', [], MAW_PLUGIN_VERSION);
        wp_enqueue_script('maw-admin', MAW_PLUGIN_URL . 'assets/admin/admin.js', [], MAW_PLUGIN_VERSION, true);
    }
}
