<?php

namespace MediaApiWidget\PostIndex;

use MediaApiWidget\Support\MediaStore;

if (!defined('ABSPATH')) { exit; }

/**
 * Registers the media post index content model.
 *
 * The plugin's canonical media store is a WordPress transient plus a local
 * backup JSON file. That is fast and self-contained, but it is invisible to
 * everything outside this plugin: no WP_Query, no page builder, no query block.
 * This class declares the parallel content model that makes the same data
 * queryable — one `maw_media_item` post per YouTube video or podcast episode,
 * classified by two taxonomies and described by ordinary post meta.
 *
 * The posts are a *projection*, never the source of truth. They are rebuilt
 * from stored data by {@see PostIndexSync}, and deleting them all costs nothing
 * but a resync. Nothing in the media pipeline reads them.
 *
 * Visibility is deliberately "index-only, builder-discoverable":
 *
 * - `public => true` because Elementor, Divi, and Bricks all enumerate loop
 *   sources with `get_post_types(['public' => true])`. Registering privately
 *   would satisfy the letter of "index" while making the feature unusable.
 * - `publicly_queryable => false` because the index needs no front-end URL of
 *   its own. This is the flag `is_post_type_viewable()` consults, so it removes
 *   the admin "View" link, the REST link preview, and URL-driven access, while
 *   leaving explicit WP_Query/get_posts calls entirely functional.
 * - `rewrite => false` and `has_archive => false` so the type contributes no
 *   rewrite rules. That keeps it clear of the plugin's own /podcast/player
 *   route and means nothing depends on rewrite rules being flushed at the right
 *   moment during activation.
 *
 * Both argument arrays pass through filters so a site whose builder cannot see
 * the type can adjust one flag without forking the plugin.
 *
 * All registration is idempotent, so calling {@see self::registerTypes()} from
 * both `init` and plugin activation is safe.
 */
final class PostIndex
{
    /**
     * Post type holding one indexed media item. 14 characters, inside
     * WordPress's 20-character limit.
     *
     * @var string
     */
    public const POST_TYPE = 'maw_media_item';

    /**
     * Taxonomy whose terms are playlist_name slugs.
     *
     * @var string
     */
    public const TAX_PLAYLIST = 'maw_playlist';

    /**
     * Taxonomy whose terms are 'youtube' and 'podcast'.
     *
     * @var string
     */
    public const TAX_MEDIA_TYPE = 'maw_media_type';

    /**
     * Filter altering the post type registration arguments.
     *
     * @var string
     */
    public const FILTER_POST_TYPE_ARGS = 'media_api_widget_post_type_args';

    /**
     * Filter altering a taxonomy's registration arguments.
     *
     * @var string
     */
    public const FILTER_TAXONOMY_ARGS = 'media_api_widget_taxonomy_args';

    /**
     * Internal identity key. Underscore-prefixed so core treats it as
     * protected: hidden from the Custom Fields UI and never REST-exposed.
     *
     * @var string
     */
    public const META_SOURCE_KEY = '_maw_source_key';

    /**
     * Internal trimmed copy of the source item, for debugging and rebuilds.
     *
     * @var string
     */
    public const META_SOURCE_PAYLOAD = '_maw_source_payload';

    /** @var string Playlist slug this item belongs to. */
    public const META_PLAYLIST_NAME = 'maw_playlist_name';

    /** @var string 'youtube' or 'podcast'. */
    public const META_MEDIA_TYPE = 'maw_media_type';

    /** @var string Provider-side identifier, as supplied. */
    public const META_SOURCE_ID = 'maw_source_id';

    /** @var string YouTube video id; empty for podcast items. */
    public const META_YOUTUBE_ID = 'maw_youtube_id';

    /** @var string Podcast episode GUID; empty for YouTube items. */
    public const META_PODCAST_GUID = 'maw_podcast_guid';

    /** @var string Remote thumbnail URL. Never sideloaded into the library. */
    public const META_THUMBNAIL_URL = 'maw_thumbnail_url';

    /** @var string Publication date, ISO-8601 when parseable. */
    public const META_PUBLISHED_DATE = 'maw_published_date';

    /** @var string Episode number. Absent when the item is unnumbered. */
    public const META_EPISODE = 'maw_episode';

    /** @var string Season number. Absent unless season mode produced one. */
    public const META_SEASON = 'maw_season';

    /** @var string Zero-based position within the stored playlist. */
    public const META_PLAYLIST_POSITION = 'maw_playlist_position';

    /** @var string Transcript workflow status, set by an integration. */
    public const META_TRANSCRIPT_STATUS = 'maw_transcript_status';

    /** @var string URL of a transcript held outside WordPress. */
    public const META_TRANSCRIPT_URL = 'maw_transcript_url';

    /** @var string BCP-47 language tag of the transcript. */
    public const META_TRANSCRIPT_LANGUAGE = 'maw_transcript_language';

    /** @var string GMT timestamp of the last sync that saw this item. */
    public const META_LAST_SEEN = 'maw_last_seen';

    /**
     * Option holding the index schema version.
     *
     * Deliberately separate from MAW_PLUGIN_VERSION: the index is rebuilt when
     * its own shape changes, not on every plugin release.
     *
     * @var string
     */
    public const OPTION_SCHEMA_VERSION = 'maw_media_post_index_schema';

    /**
     * Current index schema version. Bump only when a rebuild is required.
     *
     * @var int
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Hooks type registration into WordPress.
     *
     * Priority 9 puts the post type and its taxonomies in place before the
     * default priority 10, where other plugin subsystems (and third-party code)
     * may already want to query them.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('init', [self::class, 'registerTypes'], 9);
    }

    /**
     * Registers the post type, taxonomies, and public meta.
     *
     * Idempotent: returns immediately when the post type already exists, so it
     * is safe to call from `init` and from plugin activation both.
     *
     * @return void
     */
    public static function registerTypes(): void
    {
        if (post_type_exists(self::POST_TYPE)) {
            return;
        }

        self::registerPostType();
        self::registerTaxonomies();
        self::registerMeta();
    }

    /**
     * Reports whether the full content model is available.
     *
     * Callers that write posts check this first: inserting into an unregistered
     * post type produces rows nothing can query and no admin screen can show.
     *
     * @return bool True when the post type and both taxonomies exist.
     */
    public static function isRegistered(): bool
    {
        return post_type_exists(self::POST_TYPE)
            && taxonomy_exists(self::TAX_PLAYLIST)
            && taxonomy_exists(self::TAX_MEDIA_TYPE);
    }

    /**
     * Returns every public meta key this index writes.
     *
     * @return array<int,string> Meta keys, without the internal underscore ones.
     */
    public static function publicMetaKeys(): array
    {
        return [
            self::META_PLAYLIST_NAME,
            self::META_MEDIA_TYPE,
            self::META_SOURCE_ID,
            self::META_YOUTUBE_ID,
            self::META_PODCAST_GUID,
            self::META_THUMBNAIL_URL,
            self::META_PUBLISHED_DATE,
            self::META_EPISODE,
            self::META_SEASON,
            self::META_PLAYLIST_POSITION,
            self::META_TRANSCRIPT_STATUS,
            self::META_TRANSCRIPT_URL,
            self::META_TRANSCRIPT_LANGUAGE,
            self::META_LAST_SEEN,
        ];
    }

    /**
     * Registers the maw_media_item post type.
     *
     * @return void
     */
    private static function registerPostType(): void
    {
        $args = [
            'labels' => [
                'name'               => 'Media Items',
                'singular_name'      => 'Media Item',
                'menu_name'          => 'Media Items',
                'all_items'          => 'All Media Items',
                'edit_item'          => 'Edit Media Item',
                'view_item'          => 'View Media Item',
                'search_items'       => 'Search Media Items',
                'not_found'          => 'No media items found.',
                'not_found_in_trash' => 'No media items found in Trash.',
            ],
            'description' => 'Queryable index of YouTube videos and podcast episodes synced by Media API Widget.',

            // Discoverable by page builders, which enumerate public types...
            'public'              => true,
            // ...but with no front-end URL, archive, or rewrite rule of its own.
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'rewrite'             => false,
            'has_archive'         => false,
            'query_var'           => false,

            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_admin_bar' => false,
            // An item with no viewable URL has no business in a nav menu. This
            // does not affect builder loop pickers, which key off 'public'.
            'show_in_nav_menus' => false,
            'menu_position'     => 25,
            'menu_icon'         => 'dashicons-playlist-video',

            'show_in_rest'   => true,
            'rest_base'      => 'maw-media-items',

            // 'editor' carries the transcript; 'custom-fields' is what exposes
            // the meta below to the block editor and to builder field pickers.
            'supports'   => ['title', 'editor', 'excerpt', 'custom-fields', 'author'],
            'taxonomies' => [self::TAX_PLAYLIST, self::TAX_MEDIA_TYPE],

            'hierarchical'     => false,
            'capability_type'  => 'post',
            'map_meta_cap'     => true,
            'can_export'       => true,
            'delete_with_user' => false,
        ];

        /**
         * Filters the media item post type registration arguments.
         *
         * Provided because the default visibility flags are chosen to satisfy
         * page builders that cannot be tested from inside this plugin. A site
         * whose builder does not discover the type can adjust a flag here
         * instead of forking.
         *
         * @param array<string,mixed> $args     Arguments for register_post_type().
         * @param string              $postType The post type being registered.
         */
        $args = apply_filters(self::FILTER_POST_TYPE_ARGS, $args, self::POST_TYPE);

        register_post_type(self::POST_TYPE, is_array($args) ? $args : []);
    }

    /**
     * Registers the playlist and media type taxonomies.
     *
     * Both are flat: a playlist has no parent playlist, and 'youtube' is not a
     * child of anything. Flat terms are also what builder taxonomy filters
     * handle most predictably.
     *
     * @return void
     */
    private static function registerTaxonomies(): void
    {
        $shared = [
            'public'             => true,
            'publicly_queryable' => false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_admin_column'  => true,
            'show_in_quick_edit' => true,
            'show_in_nav_menus'  => false,
            'show_in_rest'       => true,
            'query_var'          => true,
            'rewrite'            => false,
        ];

        $taxonomies = [
            self::TAX_PLAYLIST => array_merge($shared, [
                'labels' => [
                    'name'          => 'Playlists',
                    'singular_name' => 'Playlist',
                    'menu_name'     => 'Playlists',
                    'all_items'     => 'All Playlists',
                    'edit_item'     => 'Edit Playlist',
                    'search_items'  => 'Search Playlists',
                    'not_found'     => 'No playlists found.',
                ],
                'description' => 'The configured playlist_name an indexed media item came from.',
                'rest_base'   => 'maw-playlists',
            ]),
            self::TAX_MEDIA_TYPE => array_merge($shared, [
                'labels' => [
                    'name'          => 'Media Types',
                    'singular_name' => 'Media Type',
                    'menu_name'     => 'Media Types',
                    'all_items'     => 'All Media Types',
                    'edit_item'     => 'Edit Media Type',
                    'search_items'  => 'Search Media Types',
                    'not_found'     => 'No media types found.',
                ],
                'description' => 'Whether an indexed media item is a YouTube video or a podcast episode.',
                'rest_base'   => 'maw-media-types',
            ]),
        ];

        foreach ($taxonomies as $taxonomy => $args) {
            /**
             * Filters a media index taxonomy's registration arguments.
             *
             * @param array<string,mixed> $args     Arguments for register_taxonomy().
             * @param string              $taxonomy The taxonomy being registered.
             */
            $filtered = apply_filters(self::FILTER_TAXONOMY_ARGS, $args, $taxonomy);

            register_taxonomy($taxonomy, [self::POST_TYPE], is_array($filtered) ? $filtered : $args);
        }
    }

    /**
     * Registers the public meta keys with types, REST visibility, and sanitizers.
     *
     * Registration is what makes these fields appear in the block editor's
     * custom-fields panel, in the REST response, and in builder dynamic-field
     * pickers. The two underscore-prefixed internal keys are deliberately not
     * registered: core treats them as protected, which is exactly what we want.
     *
     * @return void
     */
    private static function registerMeta(): void
    {
        $integerKeys = [
            self::META_EPISODE,
            self::META_SEASON,
            self::META_PLAYLIST_POSITION,
            self::META_LAST_SEEN,
        ];

        foreach (self::publicMetaKeys() as $metaKey) {
            register_post_meta(self::POST_TYPE, $metaKey, [
                'type'   => in_array($metaKey, $integerKeys, true) ? 'integer' : 'string',
                'single' => true,
                'show_in_rest' => true,
                // Routed through the same helper the storage layer uses, so a
                // value written by the index and a value written over REST are
                // sanitized identically.
                'sanitize_callback' => static fn ($value) => self::sanitizeMetaValue($metaKey, $value),
                'auth_callback'     => [self::class, 'canEditPost'],
            ]);
        }
    }

    /**
     * Applies a public meta key's registered sanitizer to a value.
     *
     * WordPress runs the sanitize_callback from register_post_meta() on every
     * write, so the value that lands in the database is not necessarily the value
     * handed to update_post_meta(). Anything that needs to compare a value it is
     * about to write against what is already stored must therefore sanitize
     * first, or the two can never match — which would make an
     * "is this already up to date?" check always answer no, and rewrite every
     * indexed post on every synchronization forever.
     *
     * @param string $metaKey Meta key being written.
     * @param mixed  $value   Raw value.
     * @return mixed The value as it will actually be stored.
     */
    public static function sanitizeMetaValue(string $metaKey, $value)
    {
        $sanitizers = [
            self::META_PLAYLIST_NAME       => [self::class, 'sanitizeSlug'],
            self::META_MEDIA_TYPE          => [self::class, 'sanitizeMediaType'],
            self::META_SOURCE_ID           => [self::class, 'sanitizeShortText'],
            self::META_YOUTUBE_ID          => [self::class, 'sanitizeProviderId'],
            self::META_PODCAST_GUID        => [self::class, 'sanitizeShortText'],
            self::META_THUMBNAIL_URL       => [self::class, 'sanitizeUrl'],
            self::META_PUBLISHED_DATE      => [self::class, 'sanitizeDate'],
            self::META_EPISODE             => [self::class, 'sanitizeNonNegativeInt'],
            self::META_SEASON              => [self::class, 'sanitizeNonNegativeInt'],
            self::META_PLAYLIST_POSITION   => [self::class, 'sanitizeNonNegativeInt'],
            self::META_TRANSCRIPT_STATUS   => [self::class, 'sanitizeSlug'],
            self::META_TRANSCRIPT_URL      => [self::class, 'sanitizeUrl'],
            self::META_TRANSCRIPT_LANGUAGE => [self::class, 'sanitizeLanguage'],
            self::META_LAST_SEEN           => [self::class, 'sanitizeNonNegativeInt'],
        ];

        if (!isset($sanitizers[$metaKey])) {
            return $value;
        }

        return ($sanitizers[$metaKey])($value);
    }

    /**
     * Authorizes a REST write to an indexed item's meta.
     *
     * Gated on the same capability the editor screen requires, rather than
     * relying on the default, so a meta write can never be more permissive
     * than editing the post it belongs to.
     *
     * @param bool   $allowed Whether the write is currently allowed.
     * @param string $metaKey The meta key being written.
     * @param int    $postId  The post being written to.
     * @return bool True when the current user may edit the post.
     */
    public static function canEditPost($allowed, $metaKey = '', $postId = 0): bool
    {
        return current_user_can('edit_post', (int) $postId);
    }

    /**
     * @param mixed $value Raw meta value.
     * @return string A slug-safe string.
     */
    public static function sanitizeSlug($value): string
    {
        return sanitize_key((string) $value);
    }

    /**
     * @param mixed $value Raw meta value.
     * @return string 'youtube', 'podcast', or an empty string.
     */
    public static function sanitizeMediaType($value): string
    {
        $type = sanitize_key((string) $value);

        return in_array($type, MediaStore::SUPPORTED_MEDIA_TYPES, true) ? $type : '';
    }

    /**
     * @param mixed $value Raw meta value.
     * @return string Plain text, capped at a comfortable index-key length.
     */
    public static function sanitizeShortText($value): string
    {
        return substr(sanitize_text_field((string) $value), 0, 191);
    }

    /**
     * Sanitizes a provider-side identifier without changing its case.
     *
     * sanitize_key() would lowercase this, and YouTube video ids are
     * case-sensitive — `dQw4w9WgXcQ` and `dqw4w9wgxcq` are different videos.
     *
     * @param mixed $value Raw meta value.
     * @return string The identifier, stripped of anything outside the safe set.
     */
    public static function sanitizeProviderId($value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value), 0, 64);
    }

    /**
     * @param mixed $value Raw meta value.
     * @return string An http(s) URL, or an empty string.
     */
    public static function sanitizeUrl($value): string
    {
        return esc_url_raw(trim((string) $value));
    }

    /**
     * Normalizes a publication date to ISO-8601 when it can be parsed.
     *
     * Falls back to the trimmed original rather than discarding it: podcast
     * pubDate values are raw RFC-2822 strings straight from a third-party feed
     * and an unparseable one is still better than nothing. This is also why the
     * REST schema for the field is a plain string with no date-time format —
     * strict validation would reject exactly these values.
     *
     * @param mixed $value Raw meta value.
     * @return string ISO-8601 date, the trimmed original, or an empty string.
     */
    public static function sanitizeDate($value): string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return '';
        }

        $timestamp = strtotime($raw);

        return $timestamp === false ? substr($raw, 0, 191) : gmdate('c', $timestamp);
    }

    /**
     * @param mixed $value Raw meta value.
     * @return int A non-negative integer.
     */
    public static function sanitizeNonNegativeInt($value): int
    {
        return max(0, (int) $value);
    }

    /**
     * @param mixed $value Raw meta value.
     * @return string A BCP-47-shaped language tag, or an empty string.
     */
    public static function sanitizeLanguage($value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9-]/', '', (string) $value), 0, 35);
    }
}
