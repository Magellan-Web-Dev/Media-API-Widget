<?php
/**
 * Tests per-playlist WordPress post storage ("Store playlist items as
 * WordPress posts").
 *
 * The rule that carries the most weight here is the deletion rule. A post is
 * removed only when a *complete, validated refresh* says its item has left the
 * source. Every other way the stored data can shrink — a failed or partial
 * fetch, malformed data, a cache miss, the six-item limit, an enrichment write,
 * an item that cannot be identified, a write that failed part-way, a newer
 * refresh arriving mid-pass — must leave every post in place. A builder loop
 * that silently loses half its posts after a timeout is the failure these cases
 * exist to prevent.
 *
 * The other guarantees: storage is off unless a playlist turns it on, posts
 * keep their IDs and editorial enrichment across updates, background batches
 * are bounded, deduplicated and retried a bounded number of times, turning
 * storage off stops work but keeps posts, and the admin actions check both the
 * capability and a nonce bound to the action and the playlist.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/wp-shortcodes.php';

use MediaApiWidget\Admin\Menu;
use MediaApiWidget\Config\Options;
use MediaApiWidget\PostIndex\ItemMapper;
use MediaApiWidget\PostIndex\PostFields;
use MediaApiWidget\PostIndex\PostIndex;
use MediaApiWidget\PostIndex\PostIndexAdmin;
use MediaApiWidget\PostIndex\PostIndexSync;
use MediaApiWidget\PostIndex\RefreshManifest;
use MediaApiWidget\PostIndex\SyncStatus;
use MediaApiWidget\Support\MediaStore;
use MediaApiWidget\Support\OptionLock;
use MediaApiWidget\Support\PodcastNumbering;

if (!class_exists('MawPsQuery')) {
    /**
     * Minimal WP_Query stand-in: get()/set() over a vars array.
     */
    final class MawPsQuery
    {
        /** @param array<string,mixed> $vars Query vars. */
        public function __construct(public array $vars = []) {}

        /** @return mixed */
        public function get(string $key)
        {
            return $this->vars[$key] ?? '';
        }

        /** @param mixed $value */
        public function set(string $key, $value): void
        {
            $this->vars[$key] = $value;
        }
    }
}

if (!class_exists('MawPsRestResponse')) {
    /**
     * Minimal WP_REST_Response stand-in.
     */
    final class MawPsRestResponse
    {
        /** @param array<string,mixed> $data Response data. */
        public function __construct(public array $data) {}

        /** @return array<string,mixed> */
        public function get_data(): array
        {
            return $this->data;
        }

        /** @param array<string,mixed> $data */
        public function set_data(array $data): void
        {
            $this->data = $data;
        }
    }
}

/**
 * Synchronizes a playlist immediately and returns the result.
 *
 * @param string $playlist Playlist slug.
 * @param string $type     'youtube' or 'podcast'.
 * @return array<string,mixed> Result.
 */
function maw_ps_sync(string $playlist = 'testshow', string $type = 'youtube'): array
{
    return PostIndexSync::sync($playlist, $type, PostIndexSync::SOURCE_MANUAL);
}

/**
 * Builds a parsed YouTube item list, one item per video id.
 *
 * @param array<int,string>                  $ids       Video ids.
 * @param array<string,array<string,mixed>>  $overrides Per-id field overrides.
 * @return array<int,array<string,mixed>> Items.
 */
function maw_ps_items(array $ids, array $overrides = []): array
{
    return array_map(static fn (string $id): array => maw_mpi_youtube_item(array_merge([
        'id'          => $id,
        'title'       => 'Title ' . $id,
        'description' => 'About ' . $id,
        'thumbnail'   => ['url' => 'https://i.example.com/' . $id . '.jpg', 'width' => 1280, 'height' => 720],
    ], $overrides[$id] ?? [])), $ids);
}

/**
 * @param string $videoId  Video id.
 * @param string $playlist Playlist slug.
 * @return string Source key.
 */
function maw_ps_key(string $videoId, string $playlist = 'testshow'): string
{
    return ItemMapper::sourceKey('youtube', $playlist, 'vid', $videoId);
}

/**
 * @param string $videoId  Video id.
 * @param string $playlist Playlist slug.
 * @return int Post ID, or 0 when the item has no post.
 */
function maw_ps_id(string $videoId, string $playlist = 'testshow'): int
{
    $row = maw_mpi_post_by_key(maw_ps_key($videoId, $playlist));

    return $row === null ? 0 : (int) $row['ID'];
}

/**
 * @param array<int,string> $videoIds Video ids.
 * @return array<string,int> Video id => post ID.
 */
function maw_ps_ids(array $videoIds): array
{
    $out = [];
    foreach ($videoIds as $videoId) {
        $out[$videoId] = maw_ps_id($videoId);
    }

    return $out;
}

/**
 * Runs a real YouTube refresh for "testshow" against queued responses.
 *
 * Clears the fetch-interval stamp and the error / in-progress transients first
 * so several refreshes can run in one case.
 *
 * @param array<int,mixed>    $responses Decoded pages, 'error' for a transport
 *                                       failure, or ['raw' => body].
 * @param array<string,mixed> $config    Config overrides.
 * @return array<string,mixed> Loader state.
 */
function maw_ps_refresh(array $responses, array $config = []): array
{
    delete_option('maw_yt_last_fetched_testshow');
    delete_transient('testshow_youtube_error');
    delete_transient('testshow_youtube_request_in_progress');

    foreach ($responses as $response) {
        if ($response === 'error') {
            maw_queue_error();
        } elseif (is_array($response) && isset($response['raw'])) {
            maw_queue_raw((string) $response['raw']);
        } else {
            maw_queue_json($response);
        }
    }

    return maw_run_youtube_load(maw_youtube_config($config));
}

/**
 * @return array<int,string> Video ids of the stored "testshow" data, in order.
 */
function maw_ps_stored_ids(): array
{
    $data = media_api_widget_get_stored_data('testshow', 'youtube');

    return is_array($data) ? array_map(static fn (array $item): string => (string) $item['id'], $data) : [];
}

/**
 * @return int Current manifest generation for "testshow" (0 when none).
 */
function maw_ps_generation(): int
{
    return (int) (RefreshManifest::get('testshow', 'youtube')['generation'] ?? 0);
}

return [

    // -----------------------------------------------------------------------
    // The switch
    // -----------------------------------------------------------------------

    'post storage is off unless the playlist turns it on' => static function (): void {
        maw_mpi_register_types();
        (new PostIndexSync())->register();
        Options::setMediaItems([['type' => 'youtube', 'playlist_name' => 'testshow', 'load_full_playlist' => true]]);

        maw_ps_refresh([maw_youtube_page(3, null, 3)]);
        maw_assert_same(3, count(maw_ps_stored_ids()), 'the refresh itself still stores the playlist');
        maw_assert_same(null, RefreshManifest::get('testshow', 'youtube'), 'no refresh record is kept for a playlist without post storage');
        maw_assert_same([], maw_mpi_cron_events(), 'nothing is queued when its data is stored');

        $result = maw_ps_sync();
        maw_assert_same('maw_post_storage_disabled', $result['error'] ? $result['error']->get_error_code() : null, 'a direct sync is refused');
        maw_assert_same([], maw_mpi_posts(), 'no posts are created');
        maw_assert_same(false, PostIndexSync::scheduleSync('testshow', 'youtube'), 'nothing can be scheduled');
        maw_assert(is_wp_error(PostIndexSync::requestResync('testshow', 'youtube')), 'a resync is refused');
        maw_assert_same([], Options::postStoragePlaylists(), 'no playlist is listed as storing posts');
    },

    'only an explicit on value enables storage' => static function (): void {
        foreach ([true, 1, '1', 'true'] as $on) {
            maw_assert_same(true, Options::rowStoresPosts(['store_as_posts' => $on]), 'on: ' . var_export($on, true));
        }
        foreach ([false, 0, '0', '', 'yes', 'on', null, []] as $off) {
            maw_assert_same(false, Options::rowStoresPosts(['store_as_posts' => $off]), 'off: ' . var_export($off, true));
        }
        maw_assert_same(false, Options::rowStoresPosts([]), 'a row without the setting is off');
    },

    'enabling one playlist leaves every other playlist alone' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        maw_mpi_seed_youtube('othershow', maw_ps_items(['vidbbbb1']), false);
        maw_mpi_enable('othershow', 'youtube', false);

        maw_ps_sync();
        $other = maw_ps_sync('othershow');

        maw_assert_same(1, count(maw_mpi_posts()), 'only the enabled playlist gets posts');
        maw_assert(maw_ps_id('vidaaaa1') > 0, 'the enabled playlist item is stored');
        maw_assert_same('maw_post_storage_disabled', $other['error']->get_error_code(), 'the other playlist is refused');
        maw_assert_same([['testshow', 'youtube']], Options::postStoragePlaylists(), 'only the enabled playlist is listed');
    },

    'turning storage on queues an initial population that adds but never removes' => static function (): void {
        maw_mpi_register_types();
        (new PostIndexSync())->register();

        // An earlier storage period left posts behind.
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1', 'vidcccc1']));
        maw_mpi_record_refresh();
        maw_ps_sync();
        $before = Options::getMediaItems();
        maw_mpi_enable('testshow', 'youtube', false);
        PostIndexSync::applySettingsChange($before, Options::getMediaItems());

        // While off, the data changed: C left, D arrived.
        set_transient('youtube_testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1', 'viddddd1']), 7200);

        $before = Options::getMediaItems();
        maw_mpi_enable('testshow', 'youtube', true);
        $change = PostIndexSync::applySettingsChange($before, Options::getMediaItems());

        maw_assert_same([['testshow', 'youtube']], $change['enabled'], 'the playlist is reported as newly enabled');
        maw_assert_same(1, count(maw_mpi_cron_events()), 'one initial population is queued');
        maw_assert_same(SyncStatus::STATE_PENDING, SyncStatus::get('testshow', 'youtube')['state'], 'status shows it pending');
        maw_assert_same('Initial population queued.', SyncStatus::get('testshow', 'youtube')['message'], 'with an explanation');

        maw_mpi_run_cron();

        maw_assert(maw_ps_id('viddddd1') > 0, 'the new item is added');
        maw_assert(maw_ps_id('vidcccc1') > 0, 'the missing item is not removed by the initial population');
        maw_assert_same('no_refresh_record', SyncStatus::get('testshow', 'youtube')['prune_note'], 'and the status says why');
        maw_assert_same(SyncStatus::STATE_COMPLETE, SyncStatus::get('testshow', 'youtube')['state'], 'the population completes');

        // The first complete refresh after enabling may then reconcile.
        maw_mpi_record_refresh();
        maw_ps_sync();
        maw_assert_same(0, maw_ps_id('vidcccc1'), 'the next complete refresh removes the departed item');
    },

    // -----------------------------------------------------------------------
    // Precise updates
    // -----------------------------------------------------------------------

    'changed title, description and image update the same post in place' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        maw_ps_sync();
        $id = maw_ps_id('vidaaaa1');

        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1'], ['vidaaaa1' => [
            'title'       => 'Renamed',
            'description' => "New first line\nNew second line",
            'thumbnail'   => ['url' => 'https://i.example.com/new.jpg', 'width' => 1280, 'height' => 720],
        ]]));
        $result = maw_ps_sync();

        maw_assert_same(1, $result['updated'], 'reported as one update');
        maw_assert_same(1, count(maw_mpi_posts()), 'no second post');
        maw_assert_same($id, maw_ps_id('vidaaaa1'), 'the post ID is kept');
        $post = get_post($id);
        maw_assert_same('Renamed', $post->post_title, 'the title is updated');
        maw_assert_same("New first line\nNew second line", $post->post_excerpt, 'the description is updated, line breaks kept');
        maw_assert_same('https://i.example.com/new.jpg', maw_mpi_meta($id, PostIndex::META_THUMBNAIL_URL), 'the image is updated');

        $again = maw_ps_sync();
        maw_assert_same([0, 0, 1], [$again['created'], $again['updated'], $again['unchanged']], 'an unchanged item is not rewritten');
    },

    'season and episode follow the source, including being cleared' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1'], ['vidaaaa1' => ['season' => 2, 'episode' => 5]]));
        maw_ps_sync();
        $id = maw_ps_id('vidaaaa1');
        maw_assert_same([2, 5], [maw_mpi_meta($id, PostIndex::META_SEASON), maw_mpi_meta($id, PostIndex::META_EPISODE)], 'numbers are stored');

        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1'], ['vidaaaa1' => ['season' => 3, 'episode' => 1]]));
        maw_ps_sync();
        maw_assert_same([3, 1], [maw_mpi_meta($id, PostIndex::META_SEASON), maw_mpi_meta($id, PostIndex::META_EPISODE)], 'changed numbers are updated');

        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1'], ['vidaaaa1' => ['season' => -1, 'episode' => -1]]));
        maw_ps_sync();
        maw_assert_same(false, metadata_exists('post', $id, PostIndex::META_SEASON), 'an undeterminable season is cleared, not left stale');
        maw_assert_same(false, metadata_exists('post', $id, PostIndex::META_EPISODE), 'an undeterminable episode is cleared');
        maw_assert_same('', PostFields::value($id, PostFields::EPISODE_LABEL), 'and the label is blank');
    },

    'editorial and integration enrichment survives updates' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        maw_ps_sync();
        $id = maw_ps_id('vidaaaa1');

        wp_update_post(['ID' => $id, 'post_content' => 'Full transcript.', 'post_status' => 'draft']);
        update_post_meta($id, PostIndex::META_TRANSCRIPT_STATUS, 'ready');
        update_post_meta($id, 'site_custom_field', 'kept');

        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1'], ['vidaaaa1' => ['title' => 'Renamed']]));
        maw_ps_sync();

        $post = get_post($id);
        maw_assert_same('Renamed', $post->post_title, 'the source field is updated');
        maw_assert_same('Full transcript.', $post->post_content, 'the transcript is kept');
        maw_assert_same('draft', $post->post_status, 'an editor\'s status choice is kept (never republished)');
        maw_assert_same('ready', maw_mpi_meta($id, PostIndex::META_TRANSCRIPT_STATUS), 'integration meta is kept');
        maw_assert_same('kept', maw_mpi_meta($id, 'site_custom_field'), 'other custom fields are kept');
    },

    'a trashed item stays in the trash untouched' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        maw_ps_sync();
        $id = maw_ps_id('vidaaaa1');
        wp_update_post(['ID' => $id, 'post_status' => 'trash']);

        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1'], ['vidaaaa1' => ['title' => 'Renamed']]));
        maw_ps_sync();

        maw_assert_same(1, count(maw_mpi_posts()), 'no replacement post is created');
        maw_assert_same('trash', get_post($id)->post_status, 'it is not restored');
        maw_assert_same('Title vidaaaa1', get_post($id)->post_title, 'and not rewritten');
    },

    'new items are added and existing posts keep their IDs and follow the order' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1']));
        maw_ps_sync();
        $ids = maw_ps_ids(['vidaaaa1', 'vidbbbb1']);

        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidnnnn1', 'vidaaaa1', 'vidbbbb1']));
        $result = maw_ps_sync();

        maw_assert_same(1, $result['created'], 'one post is created');
        maw_assert_same($ids, maw_ps_ids(['vidaaaa1', 'vidbbbb1']), 'existing posts keep their IDs');
        maw_assert_same(
            [0, 1, 2],
            [get_post(maw_ps_id('vidnnnn1'))->menu_order, get_post(maw_ps_id('vidaaaa1'))->menu_order, get_post(maw_ps_id('vidbbbb1'))->menu_order],
            'menu order follows the playlist order'
        );
        maw_assert_same(1, maw_mpi_meta(maw_ps_id('vidaaaa1'), PostIndex::META_PLAYLIST_POSITION), 'and so does the position field');
    },

    'background batches are bounded and write every item exactly once' => static function (): void {
        maw_mpi_boot(['testshow' => 'youtube']);
        add_filter(PostIndexSync::FILTER_BATCH_SIZE, static fn () => 2);
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vid00001', 'vid00002', 'vid00003', 'vid00004', 'vid00005']));
        maw_mpi_record_refresh();
        PostIndexSync::scheduleSync('testshow', 'youtube', 0);
        PostIndexSync::scheduleSync('testshow', 'youtube', 0);
        maw_assert_same(1, count(maw_mpi_cron_events()), 'a second request while one is queued adds no event');

        maw_mpi_run_cron(1);
        maw_assert_same(2, count(maw_mpi_posts()), 'the first batch writes two items');
        $status = SyncStatus::get('testshow', 'youtube');
        maw_assert_same(SyncStatus::STATE_RUNNING, $status['state'], 'status shows it running');
        maw_assert_same(['done' => 2, 'total' => 5], $status['progress'], 'with progress');
        maw_assert_same(1, count(maw_mpi_cron_events()), 'the next batch is queued');

        $runs = maw_mpi_run_cron();
        maw_assert_same(2, $runs, 'two more batches finish the playlist');
        maw_assert_same(5, count(maw_mpi_posts()), 'every item has one post');
        maw_assert_same(SyncStatus::STATE_COMPLETE, SyncStatus::get('testshow', 'youtube')['state'], 'the pass completes');
        maw_assert_same(false, get_option(PostIndexSync::jobOptionName('testshow', 'youtube')), 'the job record is cleared');

        PostIndexSync::requestResync('testshow', 'youtube');
        maw_mpi_run_cron();
        maw_assert_same(5, count(maw_mpi_posts()), 'a full resync creates no duplicates');
        maw_assert_same(5, SyncStatus::get('testshow', 'youtube')['last_result']['unchanged'], 'and rewrites nothing');
    },

    // -----------------------------------------------------------------------
    // Removal: only on a complete refresh
    // -----------------------------------------------------------------------

    'a complete refresh removes posts for items that left the source, and only those' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1', 'vidcccc1']));
        maw_mpi_record_refresh();
        maw_ps_sync();
        $ids = maw_ps_ids(['vidaaaa1', 'vidcccc1']);

        // Posts the plugin did not generate for this playlist.
        $handMade = wp_insert_post(['post_type' => PostIndex::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Hand made']);
        update_post_meta($handMade, PostIndex::META_PLAYLIST_NAME, 'testshow');
        update_post_meta($handMade, PostIndex::META_MEDIA_TYPE, 'youtube');
        $foreignKey = wp_insert_post(['post_type' => PostIndex::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Other key']);
        update_post_meta($foreignKey, PostIndex::META_PLAYLIST_NAME, 'testshow');
        update_post_meta($foreignKey, PostIndex::META_MEDIA_TYPE, 'youtube');
        update_post_meta($foreignKey, PostIndex::META_SOURCE_KEY, maw_ps_key('vidzzzz1', 'othershow'));
        $blogPost = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Blog']);
        update_post_meta($blogPost, PostIndex::META_PLAYLIST_NAME, 'testshow');
        update_post_meta($blogPost, PostIndex::META_MEDIA_TYPE, 'youtube');
        update_post_meta($blogPost, PostIndex::META_SOURCE_KEY, maw_ps_key('vidyyyy1'));

        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidcccc1']));
        maw_mpi_record_refresh();
        $result = maw_ps_sync();

        maw_assert_same(1, $result['pruned'], 'one post is removed');
        maw_assert_same('removed', $result['prune_note'], 'and the note says so');
        maw_assert_same(0, maw_ps_id('vidbbbb1'), 'the departed item\'s post is gone');
        maw_assert_same($ids, maw_ps_ids(['vidaaaa1', 'vidcccc1']), 'remaining posts keep their IDs');
        maw_assert(get_post($handMade) !== null && get_post($foreignKey) !== null && get_post($blogPost) !== null, 'posts the plugin did not generate for this playlist are never touched');
        maw_assert(str_contains(SyncStatus::get('testshow', 'youtube')['message'], '1 removed'), 'the status reports the removal');

        $again = maw_ps_sync();
        maw_assert_same([0, 'already_reconciled'], [$again['pruned'], $again['prune_note']], 'a refresh is reconciled once');
    },

    'a valid empty playlist removes every post' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_enable('testshow', 'youtube');
        maw_ps_refresh([maw_youtube_page(2, null, 2)]);
        maw_ps_sync();
        maw_assert_same(2, count(maw_mpi_posts()), 'the playlist is stored');

        $state = maw_ps_refresh([maw_youtube_page(0, null, 0)]);
        maw_assert_same(false, $state['errorLoadingData'], 'an empty playlist is a successful refresh');
        maw_assert_same([], maw_ps_stored_ids(), 'and is stored as empty');

        $result = maw_ps_sync();
        maw_assert_same(2, $result['pruned'], 'both posts are removed');
        maw_assert_same([], maw_mpi_posts(), 'nothing is left');
    },

    'failed, partial or malformed refreshes record nothing and remove nothing' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_enable('testshow', 'youtube');
        maw_ps_refresh([maw_youtube_page(4, null, 4)]);
        maw_ps_sync();
        $token = RefreshManifest::get('testshow', 'youtube')['token'];

        $failures = [
            'transport error'      => ['error'],
            'partial pagination'   => [maw_youtube_page(2, 'NEXT', 4), 'error'],
            'malformed JSON'       => [['raw' => 'not json']],
            'malformed page shape' => [['kind' => 'youtube#playlistItemListResponse']],
        ];

        foreach ($failures as $label => $responses) {
            $state = maw_ps_refresh($responses);
            maw_assert_same(true, $state['errorLoadingData'], $label . ': the refresh fails');
            maw_assert_same(4, count(maw_ps_stored_ids()), $label . ': the stored data is untouched');
            maw_assert_same($token, RefreshManifest::get('testshow', 'youtube')['token'], $label . ': no refresh is recorded');

            $result = maw_ps_sync();
            maw_assert_same(0, $result['pruned'], $label . ': nothing is removed');
            maw_assert_same(4, count(maw_mpi_posts()), $label . ': every post is kept');
        }
    },

    'the six-item limit never removes the items beyond six' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_enable('testshow', 'youtube');
        maw_ps_refresh([maw_youtube_page(10, null, 10)], ['load_full_playlist' => true]);
        maw_ps_sync();
        maw_assert_same(10, count(maw_mpi_posts()), 'the full playlist is stored as posts');

        // Full -> limited is not an enable/disable change.
        $before = Options::getMediaItems();
        maw_mpi_enable('testshow', 'youtube', true, ['load_full_playlist' => false]);
        $change = PostIndexSync::applySettingsChange($before, Options::getMediaItems());
        maw_assert_same(['enabled' => [], 'disabled' => []], $change, 'switching to limited loading is neither');
        maw_assert(RefreshManifest::get('testshow', 'youtube') !== null, 'and keeps the refresh record');

        maw_ps_refresh([maw_youtube_page(10, null, 10)], ['load_full_playlist' => false]);
        maw_assert_same(6, count(maw_ps_stored_ids()), 'only six items are stored for the player');
        maw_assert_same(10, count(RefreshManifest::get('testshow', 'youtube')['keys']), 'but the refresh records all ten');

        $result = maw_ps_sync();
        maw_assert_same(0, $result['pruned'], 'nothing is removed');
        maw_assert_same(10, count(maw_mpi_posts()), 'all ten posts are kept');

        // A genuine departure is still detected in limited mode.
        maw_ps_refresh([maw_youtube_page(9, null, 9)], ['load_full_playlist' => false]);
        $result = maw_ps_sync();
        maw_assert_same(1, $result['pruned'], 'the item that left the playlist is removed');
        maw_assert_same(0, maw_ps_id('vid00010'), 'it is the tenth');
        maw_assert(maw_ps_id('vid00007') > 0 && maw_ps_id('vid00009') > 0, 'items seven to nine are kept');
    },

    'a cache miss removes nothing' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1']));
        maw_mpi_record_refresh();
        maw_ps_sync();

        maw_mpi_record_refresh('testshow', 'youtube', maw_ps_items(['vidaaaa1']));
        delete_transient('youtube_testshow');
        $result = maw_ps_sync();

        maw_assert_same('maw_no_stored_data', $result['error'] ? $result['error']->get_error_code() : null, 'the pass stops');
        maw_assert_same(2, count(maw_mpi_posts()), 'every post is kept');
        $status = SyncStatus::get('testshow', 'youtube');
        maw_assert_same(SyncStatus::STATE_ERROR, $status['state'], 'status shows the error');
        maw_assert(str_contains($status['message'], 'Existing posts were kept.'), 'and that posts were kept');
    },

    'an enrichment write never authorizes removal' => static function (): void {
        maw_mpi_register_types();
        (new PostIndexSync())->register();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1']));
        maw_mpi_record_refresh();
        maw_ps_sync();
        $generation = maw_ps_generation();

        $written = media_api_widget_update_stored_data('testshow', 'youtube', static fn ($data) => array_slice($data, 0, 1));
        maw_assert(!is_wp_error($written), 'the enrichment write succeeds');
        maw_assert_same(1, count(maw_mpi_cron_events()), 'it queues a sync');
        maw_assert_same($generation, maw_ps_generation(), 'but records no refresh');

        maw_mpi_run_cron();
        maw_assert_same(2, count(maw_mpi_posts()), 'the item missing from the enriched payload keeps its post');
    },

    'items that cannot be identified block removal' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1']));
        maw_mpi_record_refresh();
        maw_ps_sync();

        $withBroken = array_merge(maw_ps_items(['vidaaaa1']), [maw_mpi_youtube_item(['id' => null, 'title' => 'No id'])]);

        // The refresh itself contained an unidentifiable item.
        maw_mpi_seed_youtube('testshow', $withBroken);
        maw_mpi_record_refresh();
        maw_assert_same(false, RefreshManifest::get('testshow', 'youtube')['complete'], 'the refresh is recorded as incomplete');
        $result = maw_ps_sync();
        maw_assert_same([0, 'incomplete_refresh'], [$result['pruned'], $result['prune_note']], 'an incomplete refresh removes nothing');

        // The refresh was clean but the stored data holds an unidentifiable item.
        maw_mpi_record_refresh('testshow', 'youtube', maw_ps_items(['vidaaaa1']));
        $result = maw_ps_sync();
        maw_assert_same([0, 'unidentified_items'], [$result['pruned'], $result['prune_note']], 'unidentified stored items remove nothing');
        maw_assert(maw_ps_id('vidbbbb1') > 0, 'the post is kept');
    },

    'a partial write failure blocks removal until a clean pass' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1', 'vidcccc1']));
        maw_mpi_record_refresh();
        maw_ps_sync();

        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1'], ['vidbbbb1' => ['title' => 'Renamed']]));
        maw_mpi_record_refresh();
        MawTestPosts::$failUpdates = true;
        $result = maw_ps_sync();

        maw_assert_same(1, $result['failed'], 'the failed write is counted');
        maw_assert_same([0, 'write_failures'], [$result['pruned'], $result['prune_note']], 'nothing is removed');
        maw_assert(maw_ps_id('vidcccc1') > 0, 'the departed item\'s post is kept for now');
        maw_assert_same(SyncStatus::STATE_ERROR, SyncStatus::get('testshow', 'youtube')['state'], 'status shows the error');

        MawTestPosts::$failUpdates = false;
        $result = maw_ps_sync();
        maw_assert_same([1, 'removed'], [$result['pruned'], $result['prune_note']], 'a clean pass then reconciles');
        maw_assert_same('Renamed', get_post(maw_ps_id('vidbbbb1'))->post_title, 'and applies the update');
    },

    'failed removals are reported and retried' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1']));
        maw_mpi_record_refresh();
        maw_ps_sync();

        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        maw_mpi_record_refresh();
        MawTestPosts::$failDeletes = true;
        $result = maw_ps_sync();
        maw_assert_same([1, 'delete_failures', false], [$result['prune_failed'], $result['prune_note'], $result['ok']], 'the failure is reported');

        MawTestPosts::$failDeletes = false;
        $result = maw_ps_sync();
        maw_assert_same([1, 'removed'], [$result['pruned'], $result['prune_note']], 'the next pass retries the removal');
    },

    // -----------------------------------------------------------------------
    // Concurrency and retries
    // -----------------------------------------------------------------------

    'a newer refresh during a batched pass defers removal to the next pass' => static function (): void {
        maw_mpi_register_types();
        $sync = new PostIndexSync();
        add_filter(PostIndexSync::FILTER_BATCH_SIZE, static fn () => 1);
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1', 'vidcccc1']));
        maw_mpi_record_refresh();
        maw_ps_sync();

        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1']));
        maw_mpi_record_refresh();
        $sync->runScheduled('testshow', 'youtube');

        // Another refresh lands between batches.
        maw_mpi_record_refresh();
        $sync->runScheduled('testshow', 'youtube');

        maw_assert_same('superseded', SyncStatus::get('testshow', 'youtube')['prune_note'], 'the outdated pass does not remove');
        maw_assert(maw_ps_id('vidcccc1') > 0, 'the post is kept');

        $result = maw_ps_sync();
        maw_assert_same([1, 'removed'], [$result['pruned'], $result['prune_note']], 'the pass for the newest refresh removes it');
    },

    'data that changes between batches restarts the pass' => static function (): void {
        maw_mpi_register_types();
        $sync = new PostIndexSync();
        add_filter(PostIndexSync::FILTER_BATCH_SIZE, static fn () => 1);
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1', 'vidcccc1']));
        $sync->runScheduled('testshow', 'youtube');
        $firstHash = get_option(PostIndexSync::jobOptionName('testshow', 'youtube'))['data_hash'];

        maw_mpi_seed_youtube('testshow', maw_ps_items(['viddddd1', 'videeee1', 'vidffff1']));
        $sync->runScheduled('testshow', 'youtube');
        $job = get_option(PostIndexSync::jobOptionName('testshow', 'youtube'));

        maw_assert($job['data_hash'] !== $firstHash, 'the job is restarted for the new data');
        maw_assert_same(1, $job['offset'], 'from the first item');
        maw_assert(maw_ps_id('viddddd1') > 0, 'the new data\'s first item is written');
        maw_assert_same(0, maw_ps_id('vidbbbb1'), 'the old data is not continued');

        maw_ps_sync();
        maw_assert(maw_ps_id('videeee1') > 0 && maw_ps_id('vidffff1') > 0, 'the pass completes on the new data');
        maw_assert_same(4, count(maw_mpi_posts()), 'with no duplicates');
    },

    'a held lock requeues the batch instead of running it' => static function (): void {
        maw_mpi_boot(['testshow' => 'youtube']);
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        $lock = OptionLock::acquire(PostIndexSync::lockName('youtube', 'testshow'), 300);

        try {
            (new PostIndexSync())->runScheduled('testshow', 'youtube');
            maw_assert_same([], maw_mpi_posts(), 'nothing is written while another pass holds the lock');
            $events = maw_mpi_cron_events();
            maw_assert_same(1, count($events), 'the batch is requeued');
            maw_assert(abs(($events[0]['timestamp'] - time()) - 60) <= 2, 'about a minute later');
        } finally {
            OptionLock::release($lock);
        }
    },

    'failing passes retry a bounded number of times' => static function (): void {
        maw_mpi_boot(['testshow' => 'youtube']);
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        MawTestPosts::$failInserts = true;
        PostIndexSync::requestResync('testshow', 'youtube');

        $runs = maw_mpi_run_cron(20);
        maw_assert_same(PostIndexSync::MAX_ATTEMPTS, $runs, 'the pass is attempted the maximum number of times');
        maw_assert_same([], maw_mpi_cron_events(), 'then nothing more is queued');
        $status = SyncStatus::get('testshow', 'youtube');
        maw_assert_same([SyncStatus::STATE_ERROR, PostIndexSync::MAX_ATTEMPTS], [$status['state'], $status['attempts']], 'the status shows the error and attempts');

        MawTestPosts::$failInserts = false;
        PostIndexSync::requestResync('testshow', 'youtube');
        maw_assert_same(0, SyncStatus::get('testshow', 'youtube')['attempts'], 'a manual resync resets the attempts');
        maw_mpi_run_cron();
        maw_assert_same(1, count(maw_mpi_posts()), 'and succeeds');
    },

    // -----------------------------------------------------------------------
    // Turning storage off
    // -----------------------------------------------------------------------

    'turning storage off stops queued and in-progress work and keeps posts' => static function (): void {
        maw_mpi_boot(['testshow' => 'youtube']);
        add_filter(PostIndexSync::FILTER_BATCH_SIZE, static fn () => 1);
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1', 'vidcccc1']));
        maw_mpi_record_refresh();
        PostIndexSync::scheduleSync('testshow', 'youtube', 0);
        maw_mpi_run_cron(1);
        maw_assert_same(1, count(maw_mpi_cron_events()), 'a continuation is queued');

        $before = Options::getMediaItems();
        maw_mpi_enable('testshow', 'youtube', false);
        $change = PostIndexSync::applySettingsChange($before, Options::getMediaItems());

        maw_assert_same([['testshow', 'youtube']], $change['disabled'], 'the playlist is reported as disabled');
        maw_assert_same([], maw_mpi_cron_events(), 'queued work is removed');
        maw_assert_same(false, get_option(PostIndexSync::jobOptionName('testshow', 'youtube')), 'the pass in progress is discarded');
        maw_assert_same(null, RefreshManifest::get('testshow', 'youtube'), 'the refresh record is withdrawn');
        maw_assert_same(SyncStatus::STATE_DISABLED, SyncStatus::get('testshow', 'youtube')['state'], 'status shows storage off');
        maw_assert_same(1, count(maw_mpi_posts()), 'existing posts are kept');

        // A stale event that fires anyway does nothing.
        do_action(PostIndexSync::CRON_HOOK, 'testshow', 'youtube');
        maw_assert_same(1, count(maw_mpi_posts()), 'a stale event writes nothing');
        maw_assert_same([], maw_mpi_cron_events(), 'and queues nothing');
    },

    'storage switched off outside the settings screen stops at the next batch' => static function (): void {
        maw_mpi_boot(['testshow' => 'youtube']);
        add_filter(PostIndexSync::FILTER_BATCH_SIZE, static fn () => 1);
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1', 'vidcccc1']));
        PostIndexSync::scheduleSync('testshow', 'youtube', 0);
        maw_mpi_run_cron(1);

        maw_mpi_enable('testshow', 'youtube', false);
        maw_mpi_run_cron();

        maw_assert_same(1, count(maw_mpi_posts()), 'no further items are written');
        maw_assert_same([], maw_mpi_cron_events(), 'nothing further is queued');
        maw_assert_same(false, get_option(PostIndexSync::jobOptionName('testshow', 'youtube')), 'the pass is discarded');
    },

    'removing a playlist from the settings keeps its posts' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1']));
        maw_ps_sync();

        $change = PostIndexSync::applySettingsChange(Options::getMediaItems(), []);
        Options::setMediaItems([]);

        maw_assert_same([['testshow', 'youtube']], $change['disabled'], 'the removed playlist is stopped');
        maw_assert_same(2, count(maw_mpi_posts()), 'its posts are retained');
        maw_assert_same(2, PostIndexSync::countStoredPosts('testshow', 'youtube'), 'and still counted');
        maw_assert_same(SyncStatus::STATE_DISABLED, SyncStatus::get('testshow', 'youtube')['state'], 'status shows storage off');
    },

    // -----------------------------------------------------------------------
    // Admin actions
    // -----------------------------------------------------------------------

    'resync and delete require the manage_options capability' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        maw_ps_sync();
        MawTestPosts::$canManageOptions = false;

        foreach ([PostIndexAdmin::ACTION_RESYNC, PostIndexAdmin::ACTION_CLEANUP] as $action) {
            $result = PostIndexAdmin::process($action, [
                'playlist' => 'testshow',
                'type'     => 'youtube',
                '_wpnonce' => wp_create_nonce(PostIndexAdmin::nonceAction($action, 'testshow', 'youtube')),
            ]);
            maw_assert_same([false, 'forbidden'], [$result['ok'], $result['code']], $action . ' is refused');
        }

        maw_assert_same([], maw_mpi_cron_events(), 'nothing is queued');
        maw_assert_same(1, count(maw_mpi_posts()), 'nothing is deleted');
    },

    'resync and delete require a nonce for that action and playlist' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        $request = static fn (string $nonceAction): array => [
            'playlist' => 'testshow',
            'type'     => 'youtube',
            '_wpnonce' => wp_create_nonce($nonceAction),
        ];

        $cases = [
            'another playlist\'s nonce' => $request(PostIndexAdmin::nonceAction(PostIndexAdmin::ACTION_RESYNC, 'othershow', 'youtube')),
            'another action\'s nonce'   => $request(PostIndexAdmin::nonceAction(PostIndexAdmin::ACTION_CLEANUP, 'testshow', 'youtube')),
            'no nonce'                  => ['playlist' => 'testshow', 'type' => 'youtube'],
        ];
        foreach ($cases as $label => $fields) {
            $result = PostIndexAdmin::process(PostIndexAdmin::ACTION_RESYNC, $fields);
            maw_assert_same('nonce', $result['code'], $label . ' is refused');
        }

        MawTestPosts::$nonceValid = false;
        $result = PostIndexAdmin::process(PostIndexAdmin::ACTION_RESYNC, $request(PostIndexAdmin::nonceAction(PostIndexAdmin::ACTION_RESYNC, 'testshow', 'youtube')));
        maw_assert_same('nonce', $result['code'], 'an expired nonce is refused');
        maw_assert_same([], maw_mpi_cron_events(), 'nothing was queued by refused requests');

        MawTestPosts::$nonceValid = true;
        $result = PostIndexAdmin::process(PostIndexAdmin::ACTION_RESYNC, $request(PostIndexAdmin::nonceAction(PostIndexAdmin::ACTION_RESYNC, 'testshow', 'youtube')));
        maw_assert_same([true, 'resync'], [$result['ok'], $result['code']], 'the matching nonce is accepted');
        maw_assert_same(1, count(maw_mpi_cron_events()), 'and the resync is queued');
        maw_assert_same(SyncStatus::STATE_PENDING, SyncStatus::get('testshow', 'youtube')['state'], 'status shows it pending');
    },

    'delete stored posts removes only that playlist\'s generated posts' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1']));
        maw_mpi_seed_youtube('othershow', maw_ps_items(['vidcccc1']));
        maw_ps_sync();
        maw_ps_sync('othershow');
        $handMade = wp_insert_post(['post_type' => PostIndex::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Hand made']);
        update_post_meta($handMade, PostIndex::META_PLAYLIST_NAME, 'testshow');
        update_post_meta($handMade, PostIndex::META_MEDIA_TYPE, 'youtube');

        $result = PostIndexAdmin::process(PostIndexAdmin::ACTION_CLEANUP, [
            'playlist' => 'testshow',
            'type'     => 'youtube',
            '_wpnonce' => wp_create_nonce(PostIndexAdmin::nonceAction(PostIndexAdmin::ACTION_CLEANUP, 'testshow', 'youtube')),
        ]);

        maw_assert_same(true, $result['ok'], 'the cleanup succeeds');
        maw_assert(str_contains($result['message'], 'Deleted 2 stored posts'), 'and reports what it deleted');
        maw_assert(str_contains($result['message'], 'recreates them'), 'and warns that storage is still on');
        maw_assert_same([0, 0], [maw_ps_id('vidaaaa1'), maw_ps_id('vidbbbb1')], 'the playlist\'s posts are deleted');
        maw_assert(maw_ps_id('vidcccc1', 'othershow') > 0, 'another playlist\'s posts are kept');
        maw_assert(get_post($handMade) !== null, 'a post the plugin did not generate is kept');
    },

    'a resync is refused for a playlist without post storage' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_enable('testshow', 'youtube', false);

        $result = PostIndexAdmin::process(PostIndexAdmin::ACTION_RESYNC, [
            'playlist' => 'testshow',
            'type'     => 'youtube',
            '_wpnonce' => wp_create_nonce(PostIndexAdmin::nonceAction(PostIndexAdmin::ACTION_RESYNC, 'testshow', 'youtube')),
        ]);

        maw_assert_same([false, 'maw_post_storage_disabled'], [$result['ok'], $result['code']], 'the resync is refused');
        maw_assert_same([], maw_mpi_cron_events(), 'nothing is queued');
    },

    'status reports the last success, the post count and why nothing was removed' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1', 'vidbbbb1']));
        maw_ps_sync();

        $status = SyncStatus::get('testshow', 'youtube');
        maw_assert_same(SyncStatus::STATE_COMPLETE, $status['state'], 'the pass is complete');
        maw_assert($status['last_success_at'] > 0, 'the last success time is recorded');
        maw_assert_same(2, $status['last_result']['created'], 'with the counts');
        maw_assert_same(2, PostIndexSync::countStoredPosts('testshow', 'youtube'), 'the stored post count is available');
        maw_assert_same('no_refresh_record', $status['prune_note'], 'and the removal note');

        $notes = SyncStatus::pruneNotes();
        foreach (['removed', 'none_missing', 'no_refresh_record', 'already_reconciled', 'superseded', 'incomplete_refresh', 'unidentified_items', 'write_failures', 'delete_failures'] as $code) {
            maw_assert(isset($notes[$code]) && $notes[$code] !== '', 'note "' . $code . '" has an explanation');
        }
    },

    // -----------------------------------------------------------------------
    // Fields
    // -----------------------------------------------------------------------

    'stored posts never create media library attachments' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        maw_ps_sync();
        $id = maw_ps_id('vidaaaa1');

        maw_assert_same([], array_filter(MawTestPosts::$posts, static fn (array $row): bool => $row['post_type'] === 'attachment'), 'no attachment posts');
        maw_assert_same(false, metadata_exists('post', $id, '_thumbnail_id'), 'no featured image is set');
        maw_assert_same('https://i.example.com/vidaaaa1.jpg', maw_mpi_meta($id, PostIndex::META_THUMBNAIL_URL), 'the remote image URL is stored instead');
    },

    'the ID field is the YouTube video ID or the podcast episode ID' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['HZwLZkgFdko']));
        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree([
            maw_mpi_podcast_item(['guid' => 'e446bef0-75e8-46fc-9ddc-d965de132b75', 'title' => 'With guid']),
            maw_mpi_podcast_item(['guid' => '', 'title' => 'Without guid', 'enclosure' => ['@attributes' => ['url' => 'https://cdn.example.com/no-guid.mp3']]]),
        ]));
        maw_ps_sync();
        maw_ps_sync('testpod', 'podcast');

        maw_assert_same('HZwLZkgFdko', PostFields::value(maw_ps_id('HZwLZkgFdko'), PostIndex::META_SOURCE_ID), 'a video\'s ID is its YouTube video ID, case kept');
        $byTitle = [];
        foreach (maw_mpi_posts() as $row) {
            $byTitle[$row['post_title']] = (int) $row['ID'];
        }
        maw_assert_same('e446bef0-75e8-46fc-9ddc-d965de132b75', PostFields::value($byTitle['With guid'], PostIndex::META_SOURCE_ID), 'an episode\'s ID is its GUID');
        maw_assert_same('https://cdn.example.com/no-guid.mp3', PostFields::value($byTitle['Without guid'], PostIndex::META_SOURCE_ID), 'an episode without a GUID falls back to its audio URL');

        $options = PostFields::options();
        maw_assert_same(['post_title', 'post_excerpt', PostIndex::META_SOURCE_ID], array_slice(array_keys($options), 0, 3), 'ID is offered right after Title and Description');
        maw_assert_same('ID (YouTube video ID / podcast episode ID)', $options[PostIndex::META_SOURCE_ID], 'with a label that says what it is');
        maw_assert_same('ID', PostIndex::fieldLabels()[PostIndex::META_SOURCE_ID][0], 'the registered field is labelled ID');
        maw_assert(in_array(PostIndex::META_SOURCE_ID, PostIndex::publicMetaKeys(), true), 'and is a public, REST-visible key');
    },

    'every builder field resolves for a media item and nothing else' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1'], ['vidaaaa1' => ['season' => 2, 'episode' => 5]]));
        maw_ps_sync();
        $id = maw_ps_id('vidaaaa1');

        foreach (array_keys(PostFields::options()) as $key) {
            maw_assert(is_string(PostFields::value($id, $key)), 'field ' . $key . ' resolves to text');
        }
        maw_assert_same('Title vidaaaa1', PostFields::value($id, 'post_title'), 'title');
        maw_assert_same('About vidaaaa1', PostFields::value($id, 'post_excerpt'), 'description');
        maw_assert_same('Season 2 Episode 5', PostFields::value($id, PostFields::EPISODE_LABEL), 'season and episode label');
        maw_assert_same('1', PostFields::value($id, PostFields::POSITION_NUMBER), '1-based position');
        maw_assert_same('https://www.youtube.com/watch?v=vidaaaa1', PostFields::value($id, PostIndex::META_MEDIA_URL), 'watch URL');
        maw_assert_same('', PostFields::value($id, '_maw_source_key'), 'internal keys are not readable through the field list');
        maw_assert_same('', PostFields::value($id, 'not_a_field'), 'an unknown key is blank');

        $blog = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Blog']);
        maw_assert_same('', PostFields::value($blog, 'post_title'), 'a post that is not a media item reads blank');
    },

    // -----------------------------------------------------------------------
    // Podcast season/episode numbering
    // -----------------------------------------------------------------------

    'podcast titles are numbered by the regex groups, and never guessed' => static function (): void {
        $cases = [
            ['S2 E5: Foo', 'S(?<season>\d+)\s*E(?<episode>\d+)', ['season' => 2, 'episode' => 5], 'named groups'],
            ['Ep 4 - Bar', 'Ep (?<episode>\d+)', ['season' => -1, 'episode' => 4], 'a named episode group alone'],
            ['Season 3 Episode 12', 'Season (\d+) Episode (\d+)', ['season' => 3, 'episode' => 12], 'two positional groups are season, episode'],
            ['Episode 7: Baz', 'Episode (\d+)', ['season' => -1, 'episode' => 7], 'one positional group is the episode'],
            ['s1e2 lowercase', 'S(\d+)E(\d+)', ['season' => 1, 'episode' => 2], 'matching ignores case'],
            ['Top 10 Retirement Tips', 'Episode (\d+)', ['season' => -1, 'episode' => -1], 'a title that does not match is left blank, not guessed'],
            ['', 'Episode (\d+)', ['season' => -1, 'episode' => -1], 'an empty title'],
            ['Episode 3', '', ['season' => -1, 'episode' => -1], 'an empty pattern'],
        ];

        foreach ($cases as [$title, $regex, $expected, $label]) {
            maw_assert_same($expected, PodcastNumbering::parse($title, $regex), $label);
        }

        $errors = maw_capture_php_errors(static function (): void {
            maw_assert_same(['season' => -1, 'episode' => -1], PodcastNumbering::parse('Episode 3', '(unclosed'), 'an invalid pattern numbers nothing');
        });
        maw_assert_same([], $errors['errors'], 'and raises no warning, even to a custom error handler');
    },

    'podcast episodes are ordered newest season and episode first, or kept in feed order' => static function (): void {
        $tree = maw_mpi_podcast_tree([
            maw_mpi_podcast_item(['title' => 'S1E1 First', 'guid' => 'g1']),
            maw_mpi_podcast_item(['title' => 'S2E1 Newest season', 'guid' => 'g2']),
            maw_mpi_podcast_item(['title' => 'Bonus: Top 10', 'guid' => 'g3']),
            maw_mpi_podcast_item(['title' => 'S1E2 Second', 'guid' => 'g4']),
        ]);
        $config = ['season_episode_regex_enabled' => true, 'season_episode_regex' => 'S(\d+)E(\d+)'];
        $guids  = static fn (array $tree): array => array_column($tree['channel']['item'], 'guid');

        $sorted = PodcastNumbering::apply($tree, $config);
        maw_assert_same(['g2', 'g4', 'g1', 'g3'], $guids($sorted), 'season/episode order is the default; unnumbered episodes go last');
        maw_assert_same([2, 1], [$sorted['channel']['item'][0]['season'], $sorted['channel']['item'][0]['episode']], 'numbers are attached');
        maw_assert_same([-1, -1], [$sorted['channel']['item'][3]['season'], $sorted['channel']['item'][3]['episode']], 'the unmatched episode is unnumbered');

        $feed = PodcastNumbering::apply($tree, $config + ['podcast_sort_mode' => PodcastNumbering::SORT_FEED]);
        maw_assert_same(['g1', 'g2', 'g3', 'g4'], $guids($feed), 'feed order keeps the feed\'s order');
        maw_assert_same(2, $feed['channel']['item'][1]['season'], 'and still numbers');

        maw_assert_same($tree, PodcastNumbering::apply($tree, ['season_episode_regex_enabled' => false, 'season_episode_regex' => 'S(\d+)E(\d+)']), 'off: the feed is untouched');
        maw_assert_same($tree, PodcastNumbering::apply($tree, ['season_episode_regex_enabled' => true, 'season_episode_regex' => '  ']), 'no pattern: untouched');

        $single = PodcastNumbering::apply(maw_mpi_podcast_tree(maw_mpi_podcast_item(['title' => 'S4E9 Only'])), $config);
        maw_assert_same([4, 9], [$single['channel']['item']['season'], $single['channel']['item']['episode']], 'a single-episode feed is numbered in place');
    },

    'a podcast refresh stores regex numbers as post fields in playlist order' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_enable('testpod', 'podcast');
        maw_queue_raw('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Pod</title>'
            . '<item><title>S2 E5: Middle</title><guid>g-205</guid><description>Five</description></item>'
            . '<item><title>Bonus: Top 10 tips</title><guid>g-bonus</guid></item>'
            . '<item><title>S2 E6: Newest</title><guid>g-206</guid><description>Six</description></item>'
            . '</channel></rss>');

        maw_run_podcast_load(array_merge(maw_youtube_config(), [
            'type'                         => 'podcast',
            'podcast_platform'             => 'custom',
            'playlist_name'                => 'testpod',
            'media_data'                   => 'https://feeds.example.com/podcast.xml',
            'season_episode_regex_enabled' => true,
            'season_episode_regex'         => 'S(\d+)\s*E(\d+)',
            'podcast_sort_mode'            => PodcastNumbering::SORT_SEASON_EPISODE,
        ]));

        $manifest = RefreshManifest::get('testpod', 'podcast');
        maw_assert(is_array($manifest) && $manifest['complete'] && count($manifest['keys']) === 3, 'the whole feed is recorded as the refresh');

        maw_ps_sync('testpod', 'podcast');
        $byGuid = [];
        foreach (maw_mpi_posts() as $row) {
            $byGuid[(string) maw_mpi_meta((int) $row['ID'], PostIndex::META_PODCAST_GUID)] = $row;
        }

        maw_assert_same([0, 1, 2], [$byGuid['g-206']['menu_order'], $byGuid['g-205']['menu_order'], $byGuid['g-bonus']['menu_order']], 'newest episode first, unnumbered last');
        maw_assert_same([2, 6], [maw_mpi_meta((int) $byGuid['g-206']['ID'], PostIndex::META_SEASON), maw_mpi_meta((int) $byGuid['g-206']['ID'], PostIndex::META_EPISODE)], 'numbers are stored');
        maw_assert_same(false, metadata_exists('post', (int) $byGuid['g-bonus']['ID'], PostIndex::META_EPISODE), 'an unmatched episode has no number at all');
        maw_assert_same('Six', $byGuid['g-206']['post_excerpt'], 'the description is stored');
    },

    // -----------------------------------------------------------------------
    // Builder ordering and REST
    // -----------------------------------------------------------------------

    'front-end media item queries default to playlist order' => static function (): void {
        $default = ['menu_order' => 'ASC', 'date' => 'DESC'];
        $run = static function (array $vars): MawPsQuery {
            $query = new MawPsQuery($vars);
            PostIndex::applyDefaultOrder($query);

            return $query;
        };

        maw_assert_same($default, $run(['post_type' => PostIndex::POST_TYPE])->get('orderby'), 'no ordering: playlist order');
        maw_assert_same($default, $run(['post_type' => [PostIndex::POST_TYPE], 'orderby' => 'date', 'order' => 'DESC'])->get('orderby'), 'Elementor\'s "Date, descending" default: playlist order');
        maw_assert_same('title', $run(['post_type' => PostIndex::POST_TYPE, 'orderby' => 'title'])->get('orderby'), 'an explicit order is kept');
        maw_assert_same('date', $run(['post_type' => PostIndex::POST_TYPE, 'orderby' => 'date', 'order' => 'ASC'])->get('orderby'), '"Date, ascending" is an explicit choice');
        maw_assert_same('', $run(['post_type' => 'post'])->get('orderby'), 'other post types are untouched');
        maw_assert_same('', $run(['post_type' => [PostIndex::POST_TYPE, 'post']])->get('orderby'), 'mixed queries are untouched');

        MawTestShortcodes::reset();
        MawTestPosts::$isAdmin = true;
        maw_assert_same('', $run(['post_type' => PostIndex::POST_TYPE])->get('orderby'), 'admin screens are untouched');
        MawTestShortcodes::$doingAjax = true;
        maw_assert_same($default, $run(['post_type' => PostIndex::POST_TYPE])->get('orderby'), 'admin-ajax (the builder preview) gets playlist order');
        MawTestShortcodes::reset();
        MawTestPosts::$isAdmin = false;

        add_filter(PostIndex::FILTER_DEFAULT_ORDER, static fn () => false);
        maw_assert_same('', $run(['post_type' => PostIndex::POST_TYPE])->get('orderby'), 'the filter can turn it off');
    },

    'an explicit order is exempt from Post Types Order auto-sort' => static function (): void {
        $ignore = static fn (array $vars) => PostIndex::keepExplicitOrder(false, 'ORDER BY x', new MawPsQuery($vars));

        maw_assert_same(true, $ignore(['post_type' => PostIndex::POST_TYPE, 'orderby' => 'title']), 'title order is exempt');
        maw_assert_same(true, $ignore(['post_type' => PostIndex::POST_TYPE, 'orderby' => 'rand']), 'random order is exempt');
        maw_assert_same(false, $ignore(['post_type' => PostIndex::POST_TYPE, 'orderby' => ['menu_order' => 'ASC', 'date' => 'DESC']]), 'playlist order is left to it');
        maw_assert_same(false, $ignore(['post_type' => PostIndex::POST_TYPE]), 'the default is left to it');
        maw_assert_same(false, $ignore(['post_type' => 'post', 'orderby' => 'title']), 'other post types are left to it');
        maw_assert_same('as-is', PostIndex::keepExplicitOrder('as-is', '', null), 'no query: passthrough');
    },

    'REST reports an unknown season or episode as null, not 0' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1'], ['vidaaaa1' => ['episode' => 3]]));
        maw_ps_sync();
        $id = maw_ps_id('vidaaaa1');

        $response = PostIndex::restNullUnknownNumbers(
            new MawPsRestResponse(['meta' => [PostIndex::META_SEASON => 0, PostIndex::META_EPISODE => 3]]),
            get_post($id)
        );

        maw_assert_same(null, $response->get_data()['meta'][PostIndex::META_SEASON], 'the missing season is null');
        maw_assert_same(3, $response->get_data()['meta'][PostIndex::META_EPISODE], 'the stored episode is kept');
    },

    // -----------------------------------------------------------------------
    // Read-only admin
    // -----------------------------------------------------------------------

    'an edit screen save cannot change synchronized fields' => static function (): void {
        $id = wp_insert_post([
            'post_type'    => PostIndex::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => "It's synced",
            'post_excerpt' => 'Synced description',
            'post_content' => 'Transcript',
            'post_name'    => 'its-synced',
        ]);
        $admin  = new PostIndexAdmin();
        $forged = [
            'post_type'    => PostIndex::POST_TYPE,
            'post_title'   => 'Hacked',
            'post_excerpt' => 'Hacked',
            'post_content' => 'Hacked',
            'post_name'    => 'hacked',
            'post_status'  => 'draft',
        ];

        MawTestPosts::$isAdmin = true;
        try {
            foreach (['editpost', 'inline-save'] as $action) {
                $_POST['action'] = $action;
                $saved = $admin->protectSyncedFields($forged, ['ID' => $id]);
                maw_assert_same(
                    [wp_slash("It's synced"), 'Synced description', 'Transcript', 'its-synced'],
                    [$saved['post_title'], $saved['post_excerpt'], $saved['post_content'], $saved['post_name']],
                    $action . ': synchronized fields keep their stored values'
                );
                maw_assert_same('draft', $saved['post_status'], $action . ': the status stays editable');
            }

            $_POST['action'] = 'something-else';
            maw_assert_same($forged, $admin->protectSyncedFields($forged, ['ID' => $id]), 'other saves (synchronization, code) are not affected');

            $_POST['action'] = 'editpost';
            $other = ['post_type' => 'post', 'post_title' => 'Blog'];
            maw_assert_same($other, $admin->protectSyncedFields($other, ['ID' => $id]), 'other post types are not affected');
        } finally {
            unset($_POST['action']);
            MawTestPosts::$isAdmin = false;
        }
    },

    'list cells show only the plugin\'s values' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1'], ['vidaaaa1' => ['season' => 5]]));
        maw_ps_sync();
        $id    = maw_ps_id('vidaaaa1');
        $admin = new PostIndexAdmin();

        ob_start();
        $admin->claimOwnCell('maw_season', $id);
        echo '-'; // A site snippet printing into every custom column.
        $admin->renderColumn('maw_season', $id);
        maw_assert_same('5', ob_get_clean(), 'foreign output in the plugin\'s cell is discarded');

        ob_start();
        $admin->claimOwnCell('someone_elses_column', $id);
        echo 'theirs';
        maw_assert_same('theirs', ob_get_clean(), 'other columns are not touched');
    },

    'media items use a read-only classic screen without Quick Edit' => static function (): void {
        $admin   = new PostIndexAdmin();
        $actions = [
            'edit'                 => '<a href="post.php?post=1&amp;action=edit">Edit</a>',
            'inline hide-if-no-js' => '<button>Quick&nbsp;Edit</button>',
            'trash'                => '<a href="#">Trash</a>',
        ];

        $media = $admin->rowActions($actions, (object) ['post_type' => PostIndex::POST_TYPE]);
        maw_assert_same(['edit', 'trash'], array_keys($media), 'Quick Edit is removed');
        maw_assert(str_contains($media['edit'], '>View details<'), 'Edit reads View details');
        maw_assert_same($actions, $admin->rowActions($actions, (object) ['post_type' => 'post']), 'other post types are untouched');

        maw_assert_same(false, $admin->useClassicScreen(true, PostIndex::POST_TYPE), 'media items use the classic screen');
        maw_assert_same(true, $admin->useClassicScreen(true, 'post'), 'other post types keep the block editor');
    },

    // -----------------------------------------------------------------------
    // Elementor "Media API Click Action"
    // -----------------------------------------------------------------------

    'a click action link names the item and is safe in any context' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['HZwLZkgFdko'], ['HZwLZkgFdko' => ['title' => 'Ünïcode & "quotes" <b>']]));
        maw_mpi_seed_podcast('testpod', maw_mpi_podcast_tree([maw_mpi_podcast_item(['guid' => 'e446bef0-75e8-46fc-9ddc-d965de132b75'])]));
        maw_ps_sync();
        maw_ps_sync('testpod', 'podcast');
        $video   = maw_ps_id('HZwLZkgFdko');
        $episode = 0;
        foreach (maw_mpi_posts() as $row) {
            if (maw_mpi_meta((int) $row['ID'], PostIndex::META_MEDIA_TYPE) === 'podcast') {
                $episode = (int) $row['ID'];
            }
        }

        $url = PostFields::clickUrl($video, PostFields::CLICK_LIGHTBOX);
        maw_assert(str_starts_with($url, '#maw-media-'), 'the link is a non-navigating fragment');
        maw_assert_same(1, preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $url), 'and a valid CSS ID selector, unchanged by esc_url()');
        maw_assert_same([
            'playlist' => 'testshow',
            'type'     => 'youtube',
            'id'       => 'HZwLZkgFdko',
            'action'   => 'lightbox',
            'post'     => (string) $video,
            'title'    => get_post($video)->post_title,
        ], PostFields::parseClickUrl($url), 'it carries the playlist, type, card ID, action, post and title');

        $pod = PostFields::parseClickUrl(PostFields::clickUrl($episode, PostFields::CLICK_EVENT));
        maw_assert_same(['podcast', 'e446bef0-75e8-46fc-9ddc-d965de132b75', 'event'], [$pod['type'], $pod['id'], $pod['action']], 'an episode link carries its GUID, as podcast cards do');

        maw_assert_same('lightbox', PostFields::parseClickUrl(PostFields::clickUrl($video, 'nonsense'))['action'], 'an unknown action falls back to the lightbox');

        $styled = PostFields::parseClickUrl(PostFields::clickUrl($video, PostFields::CLICK_PLAYLIST, ['color' => '#FF0000', 'font' => '<b>Poppins</b>', 'logo' => 'javascript:alert(1)']));
        maw_assert_same('#FF0000', $styled['color'], 'the playlist action carries a valid border color');
        maw_assert_same('Poppins', $styled['font'], 'and a sanitized font');
        maw_assert(!isset($styled['logo']), 'an unsafe logo URL is dropped');
        maw_assert_same(null, PostFields::parseClickUrl(PostFields::clickUrl($video, PostFields::CLICK_PLAYLIST, ['color' => 'red; x']))['color'] ?? null, 'an invalid color is dropped');
        maw_assert(!isset(PostFields::parseClickUrl(PostFields::clickUrl($video, PostFields::CLICK_LIGHTBOX, ['color' => '#FF0000']))['color']), 'styling applies to the playlist action only');

        $blog = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Blog']);
        maw_assert_same('', PostFields::clickUrl($blog), 'a post that is not a media item gets no link');
        maw_assert_same(null, PostFields::parseClickUrl('https://example.com/#other'), 'other links are not click actions');
        maw_assert_same(['lightbox', 'playlist', 'event'], array_keys(PostFields::clickActions()), 'the three actions');
    },

    // -----------------------------------------------------------------------
    // Admin menu
    // -----------------------------------------------------------------------

    'stored posts are listed inside the Media API menu, only once a playlist stores posts' => static function (): void {
        maw_mpi_register_types();
        maw_assert_same(false, get_post_type_object(PostIndex::POST_TYPE)->show_in_menu, 'no menu entry while nothing is stored');

        MawTestPosts::reset();
        maw_mpi_enable('testshow', 'youtube');
        maw_mpi_register_types();
        maw_assert_same(Menu::SLUG, get_post_type_object(PostIndex::POST_TYPE)->show_in_menu, 'then All Media Items is listed under Media API');
    },

    'the Media API submenu starts with Playlist Settings, then the stored-post views' => static function (): void {
        global $submenu;
        $before = $submenu ?? null;
        $all    = 'edit.php?post_type=' . PostIndex::POST_TYPE;

        $submenu = [Menu::SLUG => [
            ['All Media Items', 'edit_posts', $all],
            ['Playlist Settings', 'manage_options', Menu::SLUG],
            ['API Stats', 'manage_options', Menu::STATS_SLUG],
            ['Caching', 'manage_options', Menu::CACHING_SLUG],
            ['About / Help', 'manage_options', Menu::ABOUT_SLUG],
            ['Re-Order', 'edit_posts', 'order-post-types-' . PostIndex::POST_TYPE],
            ['Media API &#45; podcast', 'edit_posts', $all . '&maw_playlist=podcast'],
            ['Media API &#45; tv', 'edit_posts', $all . '&maw_playlist=tv'],
        ]];

        try {
            Menu::orderSubmenu();
            maw_assert_same(
                [Menu::SLUG, $all, $all . '&maw_playlist=podcast', $all . '&maw_playlist=tv', Menu::STATS_SLUG, Menu::CACHING_SLUG, Menu::ABOUT_SLUG],
                array_column($submenu[Menu::SLUG], 2),
                'Playlist Settings (the page "Media API" opens), All Media Items, each playlist, then the rest; no Re-Order page'
            );
            maw_assert_same(range(0, 6), array_keys($submenu[Menu::SLUG]), 'positions are renumbered');
        } finally {
            $submenu = $before;
        }
    },

    'media item screens keep the Media API menu open on the right entry' => static function (): void {
        maw_mpi_register_types();
        maw_mpi_seed_youtube('testshow', maw_ps_items(['vidaaaa1']));
        maw_ps_sync();
        $admin = new PostIndexAdmin();
        $all   = 'edit.php?post_type=' . PostIndex::POST_TYPE;

        try {
            $GLOBALS['maw_test_screen'] = (object) ['base' => 'edit', 'post_type' => PostIndex::POST_TYPE];
            $_GET = ['post_type' => PostIndex::POST_TYPE];
            maw_assert_same(Menu::SLUG, $admin->menuParent($all), 'the list belongs to the Media API menu');
            maw_assert_same($all, $admin->highlightPlaylistView($all), 'the unfiltered list highlights All Media Items');

            $_GET[PostIndex::TAX_PLAYLIST] = 'testshow';
            maw_assert_same(PostIndexAdmin::viewSlug('testshow'), $admin->highlightPlaylistView($all), 'a filtered list highlights its playlist');

            $GLOBALS['maw_test_screen'] = (object) ['base' => 'post', 'post_type' => PostIndex::POST_TYPE];
            $_GET = ['post' => (string) maw_ps_id('vidaaaa1'), 'action' => 'edit'];
            maw_assert_same(PostIndexAdmin::viewSlug('testshow'), $admin->highlightPlaylistView('x'), 'an item\'s screen highlights its playlist');

            $GLOBALS['maw_test_screen'] = (object) ['base' => 'edit', 'post_type' => 'post'];
            maw_assert_same('edit.php', $admin->menuParent('edit.php'), 'other screens are untouched');
            maw_assert_same('edit.php', $admin->highlightPlaylistView('edit.php'), 'and keep their highlight');
        } finally {
            unset($GLOBALS['maw_test_screen']);
            $_GET = [];
        }
    },
];
