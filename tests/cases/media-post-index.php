<?php
/**
 * Tests the builder-neutral WordPress media post index.
 *
 * Two guarantees carry most of the weight here.
 *
 * The first is that synchronization is *idempotent and identity-stable*. The
 * index is rebuilt from the same stored payload over and over — on every
 * refresh, every warm-up, every enrichment write, and every manual rebuild — so
 * an identity that shifts, or an upsert that fails to find an existing post,
 * silently accumulates duplicate posts until a page builder renders each episode
 * several times.
 *
 * The second is that a routine refresh cannot erase enrichment. A transcript
 * lives in post_content and is absent from every API payload, so an update that
 * offered post_content unconditionally would destroy it the first time a cache
 * expired. Several cases here assert not merely that the transcript survived,
 * but that the update never even submitted the field.
 */

declare(strict_types=1);

use MediaApiWidget\PostIndex\Backfill;
use MediaApiWidget\PostIndex\ItemMapper;
use MediaApiWidget\PostIndex\PostIndex;
use MediaApiWidget\PostIndex\PostIndexSync;
use MediaApiWidget\Support\MediaStore;
use MediaApiWidget\Support\OptionLock;
use MediaApiWidget\Support\XmlText;

/**
 * Synchronizes a playlist and returns the result summary.
 *
 * @param string $playlist Playlist slug.
 * @param string $type     'youtube' or 'podcast'.
 * @return array<string,mixed> The summary.
 */
function maw_mpi_sync(string $playlist = 'testshow', string $type = 'youtube'): array
{
    return PostIndexSync::sync($playlist, $type, PostIndexSync::SOURCE_MANUAL);
}

/**
 * Returns the source key an indexed YouTube item should carry.
 *
 * @param string $videoId  YouTube video id.
 * @param string $playlist Playlist slug.
 * @return string The composite identity.
 */
function maw_mpi_youtube_key(string $videoId = 'vid00001', string $playlist = 'testshow'): string
{
    return ItemMapper::sourceKey('youtube', $playlist, 'vid', $videoId);
}

/**
 * Stores a playlist config so the backfill and rebuild can discover it.
 *
 * @param array<int,array<string,string>> $items Media item config rows.
 * @return void
 */
function maw_mpi_configure(array $items): void
{
    MediaApiWidget\Config\Options::setMediaItems($items);
}

return [

    // -----------------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------------

    'the media item post type is registered index-only but builder-visible' => static function (): void {
        maw_mpi_register_types();

        maw_assert_same(true, post_type_exists(PostIndex::POST_TYPE), 'the post type is registered');

        $args = get_post_type_object(PostIndex::POST_TYPE);

        maw_assert_same(true, $args->public, 'public is true so builders enumerate the type');
        maw_assert_same(false, $args->publicly_queryable, 'the type has no front-end URL of its own');
        maw_assert_same(false, $args->rewrite, 'the type contributes no rewrite rules');
        maw_assert_same(false, $args->has_archive, 'the type has no archive to collide with existing routes');
        maw_assert_same(true, $args->exclude_from_search, 'indexed items stay out of site search');
        maw_assert_same(true, $args->show_ui, 'the type is manageable in wp-admin');
        maw_assert_same(true, $args->show_in_rest, 'the type is REST-visible');
    },

    'the post type supports the fields the index writes' => static function (): void {
        maw_mpi_register_types();

        $args = get_post_type_object(PostIndex::POST_TYPE);

        foreach (['title', 'editor', 'excerpt', 'custom-fields', 'author'] as $support) {
            maw_assert(
                in_array($support, $args->supports, true),
                'the post type supports ' . $support
            );
        }

        maw_assert_same(
            [PostIndex::TAX_PLAYLIST, PostIndex::TAX_MEDIA_TYPE],
            $args->taxonomies,
            'both index taxonomies are attached to the post type'
        );
    },

    'both taxonomies are registered flat and attached to the post type' => static function (): void {
        maw_mpi_register_types();

        foreach ([PostIndex::TAX_PLAYLIST, PostIndex::TAX_MEDIA_TYPE] as $taxonomy) {
            maw_assert_same(true, taxonomy_exists($taxonomy), $taxonomy . ' is registered');
            maw_assert_same(
                [PostIndex::POST_TYPE],
                MawTestPosts::$taxonomies[$taxonomy]['object_types'],
                $taxonomy . ' applies to the media item post type'
            );

            $args = get_taxonomy($taxonomy);
            maw_assert_same(false, $args->hierarchical, $taxonomy . ' terms are flat');
            maw_assert_same(true, $args->show_in_rest, $taxonomy . ' is REST-visible');
            maw_assert_same(true, $args->show_admin_column, $taxonomy . ' shows an admin column');
        }
    },

    'every public meta key is registered and the internal ones are not' => static function (): void {
        maw_mpi_register_types();

        $registered = MawTestPosts::$registeredMeta[PostIndex::POST_TYPE] ?? [];

        foreach (PostIndex::publicMetaKeys() as $metaKey) {
            maw_assert(isset($registered[$metaKey]), $metaKey . ' is registered');
            maw_assert_same(true, $registered[$metaKey]['single'], $metaKey . ' is a single value');
            maw_assert_same(true, $registered[$metaKey]['show_in_rest'], $metaKey . ' is REST-visible');
            maw_assert(
                is_callable($registered[$metaKey]['sanitize_callback']),
                $metaKey . ' has a sanitize callback'
            );
        }

        maw_assert_same('integer', $registered[PostIndex::META_EPISODE]['type'], 'episode is an integer');
        maw_assert_same('integer', $registered[PostIndex::META_LAST_SEEN]['type'], 'last seen is an integer');
        maw_assert_same('string', $registered[PostIndex::META_YOUTUBE_ID]['type'], 'the video id is a string');

        maw_assert_same(
            false,
            isset($registered[PostIndex::META_SOURCE_KEY]),
            'the internal source key is not REST-registered'
        );
        maw_assert_same(
            false,
            isset($registered[PostIndex::META_SOURCE_PAYLOAD]),
            'the internal source payload is not REST-registered'
        );
    },

    'the registration filters can adjust the arguments' => static function (): void {
        maw_on(PostIndex::FILTER_POST_TYPE_ARGS, static function ($args, $postType) {
            $args['publicly_queryable'] = true;

            return $args;
        });
        maw_on(PostIndex::FILTER_TAXONOMY_ARGS, static function ($args, $taxonomy) {
            $args['show_admin_column'] = false;

            return $args;
        });

        maw_mpi_register_types();

        maw_assert_same(
            true,
            get_post_type_object(PostIndex::POST_TYPE)->publicly_queryable,
            'a site whose builder needs a public URL can flip the flag without forking'
        );
        maw_assert_same(
            false,
            get_taxonomy(PostIndex::TAX_PLAYLIST)->show_admin_column,
            'taxonomy arguments are filterable too'
        );
    },

    // -----------------------------------------------------------------------
    // YouTube synchronization
    // -----------------------------------------------------------------------

    'a stored youtube item creates exactly one post' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube();

        $result = maw_mpi_sync();

        maw_assert_same(true, $result['ok'], 'the sync succeeds');
        maw_assert_same(1, $result['created'], 'one post is created');
        maw_assert_same(1, count(maw_mpi_posts()), 'exactly one indexed post exists');
        maw_assert_same(0, count(MawTestState::$httpRequests), 'no external request is made');
    },

    'reprocessing the same item updates it without creating a duplicate' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube();

        maw_mpi_sync();
        $firstId = maw_mpi_posts()[0]['ID'];

        $second = maw_mpi_sync();

        maw_assert_same(1, count(maw_mpi_posts()), 'still exactly one indexed post');
        maw_assert_same($firstId, maw_mpi_posts()[0]['ID'], 'the existing post id is preserved');
        maw_assert_same(1, $second['unchanged'], 'the second pass reports the item unchanged');
        maw_assert_same(0, $second['created'], 'nothing is created on the second pass');
    },

    'the same video in two playlists creates two independent posts' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('showone');
        maw_mpi_seed_youtube('showtwo');

        maw_mpi_sync('showone');
        maw_mpi_sync('showtwo');

        maw_assert_same(2, count(maw_mpi_posts()), 'each playlist gets its own indexed post');
        maw_assert(
            maw_mpi_post_by_key(maw_mpi_youtube_key('vid00001', 'showone')) !== null,
            'the first playlist has its own identity'
        );
        maw_assert(
            maw_mpi_post_by_key(maw_mpi_youtube_key('vid00001', 'showtwo')) !== null,
            'the second playlist has a separate identity'
        );
    },

    'youtube fields map to post fields and meta' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item([
                'title'         => 'Mapped Episode',
                'description'   => 'Mapped description.',
                'id'            => 'dQw4w9WgXcQ',
                'episode'       => 14,
                'season'        => 5,
                'publishedDate' => '2025-02-03T10:00:00Z',
            ]),
        ]);

        maw_mpi_sync();

        $post = maw_mpi_posts()[0];
        $id   = (int) $post['ID'];

        maw_assert_same('Mapped Episode', $post['post_title'], 'the title becomes the post title');
        maw_assert_same('Mapped description.', $post['post_excerpt'], 'the description becomes the excerpt');
        maw_assert_same('', $post['post_content'], 'the body is empty until a transcript arrives');
        maw_assert_same('publish', $post['post_status'], 'indexed items are published');
        maw_assert_same(0, (int) $post['menu_order'], 'playlist position drives menu_order');

        maw_assert_same('testshow', maw_mpi_meta($id, PostIndex::META_PLAYLIST_NAME), 'the playlist name is stored');
        maw_assert_same('youtube', maw_mpi_meta($id, PostIndex::META_MEDIA_TYPE), 'the media type is stored');
        maw_assert_same('dQw4w9WgXcQ', maw_mpi_meta($id, PostIndex::META_SOURCE_ID), 'the source id is stored');
        maw_assert_same('dQw4w9WgXcQ', maw_mpi_meta($id, PostIndex::META_YOUTUBE_ID), 'the video id is stored');
        maw_assert_same(
            'https://i.example.com/vid00001.jpg',
            maw_mpi_meta($id, PostIndex::META_THUMBNAIL_URL),
            'the remote thumbnail url is stored rather than sideloaded'
        );
        maw_assert_same(14, maw_mpi_meta($id, PostIndex::META_EPISODE), 'the episode number is stored');
        maw_assert_same(5, maw_mpi_meta($id, PostIndex::META_SEASON), 'the season number is stored');
        maw_assert_same(0, maw_mpi_meta($id, PostIndex::META_PLAYLIST_POSITION), 'the position is stored');
        maw_assert(
            (int) maw_mpi_meta($id, PostIndex::META_LAST_SEEN) > 0,
            'the last-seen timestamp is stamped'
        );
        maw_assert_same(
            ['testshow'],
            maw_mpi_terms($id, PostIndex::TAX_PLAYLIST),
            'the playlist term is assigned'
        );
        maw_assert_same(
            ['youtube'],
            maw_mpi_terms($id, PostIndex::TAX_MEDIA_TYPE),
            'the media type term is assigned'
        );
    },

    'the video id keeps its case' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['id' => 'dQw4w9WgXcQ'])]);

        maw_mpi_sync();

        maw_assert_same(
            'dQw4w9WgXcQ',
            maw_mpi_meta((int) maw_mpi_posts()[0]['ID'], PostIndex::META_YOUTUBE_ID),
            'video ids are case-sensitive, so the id is never lowercased'
        );
    },

    'an unnumbered item writes no episode meta and numbering later adds it' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['episode' => -1])]);

        maw_mpi_sync();
        $id = (int) maw_mpi_posts()[0]['ID'];

        maw_assert_same(
            null,
            maw_mpi_meta($id, PostIndex::META_EPISODE),
            'the -1 sentinel writes no episode row, so an EXISTS query means "numbered"'
        );

        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['episode' => 7])]);
        maw_mpi_sync();

        maw_assert_same(7, maw_mpi_meta($id, PostIndex::META_EPISODE), 'a later numbered refresh adds it');

        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['episode' => -1])]);
        maw_mpi_sync();

        maw_assert_same(
            null,
            maw_mpi_meta($id, PostIndex::META_EPISODE),
            'losing numbering deletes the row rather than leaving a stale number'
        );
    },

    'positions and menu order follow playlist order' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item(['id' => 'vidaaa', 'title' => 'First']),
            maw_mpi_youtube_item(['id' => 'vidbbb', 'title' => 'Second']),
            maw_mpi_youtube_item(['id' => 'vidccc', 'title' => 'Third']),
        ]);

        maw_mpi_sync();

        $ordered = get_posts([
            'post_type'      => PostIndex::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);

        maw_assert_same(3, count($ordered), 'all three items are indexed');
        maw_assert_same('First', $ordered[0]->post_title, 'the first item sorts first by menu_order');
        maw_assert_same('Third', $ordered[2]->post_title, 'the last item sorts last');
        maw_assert_same(
            2,
            maw_mpi_meta((int) $ordered[2]->ID, PostIndex::META_PLAYLIST_POSITION),
            'the stored position matches the playlist order'
        );
    },

    'arbitrary source fields are preserved in the internal payload' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item(['vendor_meta' => ['a' => [1, 2], 'b' => 'keep']]),
        ]);

        maw_mpi_sync();

        $payload = json_decode(
            (string) maw_mpi_meta((int) maw_mpi_posts()[0]['ID'], PostIndex::META_SOURCE_PAYLOAD),
            true
        );

        maw_assert_same([1, 2], $payload['vendor_meta']['a'] ?? null, 'nested custom data is retained');
        maw_assert_same('keep', $payload['vendor_meta']['b'] ?? null, 'custom scalars are retained');
    },

    'secret-looking source keys never reach post meta' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item([
                'api_key'      => 'SECRET-API-KEY-DO-NOT-LEAK',
                'access_token' => 'SECRET-TOKEN',
                'nested'       => ['client_secret' => 'SECRET-NESTED'],
            ]),
        ]);

        maw_mpi_sync();

        $dump = (string) wp_json_encode(MawTestPosts::$meta);

        maw_assert(
            !str_contains($dump, 'SECRET-API-KEY-DO-NOT-LEAK'),
            'an api key added to an item never reaches post meta'
        );
        maw_assert(!str_contains($dump, 'SECRET-TOKEN'), 'a token never reaches post meta');
        maw_assert(!str_contains($dump, 'SECRET-NESTED'), 'a nested secret never reaches post meta');
    },

    'an item with no video id is skipped rather than indexed' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item(['id' => null]),
            maw_mpi_youtube_item(['id' => 'vidgood']),
        ]);

        $result = maw_mpi_sync();

        maw_assert_same(1, count(maw_mpi_posts()), 'only the identifiable item is indexed');
        maw_assert_same(1, $result['skipped'], 'the unidentifiable item is reported as skipped');
        maw_assert_same(2, $result['total_seen'], 'the total still counts everything examined');
    },

    // -----------------------------------------------------------------------
    // Podcast identity preservation
    // -----------------------------------------------------------------------

    'a cdata guid survives the refresh path and drives identity' => static function (): void {
        $rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'
            . '<title>Test Podcast</title>'
            . '<item><title><![CDATA[CDATA Episode]]></title>'
            . '<description><![CDATA[<p>Body.</p>]]></description>'
            . '<guid isPermaLink="false"><![CDATA[stable-guid-1]]></guid>'
            . '<link><![CDATA[https://example.com/1]]></link>'
            . '<pubDate>Mon, 06 Jan 2025 00:00:00 +0000</pubDate></item>'
            . '</channel></rss>';

        maw_queue_raw($rss);
        maw_run_podcast_load(array_merge(maw_youtube_config(), [
            'type'             => 'podcast',
            'podcast_platform' => 'custom',
            'playlist_name'    => 'testpod',
            'media_data'       => 'https://feeds.example.com/podcast.xml',
        ]));

        $stored = json_decode((string) get_transient('podcast_testpod'), true);
        $item   = $stored['channel']['item'];

        maw_assert_same('stable-guid-1', $item['guid'], 'a CDATA guid is stored as a plain string');
        maw_assert_same('CDATA Episode', $item['title'], 'a CDATA title survives');
        maw_assert_same('Body.', $item['description'], 'a CDATA description survives');

        maw_mpi_register_types();
        maw_mpi_sync('testpod', 'podcast');

        $post = maw_mpi_posts()[0];

        maw_assert_same(
            ItemMapper::sourceKey('podcast', 'testpod', 'guid', 'stable-guid-1'),
            maw_mpi_meta((int) $post['ID'], PostIndex::META_SOURCE_KEY),
            'identity uses the guid tier, not a mutable fallback'
        );
        maw_assert_same('CDATA Episode', $post['post_title'], 'the recovered title becomes the post title');
    },

    'a cdata guid survives the shortcode warm up path' => static function (): void {
        $rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'
            . '<title>Test Podcast</title>'
            . '<item><title><![CDATA[Warmed Episode]]></title>'
            . '<description><![CDATA[Warmed body.]]></description>'
            . '<guid isPermaLink="false"><![CDATA[warm-guid-1]]></guid>'
            . '<pubDate>Mon, 06 Jan 2025 00:00:00 +0000</pubDate></item>'
            . '</channel></rss>';

        maw_queue_raw($rss);

        $parsed = maw_run_podcast_warmup('testpod', [
            'type'             => 'podcast',
            'playlist_name'    => 'testpod',
            'podcast_platform' => 'custom',
            'media_data'       => 'https://feeds.example.com/podcast.xml',
        ]);

        $item = $parsed['channel']['item'][0];

        maw_assert_same('warm-guid-1', $item['guid'], 'the warm-up path also preserves a CDATA guid');
        maw_assert_same('Warmed Episode', $item['title'], 'the warm-up path no longer stores a blank title');
        maw_assert_same('Warmed body.', $item['description'], 'the warm-up path no longer stores a blank description');
    },

    'retitling an episode with a guid updates the same post' => static function (): void {
        maw_mpi_register_types();

        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree([
            maw_mpi_podcast_item(['title' => 'Original Title', 'guid' => 'stable-1']),
        ]));
        maw_mpi_sync('testpod', 'podcast');

        $firstId = (int) maw_mpi_posts()[0]['ID'];

        // Same guid, different title and link: the identity must not move.
        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree([
            maw_mpi_podcast_item([
                'title' => 'Renamed Title',
                'guid'  => 'stable-1',
                'link'  => 'https://example.com/moved',
            ]),
        ]));
        maw_mpi_sync('testpod', 'podcast');

        maw_assert_same(1, count(maw_mpi_posts()), 'retitling does not create a duplicate post');
        maw_assert_same($firstId, (int) maw_mpi_posts()[0]['ID'], 'the same post is updated');
        maw_assert_same('Renamed Title', maw_mpi_posts()[0]['post_title'], 'the new title is applied');
    },

    'the enclosure url is read from attributes rather than flattened' => static function (): void {
        $enclosure = ['@attributes' => ['url' => 'https://cdn.example.com/ep1.mp3', 'type' => 'audio/mpeg']];

        maw_assert_same(
            '',
            XmlText::flatten($enclosure),
            'flatten() skips @-prefixed keys, so an enclosure has no text to give'
        );
        maw_assert_same(
            'https://cdn.example.com/ep1.mp3',
            XmlText::enclosureUrl($enclosure),
            'the explicit accessor reads the url attribute'
        );
        maw_assert_same(
            'https://cdn.example.com/first.mp3',
            XmlText::enclosureUrl([
                ['@attributes' => ['url' => 'https://cdn.example.com/first.mp3']],
                ['@attributes' => ['url' => 'https://cdn.example.com/second.mp3']],
            ]),
            'a list of enclosures resolves to the first usable url'
        );
        maw_assert_same(
            '',
            XmlText::enclosureUrl(['@attributes' => ['url' => 'javascript:alert(1)']]),
            'a non-http scheme is refused'
        );
    },

    'the identity falls back through enclosure, link, then a hash' => static function (): void {
        maw_mpi_register_types();

        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree([
            // No guid: falls to the enclosure url.
            maw_mpi_podcast_item(['guid' => '']),
            // No guid and no enclosure: falls to the link.
            maw_mpi_podcast_item(['guid' => '', 'enclosure' => null, 'link' => 'https://example.com/two']),
            // Nothing but a title and date: falls to a hash of them.
            maw_mpi_podcast_item([
                'guid' => '', 'enclosure' => null, 'link' => '',
                'title' => 'Third', 'pubDate' => 'Tue, 07 Jan 2025 00:00:00 +0000',
            ]),
            // Nothing at all: unidentifiable, so skipped.
            ['guid' => '', 'enclosure' => null, 'link' => '', 'title' => '', 'pubDate' => ''],
        ]));

        $result = maw_mpi_sync('testpod', 'podcast');

        maw_assert_same(3, count(maw_mpi_posts()), 'three identifiable episodes are indexed');
        maw_assert_same(1, $result['skipped'], 'the episode with no identity at all is skipped');

        $kinds = array_map(
            static function (array $row): string {
                $key = (string) maw_mpi_meta((int) $row['ID'], PostIndex::META_SOURCE_KEY);

                return explode(':', $key)[2] ?? '';
            },
            maw_mpi_posts()
        );

        maw_assert_same(['enc', 'link', 'hash'], $kinds, 'each tier is recorded in the key so it is diagnosable');
    },

    // -----------------------------------------------------------------------
    // Podcast synchronization
    // -----------------------------------------------------------------------

    'a multi episode feed indexes every episode' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree([
            maw_mpi_podcast_item(['guid' => 'ep-1', 'title' => 'One']),
            maw_mpi_podcast_item(['guid' => 'ep-2', 'title' => 'Two']),
        ]));

        $result = maw_mpi_sync('testpod', 'podcast');

        maw_assert_same(2, $result['created'], 'both episodes are indexed');
        maw_assert_same(0, count(MawTestState::$httpRequests), 'no external request is made');
    },

    'a single episode stored as a bare associative array indexes once' => static function (): void {
        maw_mpi_register_types();

        // The wp_head refresh path leaves a one-episode feed unwrapped.
        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree(maw_mpi_podcast_item(['guid' => 'solo-1'])));

        $result = maw_mpi_sync('testpod', 'podcast');

        maw_assert_same(1, $result['created'], 'exactly one post is created');
        maw_assert_same(1, count(maw_mpi_posts()), 'the episode fields are not mistaken for a list of episodes');
    },

    'a single episode with no title element still indexes once' => static function (): void {
        maw_mpi_register_types();

        // This is the shape that defeats an isset($items['title']) check: with no
        // title key the episode's own fields would be iterated as a list.
        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree([
            'guid'    => 'no-title-1',
            'link'    => 'https://example.com/untitled',
            'pubDate' => 'Mon, 06 Jan 2025 00:00:00 +0000',
        ]));

        $result = maw_mpi_sync('testpod', 'podcast');

        maw_assert_same(1, $result['created'], 'exactly one post is created');
        maw_assert_same('', maw_mpi_posts()[0]['post_title'], 'the missing title becomes an empty post title');
        maw_assert_same(
            'no-title-1',
            maw_mpi_meta((int) maw_mpi_posts()[0]['ID'], PostIndex::META_PODCAST_GUID),
            'the guid is still stored'
        );
    },

    'podcast fields map to post fields and meta' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree([
            maw_mpi_podcast_item([
                'title'       => 'Mapped Podcast Episode',
                'description' => 'Podcast description.',
                'guid'        => 'mapped-guid',
            ]),
        ]));

        maw_mpi_sync('testpod', 'podcast');

        $post = maw_mpi_posts()[0];
        $id   = (int) $post['ID'];

        maw_assert_same('Mapped Podcast Episode', $post['post_title'], 'the episode title becomes the post title');
        maw_assert_same('Podcast description.', $post['post_excerpt'], 'the description becomes the excerpt');
        maw_assert_same('podcast', maw_mpi_meta($id, PostIndex::META_MEDIA_TYPE), 'the media type is stored');
        maw_assert_same('mapped-guid', maw_mpi_meta($id, PostIndex::META_PODCAST_GUID), 'the guid is stored');
        maw_assert_same('mapped-guid', maw_mpi_meta($id, PostIndex::META_SOURCE_ID), 'the guid is the source id');
        maw_assert_same(
            null,
            maw_mpi_meta($id, PostIndex::META_YOUTUBE_ID),
            'no youtube id row exists for a podcast episode'
        );
        maw_assert_same(
            '2025-01-06T00:00:00+00:00',
            maw_mpi_meta($id, PostIndex::META_PUBLISHED_DATE),
            'the RFC-2822 pubDate is normalized to ISO-8601'
        );
        maw_assert_same(['testpod'], maw_mpi_terms($id, PostIndex::TAX_PLAYLIST), 'the playlist term is assigned');
        maw_assert_same(['podcast'], maw_mpi_terms($id, PostIndex::TAX_MEDIA_TYPE), 'the media type term is assigned');
    },

    'malformed podcast values produce no notices and no fatals' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree([
            maw_mpi_podcast_item([
                'guid'        => ['@attributes' => ['isPermaLink' => 'false']],
                'title'       => ['nested' => ['deeper' => 'Deep Title']],
                'description' => [],
                'enclosure'   => 'not-an-array',
                'pubDate'     => ['@attributes' => ['x' => 'y']],
                'link'        => 'https://example.com/malformed',
            ]),
        ]));

        $result = maw_mpi_sync('testpod', 'podcast');

        maw_assert_same(1, $result['created'], 'a malformed episode still indexes via a fallback identity');
        maw_assert_same('Deep Title', maw_mpi_posts()[0]['post_title'], 'a nested title is flattened to text');
        maw_assert_same('', maw_mpi_posts()[0]['post_excerpt'], 'an empty-array description becomes an empty string');
    },

    'a feed with no episodes indexes nothing without erroring' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree(null));

        $result = maw_mpi_sync('testpod', 'podcast');

        maw_assert_same(true, $result['ok'], 'an empty feed is not an error');
        maw_assert_same(0, count(maw_mpi_posts()), 'nothing is indexed');
    },

    // -----------------------------------------------------------------------
    // Transcripts
    // -----------------------------------------------------------------------

    'a transcript becomes post content and its metadata becomes post meta' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item([
                'transcript'          => 'The full transcript text.',
                'transcript_status'   => 'complete',
                'transcript_url'      => 'https://example.com/transcripts/vid00001',
                'transcript_language' => 'en-GB',
            ]),
        ]);

        maw_mpi_sync();

        $post = maw_mpi_posts()[0];
        $id   = (int) $post['ID'];

        maw_assert_same('The full transcript text.', $post['post_content'], 'the transcript lands in post content');
        maw_assert_same('complete', maw_mpi_meta($id, PostIndex::META_TRANSCRIPT_STATUS), 'the status is stored');
        maw_assert_same(
            'https://example.com/transcripts/vid00001',
            maw_mpi_meta($id, PostIndex::META_TRANSCRIPT_URL),
            'the transcript url is stored'
        );
        maw_assert_same('en-GB', maw_mpi_meta($id, PostIndex::META_TRANSCRIPT_LANGUAGE), 'the language is stored');
    },

    'a refresh without transcript keys never offers to overwrite the body' => static function (): void {
        maw_mpi_register_types();

        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item([
                'transcript'        => 'Precious transcript.',
                'transcript_status' => 'complete',
            ]),
        ]);
        maw_mpi_sync();

        $id = (int) maw_mpi_posts()[0]['ID'];
        MawTestPosts::$updateLog = [];

        // A plain API refresh: no transcript keys at all, and a changed title so
        // the update is not short-circuited as unchanged.
        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['title' => 'Refreshed Title'])]);
        maw_mpi_sync();

        maw_assert_same(
            'Precious transcript.',
            maw_mpi_posts()[0]['post_content'],
            'the transcript survives a routine refresh'
        );
        maw_assert_same(
            'complete',
            maw_mpi_meta($id, PostIndex::META_TRANSCRIPT_STATUS),
            'transcript metadata survives too'
        );
        maw_assert_same('Refreshed Title', maw_mpi_posts()[0]['post_title'], 'the refreshed title is applied');

        maw_assert(count(MawTestPosts::$updateLog) > 0, 'an update was performed');
        foreach (MawTestPosts::$updateLog as $index => $postarr) {
            maw_assert_same(
                false,
                array_key_exists('post_content', $postarr),
                'update ' . $index . ' never even submitted post_content'
            );
        }
    },

    'an explicitly empty transcript clears the body' => static function (): void {
        maw_mpi_register_types();

        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['transcript' => 'To be removed.'])]);
        maw_mpi_sync();

        maw_assert_same('To be removed.', maw_mpi_posts()[0]['post_content'], 'the transcript is stored first');

        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['transcript' => ''])]);
        maw_mpi_sync();

        maw_assert_same(
            '',
            maw_mpi_posts()[0]['post_content'],
            'an explicit empty transcript is an intentional clear, not an omission'
        );
        maw_assert_same(1, count(maw_mpi_posts()), 'clearing does not create a second post');
    },

    'an explicitly empty transcript status deletes that meta row' => static function (): void {
        maw_mpi_register_types();

        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['transcript_status' => 'pending'])]);
        maw_mpi_sync();

        $id = (int) maw_mpi_posts()[0]['ID'];
        maw_assert_same('pending', maw_mpi_meta($id, PostIndex::META_TRANSCRIPT_STATUS), 'the status is stored');

        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['transcript_status' => ''])]);
        maw_mpi_sync();

        maw_assert_same(
            null,
            maw_mpi_meta($id, PostIndex::META_TRANSCRIPT_STATUS),
            'an explicit empty value clears the row'
        );
    },

    'a later transcript update mutates the same post' => static function (): void {
        maw_mpi_register_types();

        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['transcript_status' => 'pending'])]);
        maw_mpi_sync();
        $firstId = (int) maw_mpi_posts()[0]['ID'];

        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item([
                'transcript'        => 'Arrived later.',
                'transcript_status' => 'complete',
            ]),
        ]);
        maw_mpi_sync();

        maw_assert_same(1, count(maw_mpi_posts()), 'no second post is created');
        maw_assert_same($firstId, (int) maw_mpi_posts()[0]['ID'], 'the same post is updated');
        maw_assert_same('Arrived later.', maw_mpi_posts()[0]['post_content'], 'the transcript is applied');
    },

    'a transcript is sanitized and not duplicated into public meta' => static function (): void {
        maw_mpi_register_types();

        $transcript = str_repeat('Long transcript body. ', 400) . '<script>alert(1)</script>';

        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['transcript' => $transcript])]);
        maw_mpi_sync();

        $id      = (int) maw_mpi_posts()[0]['ID'];
        $content = (string) maw_mpi_posts()[0]['post_content'];

        maw_assert(
            !str_contains($content, '<script>'),
            'the transcript is passed through the post-content sanitizer'
        );
        maw_assert(str_contains($content, 'Long transcript body.'), 'the transcript text itself is kept');

        foreach (PostIndex::publicMetaKeys() as $metaKey) {
            $value = (string) maw_mpi_meta($id, $metaKey);
            maw_assert(
                !str_contains($value, 'Long transcript body.'),
                'the transcript is not copied into ' . $metaKey
            );
        }

        maw_assert(
            !str_contains((string) maw_mpi_meta($id, PostIndex::META_SOURCE_PAYLOAD), 'Long transcript body.'),
            'the transcript is not duplicated into the internal source payload either'
        );
    },

    // -----------------------------------------------------------------------
    // Scheduling
    // -----------------------------------------------------------------------

    'a store schedules one deduplicated sync carrying no payload' => static function (): void {
        maw_mpi_boot();
        maw_queue_json(maw_youtube_page(3, null, 3));

        maw_run_youtube_load(maw_youtube_config());

        $events = maw_mpi_cron_events();

        maw_assert_same(1, count($events), 'exactly one sync event is queued');
        maw_assert_same(['testshow', 'youtube'], $events[0]['args'], 'the args are only the playlist and type');
        maw_assert_same(2, count($events[0]['args']), 'no third argument sneaks in');

        foreach ($events[0]['args'] as $arg) {
            maw_assert_same(true, is_string($arg), 'every cron argument is a plain string, never a payload');
        }
    },

    'all three store sources schedule a sync' => static function (): void {
        foreach ([
            MediaStore::SOURCE_REMOTE_REFRESH,
            MediaStore::SOURCE_SHORTCODE_WARMUP,
            MediaStore::SOURCE_MANUAL_UPDATE,
        ] as $source) {
            maw_test_reset();
            maw_mpi_boot();

            do_action(
                MediaStore::ACTION_STORED,
                [maw_mpi_youtube_item()],
                MediaStore::buildContext('testshow', 'youtube', $source)
            );

            maw_assert_same(1, count(maw_mpi_cron_events()), 'a ' . $source . ' store schedules a sync');
        }
    },

    'a second store for the same playlist adds no second pending event' => static function (): void {
        maw_mpi_boot();

        $context = MediaStore::buildContext('testshow', 'youtube', MediaStore::SOURCE_REMOTE_REFRESH);

        do_action(MediaStore::ACTION_STORED, [], $context);
        do_action(MediaStore::ACTION_STORED, [], $context);
        do_action(MediaStore::ACTION_STORED, [], $context);

        maw_assert_same(1, count(maw_mpi_cron_events()), 'repeated stores collapse into one queued job');
    },

    'playlist names are normalized so one playlist queues one event' => static function (): void {
        maw_mpi_boot();

        do_action(
            MediaStore::ACTION_STORED,
            [],
            MediaStore::buildContext('My_Show', 'youtube', MediaStore::SOURCE_REMOTE_REFRESH)
        );
        do_action(
            MediaStore::ACTION_STORED,
            [],
            MediaStore::buildContext('my_show', 'youtube', MediaStore::SOURCE_REMOTE_REFRESH)
        );

        $events = maw_mpi_cron_events();

        maw_assert_same(1, count($events), 'differing case does not queue a second event');
        maw_assert_same(['my_show', 'youtube'], $events[0]['args'], 'the args are canonicalized');
    },

    'an unusable store context schedules nothing' => static function (): void {
        maw_mpi_boot();

        do_action(MediaStore::ACTION_STORED, [], MediaStore::buildContext('', 'youtube', 'remote_refresh'));
        do_action(MediaStore::ACTION_STORED, [], MediaStore::buildContext('testshow', 'vimeo', 'remote_refresh'));

        maw_assert_same(0, count(maw_mpi_cron_events()), 'an empty playlist or unknown type queues nothing');
    },

    'a scheduled event syncs the stored payload end to end' => static function (): void {
        maw_mpi_boot();
        maw_mpi_seed_youtube();

        do_action(
            MediaStore::ACTION_STORED,
            [],
            MediaStore::buildContext('testshow', 'youtube', MediaStore::SOURCE_REMOTE_REFRESH)
        );

        maw_assert_same(0, count(maw_mpi_posts()), 'nothing is indexed inside the storing request');

        maw_mpi_run_cron();

        maw_assert_same(1, count(maw_mpi_posts()), 'the background event does the indexing');
    },

    'a held sync lock prevents concurrent processing' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube();

        OptionLock::acquire(PostIndexSync::lockName('youtube', 'testshow'), 300);

        $result = maw_mpi_sync();

        maw_assert_same(false, $result['ok'], 'the second worker refuses to run');
        maw_assert_same(
            'maw_post_index_locked',
            $result['error']->get_error_code(),
            'the error code names the contention'
        );
        maw_assert_same(0, count(maw_mpi_posts()), 'no post is written while another sync holds the lock');
    },

    'the sync lock is released on success and on failure' => static function (): void {
        global $wpdb;

        maw_mpi_register_types();
        maw_mpi_seed_youtube();

        maw_mpi_sync();
        maw_assert_same(
            null,
            $wpdb->peek(PostIndexSync::lockName('youtube', 'testshow')),
            'the lock is released after a successful sync'
        );

        // No stored data at all: an early return inside the locked block.
        maw_mpi_sync('emptyshow');
        maw_assert_same(
            null,
            $wpdb->peek(PostIndexSync::lockName('youtube', 'emptyshow')),
            'the lock is released when there is nothing to sync'
        );
    },

    'the sync lock is released when an upsert throws' => static function (): void {
        global $wpdb;

        maw_mpi_register_types();
        maw_mpi_seed_youtube();

        // A throwing hook inside the locked block stands in for any fatal path.
        maw_on(PostIndex::FILTER_POST_TYPE_ARGS, static function ($args) {
            return $args;
        });

        $threw = false;
        try {
            add_action(PostIndexSync::ACTION_SYNCED, static function (): void {
                throw new RuntimeException('listener exploded');
            }, 10, 2);

            maw_mpi_sync();
        } catch (Throwable $e) {
            $threw = true;
        }

        maw_assert_same(false, $threw, 'a throwing listener is absorbed rather than escaping the sync');
        maw_assert_same(
            null,
            $wpdb->peek(PostIndexSync::lockName('youtube', 'testshow')),
            'the lock is released regardless'
        );
    },

    'the sync lock namespace is separate from the storage lock' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube();

        // A refresh persisting data must not block the index, and vice versa.
        OptionLock::acquire(OptionLock::dataLockName('youtube', 'testshow'), 30);

        $result = maw_mpi_sync();

        maw_assert_same(true, $result['ok'], 'the storage lock does not block an index sync');
    },

    'a sync refuses to run before the post type is registered' => static function (): void {
        maw_mpi_seed_youtube();

        $result = maw_mpi_sync();

        maw_assert_same(false, $result['ok'], 'the sync refuses to run');
        maw_assert_same(
            'maw_post_index_unregistered',
            $result['error']->get_error_code(),
            'the error names the missing post type rather than writing orphan rows'
        );
        maw_assert_same(0, count(MawTestPosts::$posts), 'no post of any type is created');
    },

    'a sync with no stored data reports it and writes nothing' => static function (): void {
        maw_mpi_register_types();

        $result = maw_mpi_sync('nothingstored');

        maw_assert_same(false, $result['ok'], 'the sync reports failure');
        maw_assert_same('maw_no_stored_data', $result['error']->get_error_code(), 'the error code names the cause');
        maw_assert_same(0, count(maw_mpi_posts()), 'nothing is indexed');
        maw_assert_same(0, count(MawTestState::$httpRequests), 'and no attempt is made to fetch it');
    },

    // -----------------------------------------------------------------------
    // The synced action
    // -----------------------------------------------------------------------

    'the synced action fires once with the documented result and context' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item(['id' => 'vidaaa']),
            maw_mpi_youtube_item(['id' => 'vidbbb']),
        ]);

        maw_mpi_sync();

        maw_assert_same(1, maw_hook_count(PostIndexSync::ACTION_SYNCED), 'the action fires once per sync');

        [$result, $context] = maw_hook_args(PostIndexSync::ACTION_SYNCED);

        foreach (['created', 'updated', 'unchanged', 'failed', 'skipped', 'duplicates', 'total_seen'] as $key) {
            maw_assert(array_key_exists($key, $result), 'the result carries a ' . $key . ' count');
        }

        maw_assert_same(2, $result['created'], 'the counts describe the run');
        maw_assert_same('testshow', $context['playlist_name'], 'the context carries the playlist name');
        maw_assert_same('youtube', $context['media_type'], 'the context carries the media type');
        maw_assert_same('manual_rebuild', $context['source'], 'the context distinguishes how the sync was triggered');
    },

    // -----------------------------------------------------------------------
    // Missing items
    // -----------------------------------------------------------------------

    'an item missing from a later payload is neither deleted nor trashed' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item(['id' => 'vidkeep', 'title' => 'Kept']),
            maw_mpi_youtube_item(['id' => 'vidgone', 'title' => 'Vanishes']),
        ]);
        maw_mpi_sync();

        $goneId = (int) maw_mpi_post_by_key(maw_mpi_youtube_key('vidgone'))['ID'];
        $keptId = (int) maw_mpi_post_by_key(maw_mpi_youtube_key('vidkeep'))['ID'];

        // Backdate both stamps so "was it refreshed?" is unambiguous. Two syncs
        // in the same test otherwise land in the same second, which would make a
        // greater-than comparison prove nothing.
        $backdated = 1700000000;
        update_post_meta($goneId, PostIndex::META_LAST_SEEN, $backdated);
        update_post_meta($keptId, PostIndex::META_LAST_SEEN, $backdated);

        // A truncated payload — a shortened feed, a load-first-six setting, a
        // temporarily private video — must not destroy the index.
        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item(['id' => 'vidkeep', 'title' => 'Kept'])]);
        maw_mpi_sync();

        maw_assert_same(2, count(maw_mpi_posts()), 'the absent item keeps its post');
        maw_assert_same('publish', maw_mpi_posts()[1]['post_status'], 'and is not trashed');
        maw_assert_same(
            'Vanishes',
            maw_mpi_posts()[1]['post_title'],
            'the absent item keeps its content untouched'
        );
        maw_assert_same(
            $backdated,
            (int) maw_mpi_meta($goneId, PostIndex::META_LAST_SEEN),
            'its last-seen stamp stops advancing, which is how a stale item is identified'
        );
        maw_assert(
            (int) maw_mpi_meta($keptId, PostIndex::META_LAST_SEEN) > $backdated,
            'the item still present has its last-seen stamp refreshed'
        );
    },

    // -----------------------------------------------------------------------
    // Backfill and rebuild
    // -----------------------------------------------------------------------

    'the backfill schedules every configured playlist once and marks the schema' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_configure([
            ['type' => 'youtube', 'playlist_name' => 'showone'],
            ['type' => 'podcast', 'playlist_name' => 'podone'],
            ['type' => 'vimeo', 'playlist_name' => 'ignored'],
        ]);

        $summary = Backfill::run();

        maw_assert_same(true, $summary['ok'], 'the backfill succeeds');
        maw_assert_same(2, $summary['scheduled'], 'only supported media types are scheduled');
        maw_assert_same(2, count(maw_mpi_cron_events()), 'one event per configured playlist');
        maw_assert_same(
            PostIndex::SCHEMA_VERSION,
            (int) get_option(PostIndex::OPTION_SCHEMA_VERSION, 0),
            'the schema version is marked once scheduling succeeded'
        );
        maw_assert_same(0, count(MawTestState::$httpRequests), 'no external request is made');
    },

    'the backfill does not schedule again once the schema is current' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_configure([['type' => 'youtube', 'playlist_name' => 'showone']]);

        Backfill::run();
        wp_clear_scheduled_hook(PostIndexSync::CRON_HOOK);

        $second = Backfill::run();

        maw_assert_same(0, $second['scheduled'], 'a second run schedules nothing');
        maw_assert_same(0, count(maw_mpi_cron_events()), 'no event is queued the second time');
    },

    'a site with no configured playlists still marks the schema' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_configure([]);

        $summary = Backfill::run();

        maw_assert_same(true, $summary['marked'], 'an empty install is a successful backfill');
        maw_assert_same(
            PostIndex::SCHEMA_VERSION,
            (int) get_option(PostIndex::OPTION_SCHEMA_VERSION, 0),
            'so admin requests stop re-checking forever'
        );
    },

    'the backfill deduplicates a playlist configured twice' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_configure([
            ['type' => 'youtube', 'playlist_name' => 'showone'],
            ['type' => 'youtube', 'playlist_name' => 'showone'],
        ]);

        $summary = Backfill::run();

        maw_assert_same(1, $summary['pairs'], 'the duplicate configuration collapses to one pair');
        maw_assert_same(1, count(maw_mpi_cron_events()), 'and to one queued event');
    },

    'the rebuild function schedules all, one type, or one playlist' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_configure([
            ['type' => 'youtube', 'playlist_name' => 'showone'],
            ['type' => 'podcast', 'playlist_name' => 'podone'],
        ]);

        $all = media_api_widget_rebuild_post_index();
        maw_assert_same(2, $all['scheduled'], 'no arguments schedules every configured playlist');
        maw_assert_same(true, $all['ok'], 'the summary reports success');

        wp_clear_scheduled_hook(PostIndexSync::CRON_HOOK);
        $byType = media_api_widget_rebuild_post_index(null, 'podcast');
        maw_assert_same(1, $byType['scheduled'], 'a media type narrows it to that type');
        maw_assert_same('podone', $byType['playlists'][0]['playlist_name'], 'the right playlist is queued');

        wp_clear_scheduled_hook(PostIndexSync::CRON_HOOK);
        $byName = media_api_widget_rebuild_post_index('showone');
        maw_assert_same(1, $byName['scheduled'], 'a playlist name narrows it to that playlist');

        maw_assert_same(0, count(MawTestState::$httpRequests), 'rebuilding makes no external request');
    },

    'the rebuild function validates its arguments' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_configure([['type' => 'youtube', 'playlist_name' => 'showone']]);

        $badType = media_api_widget_rebuild_post_index(null, 'vimeo');
        maw_assert_same(true, is_wp_error($badType), 'an unsupported media type is refused');
        maw_assert_same('maw_unsupported_media_type', $badType->get_error_code(), 'the error code names the problem');

        $badName = media_api_widget_rebuild_post_index('!!!');
        maw_assert_same(true, is_wp_error($badName), 'an unusable playlist name is refused');
        maw_assert_same('maw_invalid_playlist_name', $badName->get_error_code(), 'the error code names the problem');

        $unknown = media_api_widget_rebuild_post_index('neverconfigured');
        maw_assert_same(true, is_wp_error($unknown), 'an unknown playlist is refused without a media type');
        maw_assert_same('maw_unknown_playlist', $unknown->get_error_code(), 'the error code names the problem');

        maw_assert_same(0, count(maw_mpi_cron_events()), 'no work is queued for a malformed request');
    },

    'the rebuild function refuses to run before the post type exists' => static function (): void {
        $result = media_api_widget_rebuild_post_index();

        maw_assert_same(true, is_wp_error($result), 'calling too early is refused');
        maw_assert_same(
            'maw_post_index_unregistered',
            $result->get_error_code(),
            'failing loudly beats queueing work that would create orphan rows'
        );
    },

    'an unconfigured playlist can still be rebuilt with an explicit type' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_configure([]);

        // Stored data outlives its config row, so re-indexing a just-removed
        // playlist is a legitimate request.
        $result = media_api_widget_rebuild_post_index('orphanshow', 'youtube');

        maw_assert_same(false, is_wp_error($result), 'the request is accepted');
        maw_assert_same(1, $result['scheduled'], 'the explicit pair is queued');
    },

    // -----------------------------------------------------------------------
    // Idempotence
    // -----------------------------------------------------------------------

    'syncing the same payload repeatedly produces no further writes' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [
            maw_mpi_youtube_item(['id' => 'vidaaa']),
            maw_mpi_youtube_item(['id' => 'vidbbb']),
        ]);

        maw_mpi_sync();
        MawTestPosts::$insertLog = [];
        MawTestPosts::$updateLog = [];

        $second = maw_mpi_sync();
        $third  = maw_mpi_sync();

        maw_assert_same(2, $second['unchanged'], 'the second pass reports both items unchanged');
        maw_assert_same(2, $third['unchanged'], 'and so does the third');
        maw_assert_same(0, count(MawTestPosts::$insertLog), 'no post is inserted again');
        maw_assert_same(0, count(MawTestPosts::$updateLog), 'no post is updated again');
        maw_assert_same(2, count(maw_mpi_posts()), 'the index still holds exactly two posts');
    },

    'a failed insert is reported without aborting the playlist' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', [maw_mpi_youtube_item()]);

        MawTestPosts::$failInserts = true;
        $result = maw_mpi_sync();
        MawTestPosts::$failInserts = false;

        maw_assert_same(false, $result['ok'], 'the sync reports failure');
        maw_assert_same(1, $result['failed'], 'the failed item is counted');
        maw_assert_same(0, count(maw_mpi_posts()), 'nothing is indexed');
    },
];
