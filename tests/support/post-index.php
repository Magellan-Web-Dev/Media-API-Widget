<?php
/**
 * Shared helpers for the media post index cases.
 *
 * Fixtures mirror the shapes the media pipeline actually stores: the parsed
 * YouTube item list written by MediaContent::parseYoutubeItems(), and the
 * normalized podcast feed tree written by the RSS paths. Seeding writes the
 * transient exactly as MediaStore does (an array for YouTube, a JSON string for
 * podcast), so the sync under test reads it through the production read path.
 *
 * Post storage is opt-in per playlist, so every seeding helper also configures
 * the playlist with "Store playlist items as WordPress posts" enabled unless a
 * case says otherwise.
 */

declare(strict_types=1);

use MediaApiWidget\Config\Options;
use MediaApiWidget\PostIndex\PostIndex;
use MediaApiWidget\PostIndex\PostIndexSync;
use MediaApiWidget\PostIndex\RefreshManifest;

/**
 * Registers the post type, taxonomies, and public meta.
 *
 * @return void
 */
function maw_mpi_register_types(): void
{
    PostIndex::registerTypes();
}

/**
 * Registers the content model and the store listener / cron callback, and
 * enables storage for the playlists the scheduling cases use.
 *
 * @param array<string,string> $playlists playlist_name => media type to enable.
 * @return void
 */
function maw_mpi_boot(array $playlists = ['testshow' => 'youtube', 'my_show' => 'youtube']): void
{
    maw_mpi_register_types();

    foreach ($playlists as $playlist => $type) {
        maw_mpi_enable($playlist, $type);
    }

    (new PostIndexSync())->register();
}

/**
 * Adds (or updates) a media item row with post storage switched on or off.
 *
 * @param string              $playlist Playlist slug.
 * @param string              $type     'youtube' or 'podcast'.
 * @param bool                $enabled  Store playlist items as posts.
 * @param array<string,mixed> $extra    Extra row keys (load_full_playlist, …).
 * @return void
 */
function maw_mpi_enable(string $playlist, string $type = 'youtube', bool $enabled = true, array $extra = []): void
{
    $items = Options::getMediaItems();
    $found = false;

    foreach ($items as $index => $item) {
        if (($item['playlist_name'] ?? '') === $playlist && ($item['type'] ?? '') === $type) {
            $items[$index] = array_merge($item, ['store_as_posts' => $enabled], $extra);
            $found = true;
        }
    }

    if (!$found) {
        $items[] = array_merge([
            'type'               => $type,
            'playlist_name'      => $playlist,
            'load_full_playlist' => true,
            'store_as_posts'     => $enabled,
        ], $extra);
    }

    Options::setMediaItems($items);
}

/**
 * Builds one parsed YouTube item.
 *
 * @param array<string,mixed> $overrides Fields to replace.
 * @return array<string,mixed> Item.
 */
function maw_mpi_youtube_item(array $overrides = []): array
{
    return array_merge([
        'episode'       => -1,
        'title'         => 'Episode Title',
        'id'            => 'vid00001',
        'thumbnail'     => ['url' => 'https://i.example.com/vid00001.jpg', 'width' => 1280, 'height' => 720],
        'publishedDate' => '2025-01-01T00:00:00Z',
        'description'   => 'Episode description.',
    ], $overrides);
}

/**
 * Stores a YouTube payload as the canonical data for a playlist.
 *
 * @param string                              $playlist Playlist slug.
 * @param array<int,array<string,mixed>>|null $items    Items; one default item when null.
 * @param bool                                $enable   Whether to enable post storage for it.
 * @return void
 */
function maw_mpi_seed_youtube(string $playlist = 'testshow', ?array $items = null, bool $enable = true): void
{
    set_transient('youtube_' . $playlist, $items ?? [maw_mpi_youtube_item()], 7200);

    if ($enable) {
        maw_mpi_enable($playlist, 'youtube');
    }
}

/**
 * Builds one podcast episode as the RSS paths store it.
 *
 * A null override is kept as null (an absent enclosure, for instance).
 *
 * @param array<string,mixed> $overrides Fields to replace.
 * @return array<string,mixed> Episode.
 */
function maw_mpi_podcast_item(array $overrides = []): array
{
    return array_merge([
        'title'       => 'Podcast Episode',
        'description' => 'Episode description.',
        'guid'        => 'guid-1',
        'link'        => 'https://example.com/episode',
        'pubDate'     => 'Mon, 06 Jan 2025 00:00:00 +0000',
        'enclosure'   => ['@attributes' => ['url' => 'https://cdn.example.com/episode.mp3', 'type' => 'audio/mpeg']],
    ], $overrides);
}

/**
 * Wraps episodes in a feed tree. A null item omits channel.item entirely.
 *
 * @param mixed $items Episode list, a single bare episode array, or null.
 * @return array<string,mixed> Feed tree.
 */
function maw_mpi_podcast_tree($items): array
{
    $channel = ['title' => 'Test Podcast', 'rssUrl' => 'https://feeds.example.com/podcast.xml'];

    if ($items !== null) {
        $channel['item'] = $items;
    }

    return ['channel' => $channel];
}

/**
 * Stores a podcast feed tree as the canonical data for a playlist.
 *
 * @param string              $playlist Playlist slug.
 * @param array<string,mixed> $tree     Feed tree.
 * @param bool                $enable   Whether to enable post storage for it.
 * @return void
 */
function maw_mpi_seed_podcast(string $playlist, array $tree, bool $enable = true): void
{
    set_transient('podcast_' . $playlist, (string) json_encode($tree), 7200);

    if ($enable) {
        maw_mpi_enable($playlist, 'podcast');
    }
}

/**
 * Records an authoritative refresh for whatever is currently stored, exactly as
 * a completed remote refresh does: membership is every source identity in the
 * complete (untrimmed) result.
 *
 * @param string     $playlist Playlist slug.
 * @param string     $type     'youtube' or 'podcast'.
 * @param mixed|null $complete Complete source result; the stored data when null.
 * @return int The manifest generation recorded.
 */
function maw_mpi_record_refresh(string $playlist = 'testshow', string $type = 'youtube', $complete = null): int
{
    $complete ??= media_api_widget_get_stored_data($playlist, $type);

    return RefreshManifest::recordFromData($playlist, $type, $complete);
}

/**
 * Returns every indexed post row, in ID order.
 *
 * @return array<int,array<string,mixed>> Post rows.
 */
function maw_mpi_posts(): array
{
    $rows = array_filter(
        MawTestPosts::$posts,
        static fn (array $row): bool => $row['post_type'] === PostIndex::POST_TYPE
    );
    ksort($rows);

    return array_values($rows);
}

/**
 * Returns the indexed post carrying a source key, or null.
 *
 * @param string $sourceKey Composite identity.
 * @return array<string,mixed>|null Post row.
 */
function maw_mpi_post_by_key(string $sourceKey): ?array
{
    foreach (maw_mpi_posts() as $row) {
        if ((MawTestPosts::$meta[(int) $row['ID']][PostIndex::META_SOURCE_KEY][0] ?? null) === $sourceKey) {
            return $row;
        }
    }

    return null;
}

/**
 * Returns one stored meta value as written (sanitized, typed), or null.
 *
 * @param int    $postId  Post ID.
 * @param string $metaKey Meta key.
 * @return mixed Value, or null when the row does not exist.
 */
function maw_mpi_meta(int $postId, string $metaKey)
{
    return MawTestPosts::$meta[$postId][$metaKey][0] ?? null;
}

/**
 * Returns the term slugs assigned to a post in one taxonomy.
 *
 * @param int    $postId   Post ID.
 * @param string $taxonomy Taxonomy.
 * @return array<int,string> Slugs.
 */
function maw_mpi_terms(int $postId, string $taxonomy): array
{
    return array_map(static fn (object $term): string => (string) $term->slug, wp_get_object_terms($postId, $taxonomy));
}

/**
 * Lists queued synchronization events.
 *
 * @return array<int,array{args:array<int,mixed>,timestamp:int}> Events, soonest first.
 */
function maw_mpi_cron_events(): array
{
    $events = [];

    foreach (MawTestPosts::$cron as $timestamp => $hooks) {
        foreach ($hooks[PostIndexSync::CRON_HOOK] ?? [] as $event) {
            $events[] = $event;
        }
    }

    usort($events, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

    return $events;
}

/**
 * Runs queued synchronization events the way WP-Cron does: each event is
 * removed from the schedule before its callback runs, so a callback may queue
 * a continuation. Stops after $maxRuns so a retrying job cannot loop forever.
 *
 * @param int $maxRuns Maximum callbacks to run.
 * @return int How many callbacks ran.
 */
function maw_mpi_run_cron(int $maxRuns = 50): int
{
    $runs = 0;

    while ($runs < $maxRuns) {
        $events = maw_mpi_cron_events();
        if ($events === []) {
            break;
        }

        $event = $events[0];
        wp_unschedule_event((int) $event['timestamp'], PostIndexSync::CRON_HOOK, $event['args']);

        MawTestPosts::$doingCron = true;
        try {
            do_action(PostIndexSync::CRON_HOOK, ...$event['args']);
        } finally {
            MawTestPosts::$doingCron = false;
        }

        $runs++;
    }

    return $runs;
}
