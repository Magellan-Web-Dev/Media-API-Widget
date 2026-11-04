<?php

namespace MediaApiWidget\PostIndex;

use MediaApiWidget\Admin\Menu;
use MediaApiWidget\Config\Options;

if (!defined('ABSPATH')) { exit; }

/**
 * wp-admin surfaces for stored playlist posts.
 *
 * - A "Media API - {playlist_name}" view per playlist in the Media API menu,
 *   beside All Media Items: the standard posts list filtered to that
 *   playlist, in playlist order.
 * - Playlist and media type filters, and Image / Season / Episode / Position
 *   columns, on the media items list.
 * - A read-only edit screen showing every synchronized field.
 * - The Resync and Delete stored posts actions, gated on `manage_options` and a
 *   per-playlist nonce.
 * - The per-playlist status panel the Settings page shows.
 */
final class PostIndexAdmin
{
    /** admin-post.php action for a manual resync. */
    public const ACTION_RESYNC = 'maw_post_index_resync';

    /** admin-post.php action for the explicit "Delete stored posts" cleanup. */
    public const ACTION_CLEANUP = 'maw_post_index_cleanup';

    /** Capability required for both actions. */
    public const CAPABILITY = 'manage_options';

    /** Query argument the result notice is passed back in. */
    public const NOTICE_ARG = 'maw_post_index';

    /**
     * Hooks the admin surfaces.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPlaylistViews'], 20);
        add_filter('parent_file', [$this, 'menuParent']);
        add_filter('submenu_file', [$this, 'highlightPlaylistView'], 10, 2);
        add_action('load-edit.php', [$this, 'titleForPlaylistView']);
        add_action('restrict_manage_posts', [$this, 'renderListFilters'], 10, 2);
        add_filter('manage_' . PostIndex::POST_TYPE . '_posts_columns', [$this, 'addColumns']);
        add_action('manage_' . PostIndex::POST_TYPE . '_posts_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('manage_edit-' . PostIndex::POST_TYPE . '_sortable_columns', [$this, 'sortableColumns']);
        add_action('pre_get_posts', [$this, 'orderAdminList']);
        add_action('admin_post_' . self::ACTION_RESYNC, [$this, 'handleResync']);
        add_action('admin_post_' . self::ACTION_CLEANUP, [$this, 'handleCleanup']);

        // Our list cells are ours alone (see claimOwnCell()).
        add_action('manage_posts_custom_column', [$this, 'claimOwnCell'], 0, 2);

        // Read-only edit screen.
        add_filter('use_block_editor_for_post_type', [$this, 'useClassicScreen'], 10, 2);
        add_action('load-post.php', [$this, 'prepareEditScreen']);
        add_action('add_meta_boxes_' . PostIndex::POST_TYPE, [$this, 'addDetailsBox']);
        add_filter('post_row_actions', [$this, 'rowActions'], 10, 2);
        add_filter('wp_insert_post_data', [$this, 'protectSyncedFields'], 10, 2);
    }

    /** Custom list columns this class renders. */
    private const COLUMNS = ['maw_image', 'maw_season', 'maw_episode', 'maw_position'];

    /** @var bool Whether a cell's output buffer is open (see claimOwnCell()). */
    private bool $cellBuffer = false;

    // ---------------------------------------------------------------------
    // Per-playlist views
    // ---------------------------------------------------------------------

    /**
     * Lists the playlists that get a "Media API - {playlist}" view: every
     * playlist storing posts, plus any playlist whose posts were retained.
     *
     * @return array<int,string> Playlist slugs.
     */
    public static function viewPlaylists(): array
    {
        $playlists = array_map(static fn (array $pair): string => $pair[0], Options::postStoragePlaylists());

        if (taxonomy_exists(PostIndex::TAX_PLAYLIST) && function_exists('get_terms')) {
            $terms = get_terms(['taxonomy' => PostIndex::TAX_PLAYLIST, 'hide_empty' => true, 'fields' => 'slugs']);
            if (is_array($terms)) {
                $playlists = array_merge($playlists, array_map('strval', $terms));
            }
        }

        $playlists = array_values(array_unique(array_filter(array_map('sanitize_key', $playlists))));
        sort($playlists);

        return $playlists;
    }

    /**
     * Returns the admin URL of a playlist's view.
     *
     * @param string $playlistName Playlist slug.
     * @return string Relative admin URL (menu slug form).
     */
    public static function viewSlug(string $playlistName): string
    {
        return 'edit.php?post_type=' . PostIndex::POST_TYPE . '&' . PostIndex::TAX_PLAYLIST . '=' . sanitize_key($playlistName);
    }

    /**
     * Adds a "Media API - {playlist_name}" view per playlist to the Media API
     * menu, beside "All Media Items" (which WordPress adds for the post type).
     * {@see Menu::orderSubmenu()} places them directly under Playlist Settings.
     *
     * @return void
     */
    public function addPlaylistViews(): void
    {
        if (!PostIndex::isRegistered() || !PostIndex::shouldShowMenu()) {
            return;
        }

        foreach (self::viewPlaylists() as $playlist) {
            // The hyphen is an entity because menu labels are texturized, which
            // would otherwise turn " - " into an en dash.
            add_submenu_page(Menu::SLUG, 'Media API - ' . $playlist, 'Media API &#45; ' . esc_html($playlist), 'edit_posts', self::viewSlug($playlist));
        }
    }

    /**
     * Keeps the Media API menu open on media item screens.
     *
     * The list screen otherwise names the post type's own (non-existent)
     * top-level menu as its parent, which leaves every menu closed.
     *
     * @param string|null $parentFile Current parent file.
     * @return string|null Parent file.
     */
    public function menuParent($parentFile)
    {
        return self::onMediaItemScreen() ? Menu::SLUG : $parentFile;
    }

    /**
     * Highlights the entry for what is on screen: the playlist's own view for
     * a filtered list or one of its items, otherwise All Media Items.
     *
     * @param string|null $submenuFile Current submenu file.
     * @param string      $parentFile  Current parent file.
     * @return string|null Submenu file.
     */
    public function highlightPlaylistView($submenuFile, $parentFile = '')
    {
        if (!self::onMediaItemScreen()) {
            return $submenuFile;
        }

        $playlist = self::currentListPlaylist();

        if ($playlist === '') {
            $postId = self::editScreenPostId();
            if ($postId > 0) {
                $playlist = sanitize_key((string) get_post_meta($postId, PostIndex::META_PLAYLIST_NAME, true));
            }
        }

        return $playlist !== '' && in_array($playlist, self::viewPlaylists(), true)
            ? self::viewSlug($playlist)
            : 'edit.php?post_type=' . PostIndex::POST_TYPE;
    }

    /**
     * @return bool Whether the current admin screen lists or edits media items.
     */
    private static function onMediaItemScreen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return is_object($screen)
            && ($screen->post_type ?? '') === PostIndex::POST_TYPE
            && in_array($screen->base ?? '', ['edit', 'post'], true);
    }

    /**
     * Titles a filtered list "Media API - {playlist_name}".
     *
     * @return void
     */
    public function titleForPlaylistView(): void
    {
        $playlist = self::currentListPlaylist();
        $object   = $playlist !== '' ? get_post_type_object(PostIndex::POST_TYPE) : null;

        if ($object !== null && isset($object->labels)) {
            $object->labels->name = 'Media API - ' . $playlist;
        }
    }

    /**
     * Returns the playlist the current Media API Items list is filtered to.
     *
     * @return string Playlist slug, or ''.
     */
    private static function currentListPlaylist(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
        if (($_GET['post_type'] ?? '') !== PostIndex::POST_TYPE) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
        return sanitize_key((string) wp_unslash($_GET[PostIndex::TAX_PLAYLIST] ?? ''));
    }

    // ---------------------------------------------------------------------
    // List table
    // ---------------------------------------------------------------------

    /**
     * Adds Playlist and Media type filters above the list.
     *
     * @param string $postType Post type of the list.
     * @param string $which    'top' or 'bottom'.
     * @return void
     */
    public function renderListFilters($postType, $which = 'top'): void
    {
        if ($postType !== PostIndex::POST_TYPE || $which !== 'top') {
            return;
        }

        foreach ([PostIndex::TAX_PLAYLIST => 'All playlists', PostIndex::TAX_MEDIA_TYPE => 'All media types'] as $taxonomy => $all) {
            wp_dropdown_categories([
                'taxonomy'        => $taxonomy,
                'name'            => $taxonomy,
                'value_field'     => 'slug',
                'show_option_all' => $all,
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
                'selected'        => sanitize_key((string) wp_unslash($_GET[$taxonomy] ?? '')),
                'hide_empty'      => false,
                'hierarchical'    => false,
                'orderby'         => 'name',
            ]);
        }
    }

    /**
     * @param array<string,string> $columns List columns.
     * @return array<string,string> Columns with Image first and the numbers after the title.
     */
    public function addColumns(array $columns): array
    {
        $out = [];

        foreach ($columns as $key => $label) {
            if ($key === 'title') {
                $out['maw_image'] = 'Image';
            }

            $out[$key] = $label;

            if ($key === 'title') {
                $out['maw_season']   = 'Season';
                $out['maw_episode']  = 'Episode';
                $out['maw_position'] = 'Position';
            }
        }

        return $out;
    }

    /**
     * @param string $column Column key.
     * @param int    $postId Post ID.
     * @return void
     */
    public function renderColumn($column, $postId): void
    {
        if ($this->cellBuffer) {
            // Discard anything other code printed into this cell.
            ob_end_clean();
            $this->cellBuffer = false;
        }

        switch ($column) {
            case 'maw_image':
                $url = (string) get_post_meta((int) $postId, PostIndex::META_THUMBNAIL_URL, true);
                if ($url !== '') {
                    echo '<img src="' . esc_url($url) . '" alt="" width="96" style="height:auto;max-width:96px" loading="lazy" referrerpolicy="no-referrer">';
                }
                break;
            case 'maw_season':
                echo esc_html((string) get_post_meta((int) $postId, PostIndex::META_SEASON, true));
                break;
            case 'maw_episode':
                echo esc_html((string) get_post_meta((int) $postId, PostIndex::META_EPISODE, true));
                break;
            case 'maw_position':
                $post = get_post((int) $postId);
                echo esc_html($post !== null ? (string) ((int) $post->menu_order + 1) : '');
                break;
        }
    }

    /**
     * Starts buffering one of this plugin's cells before generic handlers run.
     *
     * The generic `manage_posts_custom_column` action fires for every post
     * type before the type-specific one, and a handler that prints into
     * columns it does not own (a site snippet echoing "-" for every column, for
     * instance) would otherwise prefix these values. Anything printed into
     * these cells before {@see self::renderColumn()} runs is discarded there.
     *
     * @param string $column Column key.
     * @param int    $postId Post ID.
     * @return void
     */
    public function claimOwnCell($column, $postId): void
    {
        if (!$this->cellBuffer && in_array($column, self::COLUMNS, true) && get_post_type((int) $postId) === PostIndex::POST_TYPE) {
            ob_start();
            $this->cellBuffer = true;
        }
    }

    /**
     * @param array<string,string> $columns Sortable columns.
     * @return array<string,string> With Position and Episode.
     */
    public function sortableColumns(array $columns): array
    {
        $columns['maw_position'] = 'menu_order';
        $columns['maw_episode']  = 'maw_episode';

        return $columns;
    }

    /**
     * Orders the admin list: playlist order by default, episode on request.
     *
     * @param object $query The main admin WP_Query.
     * @return void
     */
    public function orderAdminList($query): void
    {
        if (!is_admin() || !is_object($query) || !method_exists($query, 'is_main_query') || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== PostIndex::POST_TYPE) {
            return;
        }

        $orderby = (string) $query->get('orderby');

        if ($orderby === 'maw_episode') {
            $query->set('meta_key', PostIndex::META_EPISODE);
            $query->set('orderby', 'meta_value_num');
        } elseif ($orderby === '') {
            $query->set('orderby', ['menu_order' => 'ASC', 'date' => 'DESC']);
        }
    }

    // ---------------------------------------------------------------------
    // Read-only edit screen
    // ---------------------------------------------------------------------

    /**
     * Uses the classic edit screen for media items, where every synchronized
     * field can be shown read-only in one place.
     *
     * @param bool   $useBlockEditor Whether to use the block editor.
     * @param string $postType       Post type.
     * @return bool
     */
    public function useClassicScreen($useBlockEditor, $postType)
    {
        return $postType === PostIndex::POST_TYPE ? false : $useBlockEditor;
    }

    /**
     * Strips the editable inputs from a media item's edit screen.
     *
     * Only for this request: the post type keeps its supports everywhere else
     * (REST, builders). The title stays visible but read-only; the body,
     * excerpt, custom fields, slug, author and term boxes give way to the
     * read-only details box. Publishing status stays editable, so an item can
     * still be unpublished to hide it from loops.
     *
     * @return void
     */
    public function prepareEditScreen(): void
    {
        if (self::editScreenPostType() !== PostIndex::POST_TYPE) {
            return;
        }

        foreach (['editor', 'excerpt', 'custom-fields', 'author'] as $support) {
            remove_post_type_support(PostIndex::POST_TYPE, $support);
        }

        add_action('admin_print_footer_scripts', [$this, 'printReadOnlyTitleScript']);
    }

    /**
     * Adds the details box and removes the term and slug boxes.
     *
     * @return void
     */
    public function addDetailsBox(): void
    {
        foreach (['slugdiv', 'tagsdiv-' . PostIndex::TAX_PLAYLIST, 'tagsdiv-' . PostIndex::TAX_MEDIA_TYPE, 'postcustom', 'postexcerpt'] as $box) {
            remove_meta_box($box, PostIndex::POST_TYPE, 'normal');
            remove_meta_box($box, PostIndex::POST_TYPE, 'side');
        }

        add_meta_box(
            'maw_media_item_details',
            'Media item details (synchronized — read only)',
            [$this, 'renderDetailsBox'],
            PostIndex::POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * Renders every field of a media item, read-only.
     *
     * @param object $post The post being edited.
     * @return void
     */
    public function renderDetailsBox($post): void
    {
        $id     = (int) $post->ID;
        $meta   = static fn (string $key): string => (string) get_post_meta($id, $key, true);
        $labels = PostIndex::fieldLabels();
        $image  = $meta(PostIndex::META_THUMBNAIL_URL);

        $rows = [
            ['Title', 'post_title', esc_html((string) $post->post_title)],
            ['Description', 'post_excerpt', nl2br(esc_html((string) $post->post_excerpt))],
            ['ID (YouTube video ID / podcast episode ID)', PostIndex::META_SOURCE_ID, esc_html($meta(PostIndex::META_SOURCE_ID))],
            [$labels[PostIndex::META_THUMBNAIL_URL][0], PostIndex::META_THUMBNAIL_URL, $image !== ''
                ? '<img src="' . esc_url($image) . '" alt="" style="max-width:320px;height:auto;display:block;margin-bottom:4px" referrerpolicy="no-referrer"><code>' . esc_html($image) . '</code>'
                : ''],
            [$labels[PostIndex::META_SEASON][0], PostIndex::META_SEASON, esc_html($meta(PostIndex::META_SEASON))],
            [$labels[PostIndex::META_EPISODE][0], PostIndex::META_EPISODE, esc_html($meta(PostIndex::META_EPISODE))],
            ['Season & episode label', PostFields::EPISODE_LABEL, esc_html(PostFields::value($id, PostFields::EPISODE_LABEL))],
            [$labels[PostIndex::META_PLAYLIST_NAME][0], PostIndex::META_PLAYLIST_NAME, esc_html($meta(PostIndex::META_PLAYLIST_NAME))],
            [$labels[PostIndex::META_MEDIA_TYPE][0], PostIndex::META_MEDIA_TYPE, esc_html($meta(PostIndex::META_MEDIA_TYPE))],
            ['Playlist position', PostIndex::META_PLAYLIST_POSITION, esc_html(PostFields::value($id, PostFields::POSITION_NUMBER)) . ' <span class="description">(stored 0-based: ' . esc_html($meta(PostIndex::META_PLAYLIST_POSITION)) . ')</span>'],
            [$labels[PostIndex::META_PUBLISHED_DATE][0], PostIndex::META_PUBLISHED_DATE, esc_html($meta(PostIndex::META_PUBLISHED_DATE))],
            [$labels[PostIndex::META_MEDIA_URL][0], PostIndex::META_MEDIA_URL, self::link($meta(PostIndex::META_MEDIA_URL))],
            [$labels[PostIndex::META_AUDIO_URL][0], PostIndex::META_AUDIO_URL, self::link($meta(PostIndex::META_AUDIO_URL))],
            [$labels[PostIndex::META_YOUTUBE_ID][0], PostIndex::META_YOUTUBE_ID, esc_html($meta(PostIndex::META_YOUTUBE_ID))],
            [$labels[PostIndex::META_PODCAST_GUID][0], PostIndex::META_PODCAST_GUID, esc_html($meta(PostIndex::META_PODCAST_GUID))],
            [$labels[PostIndex::META_TRANSCRIPT_STATUS][0], PostIndex::META_TRANSCRIPT_STATUS, esc_html($meta(PostIndex::META_TRANSCRIPT_STATUS))],
            [$labels[PostIndex::META_TRANSCRIPT_LANGUAGE][0], PostIndex::META_TRANSCRIPT_LANGUAGE, esc_html($meta(PostIndex::META_TRANSCRIPT_LANGUAGE))],
            [$labels[PostIndex::META_TRANSCRIPT_URL][0], PostIndex::META_TRANSCRIPT_URL, self::link($meta(PostIndex::META_TRANSCRIPT_URL))],
            ['Transcript', 'post_content', (string) $post->post_content !== ''
                ? '<textarea readonly rows="6" style="width:100%">' . esc_textarea((string) $post->post_content) . '</textarea>'
                : ''],
            ['Last synchronized', PostIndex::META_LAST_SEEN, $meta(PostIndex::META_LAST_SEEN) !== ''
                ? esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), (int) $meta(PostIndex::META_LAST_SEEN)))
                : ''],
        ];
        ?>
        <p class="description">These values come from the playlist and are updated automatically on every synchronization, so they cannot be edited here. To hide this item from loops, change its status (for example to Draft) — synchronization never republishes it. The key beside each field is what builders and code read (post meta, or the post field).</p>
        <table class="form-table maw-media-item-details" role="presentation"><tbody>
            <?php foreach ($rows as [$label, $key, $html]) : ?>
                <tr>
                    <th scope="row"><?= esc_html($label) ?><br><code style="font-weight:400"><?= esc_html($key) ?></code></th>
                    <td><?= $html !== '' ? $html : '<span class="description">— none —</span>' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    /**
     * Makes the title input read-only.
     *
     * @return void
     */
    public function printReadOnlyTitleScript(): void
    {
        ?>
        <style>#titlediv #title[readonly]{background:#f6f7f7;color:#1d2327;cursor:default}#titlediv #edit-slug-box{display:none}</style>
        <script>(function(){var t=document.getElementById('title');if(t){t.readOnly=true;t.setAttribute('aria-readonly','true');t.title='Synchronized from the playlist (read only)';}})();</script>
        <?php
    }

    /**
     * Removes Quick Edit (which edits the title and terms) from media items.
     *
     * @param array<string,string> $actions Row actions.
     * @param object               $post    The post.
     * @return array<string,string>
     */
    public function rowActions($actions, $post)
    {
        if (is_array($actions) && is_object($post) && ($post->post_type ?? '') === PostIndex::POST_TYPE) {
            unset($actions['inline hide-if-no-js']);
            if (isset($actions['edit'])) {
                $actions['edit'] = str_replace('>Edit<', '>View details<', $actions['edit']);
            }
        }

        return $actions;
    }

    /**
     * Keeps synchronized post fields unchanged when the edit screen is saved.
     *
     * The edit screen shows them read-only, and this enforces it for a forged
     * form or Quick Edit request: title, description, transcript and slug keep
     * their stored values. Status (and the date) remain editorial. Saves made
     * outside the edit screen — synchronization itself, REST, code — are not
     * affected.
     *
     * @param array<string,mixed> $data    Slashed post data about to be saved.
     * @param array<string,mixed> $postarr Raw post array.
     * @return array<string,mixed>
     */
    public function protectSyncedFields($data, $postarr)
    {
        if (!is_array($data) || ($data['post_type'] ?? '') !== PostIndex::POST_TYPE || !is_admin()) {
            return $data;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verified the save; this only narrows what it writes.
        $action = isset($_POST['action']) ? sanitize_key((string) wp_unslash($_POST['action'])) : '';
        if (!in_array($action, ['editpost', 'inline-save'], true)) {
            return $data;
        }

        $existing = !empty($postarr['ID']) ? get_post((int) $postarr['ID']) : null;
        if ($existing === null) {
            return $data;
        }

        foreach (['post_title', 'post_excerpt', 'post_content', 'post_name'] as $field) {
            $data[$field] = wp_slash((string) $existing->{$field});
        }

        return $data;
    }

    /**
     * @return string Post type of the post being edited on post.php, or ''.
     */
    private static function editScreenPostType(): string
    {
        $postId = self::editScreenPostId();

        return $postId > 0 ? (string) get_post_type($postId) : '';
    }

    /**
     * @return int ID of the post being edited on post.php, or 0.
     */
    private static function editScreenPostId(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- read-only screen detection.
        return isset($_GET['post']) ? (int) $_GET['post'] : (isset($_POST['post_ID']) ? (int) $_POST['post_ID'] : 0);
    }

    /**
     * @param string $url URL.
     * @return string Escaped link, or ''.
     */
    private static function link(string $url): string
    {
        return $url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a>' : '';
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    /**
     * Returns the nonce action for one playlist action.
     *
     * @param string $action       ACTION_* constant.
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return string Nonce action.
     */
    public static function nonceAction(string $action, string $playlistName, string $mediaType): string
    {
        return $action . '_' . sanitize_key($mediaType) . '_' . sanitize_key($playlistName);
    }

    /**
     * Returns the admin-post URL for one playlist action, nonce included.
     *
     * @param string $action       ACTION_* constant.
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return string URL.
     */
    public static function actionUrl(string $action, string $playlistName, string $mediaType): string
    {
        return add_query_arg([
            'action'   => $action,
            'playlist' => sanitize_key($playlistName),
            'type'     => sanitize_key($mediaType),
            '_wpnonce' => wp_create_nonce(self::nonceAction($action, $playlistName, $mediaType)),
        ], admin_url('admin-post.php'));
    }

    /**
     * Validates and performs one action. Separated from the request handlers
     * so permission and nonce checks are testable without a redirect.
     *
     * @param string               $action  ACTION_* constant.
     * @param array<string,mixed>  $request Request fields: playlist, type, _wpnonce.
     * @return array{ok:bool,code:string,message:string}
     */
    public static function process(string $action, array $request): array
    {
        if (!current_user_can(self::CAPABILITY)) {
            return ['ok' => false, 'code' => 'forbidden', 'message' => 'You do not have permission to manage stored playlist posts.'];
        }

        $playlist = sanitize_key((string) ($request['playlist'] ?? ''));
        $type     = sanitize_key((string) ($request['type'] ?? ''));
        $nonce    = (string) ($request['_wpnonce'] ?? '');

        if ($playlist === '' || !in_array($type, ['youtube', 'podcast'], true)) {
            return ['ok' => false, 'code' => 'invalid', 'message' => 'Unknown playlist.'];
        }

        if (!wp_verify_nonce($nonce, self::nonceAction($action, $playlist, $type))) {
            return ['ok' => false, 'code' => 'nonce', 'message' => 'The link has expired. Reload the page and try again.'];
        }

        if ($action === self::ACTION_RESYNC) {
            $queued = PostIndexSync::requestResync($playlist, $type);

            return is_wp_error($queued)
                ? ['ok' => false, 'code' => $queued->get_error_code(), 'message' => $queued->get_error_message()]
                : ['ok' => true, 'code' => 'resync', 'message' => sprintf('Resync of "%s" queued. It runs in the background.', $playlist)];
        }

        if ($action === self::ACTION_CLEANUP) {
            $removed = PostIndexSync::deleteStoredPosts($playlist, $type, 500);

            if (is_wp_error($removed)) {
                return ['ok' => false, 'code' => $removed->get_error_code(), 'message' => $removed->get_error_message()];
            }

            $message = sprintf('Deleted %d stored posts for "%s".', $removed['deleted'], $playlist);
            if ($removed['remaining'] > 0) {
                $message .= sprintf(' %d remain; run the action again to continue.', $removed['remaining']);
            }
            if (Options::isPostStorageEnabled($playlist, $type)) {
                $message .= ' Post storage is still on, so the next synchronization recreates them.';
            }

            return ['ok' => $removed['failed'] === 0, 'code' => 'cleanup', 'message' => $message];
        }

        return ['ok' => false, 'code' => 'invalid', 'message' => 'Unknown action.'];
    }

    /**
     * admin-post handler: manual resync.
     *
     * @return void
     */
    public function handleResync(): void
    {
        $this->respond(self::process(self::ACTION_RESYNC, wp_unslash($_GET)));
    }

    /**
     * admin-post handler: delete stored posts.
     *
     * @return void
     */
    public function handleCleanup(): void
    {
        $this->respond(self::process(self::ACTION_CLEANUP, wp_unslash($_GET)));
    }

    /**
     * Sends the result back to the Settings page as a notice.
     *
     * @param array{ok:bool,code:string,message:string} $result Action result.
     * @return void
     */
    private function respond(array $result): void
    {
        if ($result['code'] === 'forbidden') {
            wp_die(esc_html($result['message']), 403);
        }

        set_transient('maw_post_index_notice_' . get_current_user_id(), $result, 60);
        wp_safe_redirect(add_query_arg(self::NOTICE_ARG, '1', menu_page_url(Menu::SLUG, false)));
        exit;
    }

    /**
     * Renders (and consumes) the notice left by the last action.
     *
     * @return void
     */
    public static function renderNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        if (empty($_GET[self::NOTICE_ARG])) {
            return;
        }

        $key    = 'maw_post_index_notice_' . get_current_user_id();
        $result = get_transient($key);
        delete_transient($key);

        if (is_array($result) && isset($result['message'])) {
            $class = !empty($result['ok']) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html((string) $result['message']) . '</p></div>';
        }
    }

    // ---------------------------------------------------------------------
    // Status panel
    // ---------------------------------------------------------------------

    /**
     * Renders a playlist's post storage status on the Settings page.
     *
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return void
     */
    public static function renderStatus(string $playlistName, string $mediaType): void
    {
        $status  = SyncStatus::get($playlistName, $mediaType);
        $count   = PostIndexSync::countStoredPosts($playlistName, $mediaType);
        $enabled = Options::isPostStorageEnabled($playlistName, $mediaType);

        if (!$enabled && $count === 0 && $status['state'] === SyncStatus::STATE_IDLE) {
            return;
        }

        $labels = [
            SyncStatus::STATE_IDLE     => 'Not synchronized yet',
            SyncStatus::STATE_PENDING  => 'Pending',
            SyncStatus::STATE_RUNNING  => 'Running',
            SyncStatus::STATE_COMPLETE => 'Complete',
            SyncStatus::STATE_ERROR    => 'Error',
            SyncStatus::STATE_DISABLED => 'Stopped',
        ];

        $state       = (string) $status['state'];
        $lastSuccess = (int) $status['last_success_at'];
        $note        = SyncStatus::pruneNotes()[(string) $status['prune_note']] ?? '';
        ?>
        <div class="maw-post-storage-status" data-maw-post-status="<?= esc_attr($state) ?>">
            <p><strong>Post storage:</strong>
                <span class="maw-status-badge maw-status-<?= esc_attr($state) ?>"><?= esc_html($labels[$state] ?? $state) ?></span>
                &nbsp;<?= esc_html((string) $count) ?> stored posts
                &nbsp;·&nbsp; Last successful sync: <?= esc_html($lastSuccess > 0 ? wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $lastSuccess) : 'never') ?>
            </p>
            <?php if ((string) $status['message'] !== '') : ?>
                <p class="description"><?= esc_html((string) $status['message']) ?></p>
            <?php endif; ?>
            <?php if ($note !== '') : ?>
                <p class="description"><?= esc_html($note) ?></p>
            <?php endif; ?>
            <p class="maw-actions">
                <?php if ($enabled) : ?>
                    <a class="button button-secondary" href="<?= esc_url(self::actionUrl(self::ACTION_RESYNC, $playlistName, $mediaType)) ?>">Resync now</a>
                <?php endif; ?>
                <?php if ($count > 0 || PostIndex::shouldShowMenu()) : ?>
                    <a class="button button-secondary" href="<?= esc_url(admin_url(self::viewSlug($playlistName))) ?>">View posts (Media API - <?= esc_html($playlistName) ?>)</a>
                <?php endif; ?>
                <?php if ($count > 0) : ?>
                    <a class="button button-link-delete" href="<?= esc_url(self::actionUrl(self::ACTION_CLEANUP, $playlistName, $mediaType)) ?>" data-maw-confirm="Permanently delete the <?= esc_attr((string) $count) ?> stored posts for &quot;<?= esc_attr($playlistName) ?>&quot;? Transcripts and edits on them are lost.">Delete stored posts</a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}
