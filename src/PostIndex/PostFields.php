<?php

namespace MediaApiWidget\PostIndex;

if (!defined('ABSPATH')) { exit; }

/**
 * Reads a stored media item's fields by name, for builder integrations.
 *
 * Builder-neutral: it only reads standard post fields and the registered post
 * meta, so any integration (the Elementor dynamic tags today) presents the same
 * field list and the same values. A few derived display fields are offered on
 * top of the raw meta — a 1-based position, a formatted date, and a
 * season/episode label — so a template does not need custom code for them.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class PostFields
{
    /** Derived: "Season 5 Episode 14", "Episode 14", or blank. */
    public const EPISODE_LABEL = 'episode_label';

    /** Derived: playlist position counting from 1. */
    public const POSITION_NUMBER = 'position_number';

    /** Derived: published date in the site's date format. */
    public const PUBLISHED_FORMATTED = 'published_date_formatted';

    /**
     * Returns every readable field as key => label, in picker order.
     *
     * @return array<string,string> Field key => label.
     */
    public static function options(): array
    {
        $labels = PostIndex::fieldLabels();
        $label  = static fn (string $key): string => $labels[$key][0] ?? $key;

        return [
            'post_title'                         => 'Title',
            'post_excerpt'                       => 'Description',
            PostIndex::META_SOURCE_ID            => 'ID (YouTube video ID / podcast episode ID)',
            PostIndex::META_SEASON               => $label(PostIndex::META_SEASON),
            PostIndex::META_EPISODE              => $label(PostIndex::META_EPISODE),
            self::EPISODE_LABEL                  => 'Season & episode label',
            PostIndex::META_PUBLISHED_DATE       => 'Published date (ISO 8601)',
            self::PUBLISHED_FORMATTED            => 'Published date (site format)',
            self::POSITION_NUMBER                => 'Playlist position (1 = first)',
            PostIndex::META_PLAYLIST_POSITION    => 'Playlist position (0 = first)',
            PostIndex::META_PLAYLIST_NAME        => $label(PostIndex::META_PLAYLIST_NAME),
            PostIndex::META_MEDIA_TYPE           => $label(PostIndex::META_MEDIA_TYPE),
            PostIndex::META_THUMBNAIL_URL        => $label(PostIndex::META_THUMBNAIL_URL),
            PostIndex::META_MEDIA_URL            => $label(PostIndex::META_MEDIA_URL),
            PostIndex::META_AUDIO_URL            => $label(PostIndex::META_AUDIO_URL),
            PostIndex::META_YOUTUBE_ID           => $label(PostIndex::META_YOUTUBE_ID),
            PostIndex::META_PODCAST_GUID         => $label(PostIndex::META_PODCAST_GUID),
            PostIndex::META_TRANSCRIPT_STATUS    => $label(PostIndex::META_TRANSCRIPT_STATUS),
            PostIndex::META_TRANSCRIPT_URL       => $label(PostIndex::META_TRANSCRIPT_URL),
            PostIndex::META_TRANSCRIPT_LANGUAGE  => $label(PostIndex::META_TRANSCRIPT_LANGUAGE),
        ];
    }

    /**
     * Link prefix the front-end script turns into a media item click. The
     * values follow as URL-safe base64 JSON, so the whole fragment is a valid
     * CSS ID selector: theme and builder scripts that pass a clicked link's
     * hash to querySelector() simply find nothing instead of throwing.
     */
    public const CLICK_PREFIX = '#maw-media-';

    /** Click action: open the lightbox / player for this item alone. */
    public const CLICK_LIGHTBOX = 'lightbox';

    /** Click action: open the lightbox / player with the playlist. */
    public const CLICK_PLAYLIST = 'playlist';

    /** Click action: only dispatch mediaApiWidgetItemClick. */
    public const CLICK_EVENT = 'event';

    /**
     * Returns the click actions as action => label.
     *
     * @return array<string,string>
     */
    public static function clickActions(): array
    {
        return [
            self::CLICK_LIGHTBOX => 'Open lightbox (this item only)',
            self::CLICK_PLAYLIST => 'Open lightbox with the playlist',
            self::CLICK_EVENT    => 'Click event only (no lightbox)',
        ];
    }

    /**
     * Builds the link that makes a builder element act like this item's media
     * card: clicking it dispatches `mediaApiWidgetItemClick` and, unless the
     * action is CLICK_EVENT, opens the plugin's lightbox (YouTube) or player
     * (podcast) for it.
     *
     * The link is a fragment, so it never navigates; the front-end script
     * reads it. It contains only letters, digits, "-" and "_", so it survives
     * esc_url() unchanged.
     *
     * @param int                  $postId Media item post ID.
     * @param string               $action One of the CLICK_* actions.
     * @param array<string,string> $style  Optional lightbox styling for the
     *                                     playlist action: color, font, logo.
     * @return string Link, or '' when the post is not a media item.
     */
    public static function clickUrl(int $postId, string $action = self::CLICK_LIGHTBOX, array $style = []): string
    {
        $post = $postId > 0 ? get_post($postId) : null;

        if ($post === null || (string) $post->post_type !== PostIndex::POST_TYPE) {
            return '';
        }

        $type     = (string) get_post_meta($postId, PostIndex::META_MEDIA_TYPE, true);
        $playlist = (string) get_post_meta($postId, PostIndex::META_PLAYLIST_NAME, true);
        // The identifier the plugin's own cards carry: the video ID, or the
        // episode GUID (falling back to the stored identity).
        $itemId = (string) get_post_meta($postId, $type === 'youtube' ? PostIndex::META_YOUTUBE_ID : PostIndex::META_PODCAST_GUID, true);
        if ($itemId === '') {
            $itemId = (string) get_post_meta($postId, PostIndex::META_SOURCE_ID, true);
        }

        if ($playlist === '' || $itemId === '' || !in_array($type, ['youtube', 'podcast'], true)) {
            return '';
        }

        $args = [
            'playlist' => $playlist,
            'type'     => $type,
            'id'       => $itemId,
            'action'   => array_key_exists($action, self::clickActions()) ? $action : self::CLICK_LIGHTBOX,
            'post'     => (string) $postId,
            'title'    => (string) $post->post_title,
        ];

        if ($args['action'] === self::CLICK_PLAYLIST) {
            $color = sanitize_hex_color((string) ($style['color'] ?? ''));
            $font  = sanitize_text_field((string) ($style['font'] ?? ''));
            $logo  = esc_url_raw((string) ($style['logo'] ?? ''));

            $args += array_filter(['color' => (string) $color, 'font' => $font, 'logo' => $logo], static fn (string $v): bool => $v !== '');
        }

        $json = wp_json_encode($args);

        return is_string($json) ? self::CLICK_PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=') : '';
    }

    /**
     * Decodes a link built by {@see self::clickUrl()} (the inverse; used by
     * tests and integrations).
     *
     * @param string $url Link.
     * @return array<string,string>|null Values, or null when not such a link.
     */
    public static function parseClickUrl(string $url): ?array
    {
        $at = strpos($url, self::CLICK_PREFIX);
        if ($at === false) {
            return null;
        }

        $payload = substr($url, $at + strlen(self::CLICK_PREFIX));
        $json    = base64_decode(strtr($payload, '-_', '+/'), true);
        $values  = is_string($json) ? json_decode($json, true) : null;

        return is_array($values) ? array_map('strval', $values) : null;
    }

    /**
     * Returns the URL-valued fields as key => label.
     *
     * @return array<string,string> Field key => label.
     */
    public static function urlOptions(): array
    {
        $labels = PostIndex::fieldLabels();

        return [
            PostIndex::META_MEDIA_URL      => $labels[PostIndex::META_MEDIA_URL][0],
            PostIndex::META_AUDIO_URL      => $labels[PostIndex::META_AUDIO_URL][0],
            PostIndex::META_THUMBNAIL_URL  => $labels[PostIndex::META_THUMBNAIL_URL][0],
            PostIndex::META_TRANSCRIPT_URL => $labels[PostIndex::META_TRANSCRIPT_URL][0],
        ];
    }

    /**
     * Returns one field of a media item post as text.
     *
     * @param int    $postId Post ID.
     * @param string $key    Field key from {@see self::options()}.
     * @return string Value, or '' when the field is unknown, empty, or the post
     *                is not a media item.
     */
    public static function value(int $postId, string $key): string
    {
        $post = $postId > 0 ? get_post($postId) : null;

        if ($post === null || (string) $post->post_type !== PostIndex::POST_TYPE) {
            return '';
        }

        switch ($key) {
            case 'post_title':
                return (string) $post->post_title;
            case 'post_excerpt':
                return (string) $post->post_excerpt;
            case self::POSITION_NUMBER:
                return (string) ((int) $post->menu_order + 1);
            case self::EPISODE_LABEL:
                return self::episodeLabel($postId);
            case self::PUBLISHED_FORMATTED:
                $raw = (string) get_post_meta($postId, PostIndex::META_PUBLISHED_DATE, true);
                $ts  = $raw !== '' ? strtotime($raw) : false;

                return $ts === false ? '' : (string) wp_date((string) get_option('date_format'), $ts);
        }

        if (!in_array($key, PostIndex::publicMetaKeys(), true)) {
            return '';
        }

        $value = get_post_meta($postId, $key, true);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Builds the season/episode label the plugin's numbered titles use.
     *
     * @param int $postId Post ID.
     * @return string Label, or '' when no episode number is stored.
     */
    private static function episodeLabel(int $postId): string
    {
        $episode = (string) get_post_meta($postId, PostIndex::META_EPISODE, true);
        $season  = (string) get_post_meta($postId, PostIndex::META_SEASON, true);

        if ($episode === '') {
            return '';
        }

        return $season !== '' ? 'Season ' . $season . ' Episode ' . $episode : 'Episode ' . $episode;
    }
}
