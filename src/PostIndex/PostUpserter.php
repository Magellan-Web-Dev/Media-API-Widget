<?php

namespace MediaApiWidget\PostIndex;

if (!defined('ABSPATH')) { exit; }

/**
 * Writes one index record into a maw_media_item post.
 *
 * This is the only class in the plugin that creates or updates indexed posts,
 * and it does so exclusively through the WordPress post, meta, and term APIs —
 * never through $wpdb directly. That is a correctness requirement rather than a
 * style preference: direct SQL would bypass the object cache, break on
 * multisite table prefixes, and skip the hooks other plugins rely on to keep
 * their own caches and search indexes in step.
 *
 * Two rules govern what a sync is allowed to overwrite, and they are opposites:
 *
 * - **Source-derived fields are authoritative every sync.** Title, excerpt,
 *   position, thumbnail, dates, episode, and season are re-derived from the
 *   payload each time. When the source stops supplying one, the corresponding
 *   meta row is deleted rather than left stale.
 * - **Enrichment fields are not.** A transcript, and its status, URL, and
 *   language, are written by an integration and typically absent from the API
 *   payload entirely. A refresh that does not mention them must leave them
 *   exactly as they were, or the first routine cache expiry would erase work
 *   that took a transcription service minutes to produce.
 *
 * Distinguishing "not supplied" from "explicitly emptied" is what makes the
 * second rule implementable, which is why every check uses array_key_exists()
 * rather than isset().
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class PostUpserter
{
    /**
     * Creates or updates the post for one index record.
     *
     * @param array<string,mixed> $record       An {@see ItemMapper} record.
     * @param string              $playlistName The playlist_name slug.
     * @param string              $mediaType    'youtube' or 'podcast'.
     * @param int                 $now          GMT timestamp for maw_last_seen.
     * @return array{status:string,post_id:int,duplicates:int}
     *         `status` is created, updated, unchanged, or failed.
     */
    public static function upsert(array $record, string $playlistName, string $mediaType, int $now): array
    {
        $sourceKey = (string) ($record['source_key'] ?? '');

        if ($sourceKey === '') {
            return ['status' => 'failed', 'post_id' => 0, 'duplicates' => 0];
        }

        [$existingId, $duplicates] = self::findBySourceKey($sourceKey);

        $plan = self::buildMetaPlan($record, $playlistName, $mediaType);

        if ($existingId > 0 && self::isUnchanged($existingId, $record, $plan)) {
            // Nothing to write but the sighting. Keeping this cheap is what
            // makes re-running a sync over unchanged data close to free.
            update_post_meta($existingId, PostIndex::META_LAST_SEEN, $now);

            return ['status' => 'unchanged', 'post_id' => $existingId, 'duplicates' => $duplicates];
        }

        $postarr = self::buildPostarr($record, $existingId);

        if ($existingId > 0) {
            $result = wp_update_post(wp_slash($postarr), true);
        } else {
            $result = wp_insert_post(wp_slash($postarr), true);
        }

        if (is_wp_error($result) || (int) $result === 0) {
            return ['status' => 'failed', 'post_id' => 0, 'duplicates' => $duplicates];
        }

        $postId = (int) $result;

        if ($existingId === 0) {
            // Written before anything else so a concurrent run — possible only
            // when the sync lock has degraded open because no database handle
            // was available — can find this post rather than creating a second.
            update_post_meta($postId, PostIndex::META_SOURCE_KEY, $sourceKey);
        }

        self::applyMeta($postId, $plan);
        update_post_meta($postId, PostIndex::META_LAST_SEEN, $now);
        self::assignTerms($postId, $playlistName, $mediaType);

        $payload = (string) ($record['payload'] ?? '');
        if ($payload !== '') {
            update_post_meta($postId, PostIndex::META_SOURCE_PAYLOAD, wp_slash($payload));
        }

        return [
            'status'     => $existingId > 0 ? 'updated' : 'created',
            'post_id'    => $postId,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Finds the indexed post for a source key.
     *
     * Asks for two rows rather than one so a duplicate can be detected. The
     * lowest id is treated as canonical and any extra is reported to the caller
     * but left completely alone: this index never deletes or trashes a post, so
     * surfacing the count is the correct response.
     *
     * @param string $sourceKey The composite identity.
     * @return array{0:int,1:int} [canonical post id or 0, number of extras]
     */
    private static function findBySourceKey(string $sourceKey): array
    {
        $ids = get_posts([
            'post_type'              => PostIndex::POST_TYPE,
            'post_status'            => 'any',
            'posts_per_page'         => 2,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => [
                [
                    'key'     => PostIndex::META_SOURCE_KEY,
                    'value'   => $sourceKey,
                    'compare' => '=',
                ],
            ],
        ]);

        if (!is_array($ids) || $ids === []) {
            return [0, 0];
        }

        return [(int) $ids[0], max(0, count($ids) - 1)];
    }

    /**
     * Builds the post array, omitting anything that must be preserved.
     *
     * The single most consequential detail in this feature lives here. An update
     * goes through wp_update_post(), which merges the supplied array over the
     * existing row, so a key that is *omitted* keeps its stored value.
     * wp_insert_post() performs no such merge — it fills anything missing with a
     * default, which for post_content is an empty string. Routing an update
     * through wp_insert_post() with an ID would therefore blank the body of
     * every indexed post on the next refresh, silently destroying every
     * transcript. Updates must use wp_update_post(), and post_content must be
     * absent from the array unless the payload actually supplied a transcript.
     *
     * post_author, post_date, and post_name are likewise never sent on an
     * update, so the author and any editorial changes survive resyncing.
     *
     * @param array<string,mixed> $record     An {@see ItemMapper} record.
     * @param int                 $existingId Post being updated, or 0 to create.
     * @return array<string,mixed> Arguments for wp_update_post()/wp_insert_post().
     */
    private static function buildPostarr(array $record, int $existingId): array
    {
        $postarr = [
            'post_type'      => PostIndex::POST_TYPE,
            'post_status'    => 'publish',
            'post_title'     => (string) ($record['title'] ?? ''),
            'post_excerpt'   => (string) ($record['excerpt'] ?? ''),
            'menu_order'     => (int) ($record['position'] ?? 0),
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ];

        if ($existingId > 0) {
            $postarr['ID'] = $existingId;

            // Present only when a transcript was supplied. See the method
            // docblock: omitting this key is what preserves the existing body.
            if (!empty($record['has_transcript'])) {
                $postarr['post_content'] = wp_kses_post((string) ($record['transcript'] ?? ''));
            }

            return $postarr;
        }

        // Creating. wp_insert_post() does not merge, so the body must be given
        // explicitly — an empty string when there is no transcript yet.
        $postarr['post_content'] = !empty($record['has_transcript'])
            ? wp_kses_post((string) ($record['transcript'] ?? ''))
            : '';

        // Set on create only, so ordering by date works out of the box while a
        // later editorial date change is never overwritten by a resync.
        if (($record['published_ts'] ?? null) !== null) {
            $postarr['post_date_gmt'] = gmdate('Y-m-d H:i:s', (int) $record['published_ts']);
        }

        return $postarr;
    }

    /**
     * Decides which meta rows to write and which to delete.
     *
     * Source-derived keys always appear in one list or the other. Enrichment
     * keys appear in neither unless the payload mentioned them, which is what
     * leaves an integration's transcript metadata untouched by a routine
     * refresh. A mentioned-but-empty enrichment key is an explicit clear and
     * lands in the delete list.
     *
     * @param array<string,mixed> $record       An {@see ItemMapper} record.
     * @param string              $playlistName The playlist_name slug.
     * @param string              $mediaType    'youtube' or 'podcast'.
     * @return array{write:array<string,mixed>,delete:array<int,string>}
     */
    private static function buildMetaPlan(array $record, string $playlistName, string $mediaType): array
    {
        $write = [
            PostIndex::META_PLAYLIST_NAME     => $playlistName,
            PostIndex::META_MEDIA_TYPE        => $mediaType,
            PostIndex::META_SOURCE_ID         => (string) ($record['source_id'] ?? ''),
            PostIndex::META_PLAYLIST_POSITION => (int) ($record['position'] ?? 0),
        ];

        $delete = [];

        $optional = [
            PostIndex::META_YOUTUBE_ID     => (string) ($record['youtube_id'] ?? ''),
            PostIndex::META_PODCAST_GUID   => (string) ($record['podcast_guid'] ?? ''),
            PostIndex::META_THUMBNAIL_URL  => (string) ($record['thumbnail_url'] ?? ''),
            PostIndex::META_PUBLISHED_DATE => (string) ($record['published_date'] ?? ''),
        ];

        $transcriptFields = $record['transcript_fields'] ?? [];

        if (is_array($transcriptFields)) {
            foreach ($transcriptFields as $metaKey => $value) {
                $optional[(string) $metaKey] = (string) $value;
            }
        }

        // Sanitize before deciding, so a value that survives registration as an
        // empty string — a thumbnail URL with a refused scheme, say — is deleted
        // rather than stored as a meaningless blank.
        foreach ($optional as $metaKey => $value) {
            $sanitized = PostIndex::sanitizeMetaValue((string) $metaKey, $value);

            if ((string) $sanitized === '') {
                $delete[] = (string) $metaKey;
                continue;
            }

            $write[(string) $metaKey] = $sanitized;
        }

        // null covers both a missing field and the pipeline's -1 sentinel. The
        // row is deleted rather than skipped so an item that loses its numbering
        // does not keep a stale number a page builder would still display.
        foreach ([PostIndex::META_EPISODE => 'episode', PostIndex::META_SEASON => 'season'] as $metaKey => $field) {
            $value = $record[$field] ?? null;

            if ($value === null) {
                $delete[] = $metaKey;
                continue;
            }

            $write[$metaKey] = (int) $value;
        }

        // Every planned value is stored through the same sanitizer WordPress
        // applies on write, so the plan and the database agree by construction.
        // Without this, isUnchanged() could never match and the index would
        // rewrite every post on every synchronization.
        foreach ($write as $metaKey => $value) {
            $write[$metaKey] = PostIndex::sanitizeMetaValue((string) $metaKey, $value);
        }

        return ['write' => $write, 'delete' => array_values(array_unique($delete))];
    }

    /**
     * Applies a meta plan to a post.
     *
     * @param int                                                        $postId Post to write to.
     * @param array{write:array<string,mixed>,delete:array<int,string>} $plan   The plan.
     * @return void
     */
    private static function applyMeta(int $postId, array $plan): void
    {
        foreach ($plan['write'] as $metaKey => $value) {
            // Core unslashes meta on write, so string values are slashed here to
            // stop literal backslashes being eaten out of titles and URLs.
            update_post_meta($postId, (string) $metaKey, is_string($value) ? wp_slash($value) : $value);
        }

        foreach ($plan['delete'] as $metaKey) {
            delete_post_meta($postId, (string) $metaKey);
        }
    }

    /**
     * Reports whether a post already holds exactly what this sync would write.
     *
     * maw_last_seen and the source payload are excluded from the comparison.
     * Including maw_last_seen would make every sync report "updated" and leave
     * the unchanged counter permanently at zero; the payload is a debugging
     * artefact whose churn should not trigger a post update.
     *
     * @param int                                                        $postId Post to inspect.
     * @param array<string,mixed>                                        $record The record being applied.
     * @param array{write:array<string,mixed>,delete:array<int,string>} $plan   The meta plan.
     * @return bool True when no write is needed.
     */
    private static function isUnchanged(int $postId, array $record, array $plan): bool
    {
        $post = get_post($postId);

        if ($post === null) {
            return false;
        }

        if ((string) $post->post_title !== (string) ($record['title'] ?? '')) {
            return false;
        }

        if ((string) $post->post_excerpt !== (string) ($record['excerpt'] ?? '')) {
            return false;
        }

        if ((int) $post->menu_order !== (int) ($record['position'] ?? 0)) {
            return false;
        }

        if ((string) $post->post_status !== 'publish') {
            return false;
        }

        if (!empty($record['has_transcript'])
            && (string) $post->post_content !== wp_kses_post((string) ($record['transcript'] ?? ''))) {
            return false;
        }

        $existing = get_post_meta($postId);
        $existing = is_array($existing) ? $existing : [];

        foreach ($plan['write'] as $metaKey => $value) {
            if ($metaKey === PostIndex::META_LAST_SEEN) {
                continue;
            }

            $current = $existing[$metaKey][0] ?? null;

            if ($current === null || (string) $current !== (string) $value) {
                return false;
            }
        }

        foreach ($plan['delete'] as $metaKey) {
            if (array_key_exists($metaKey, $existing)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Assigns the playlist and media type terms to a post.
     *
     * Term ids are passed rather than slugs. wp_set_object_terms() given a
     * string matches on name-or-slug and creates by name, which makes the
     * resulting slug depend on sanitize_title() and on whatever term happens to
     * exist already; ids remove that ambiguity. $append is false so a post
     * carries exactly one term per taxonomy.
     *
     * @param int    $postId       Post to classify.
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return void
     */
    private static function assignTerms(int $postId, string $playlistName, string $mediaType): void
    {
        $playlistTermId = self::ensureTermId($playlistName, PostIndex::TAX_PLAYLIST);
        if ($playlistTermId > 0) {
            wp_set_object_terms($postId, [$playlistTermId], PostIndex::TAX_PLAYLIST, false);
        }

        $mediaTypeTermId = self::ensureTermId($mediaType, PostIndex::TAX_MEDIA_TYPE);
        if ($mediaTypeTermId > 0) {
            wp_set_object_terms($postId, [$mediaTypeTermId], PostIndex::TAX_MEDIA_TYPE, false);
        }
    }

    /**
     * Returns the id of a term, creating it when absent.
     *
     * Handles the race where two concurrent syncs both find the term missing and
     * both try to create it: the loser receives a term_exists WP_Error whose
     * error data carries the id the winner created.
     *
     * @param string $slug     Term slug, also used as its name.
     * @param string $taxonomy Taxonomy to look in.
     * @return int Term id, or 0 when it could not be resolved.
     */
    private static function ensureTermId(string $slug, string $taxonomy): int
    {
        if ($slug === '' || !taxonomy_exists($taxonomy)) {
            return 0;
        }

        $existing = term_exists($slug, $taxonomy);

        if (is_array($existing) && isset($existing['term_id'])) {
            return (int) $existing['term_id'];
        }

        if (is_numeric($existing) && (int) $existing > 0) {
            return (int) $existing;
        }

        $created = wp_insert_term($slug, $taxonomy, ['slug' => $slug]);

        if (is_array($created) && isset($created['term_id'])) {
            return (int) $created['term_id'];
        }

        if (is_wp_error($created) && $created->get_error_code() === 'term_exists') {
            $data = $created->get_error_data();

            if (is_array($data) && isset($data['term_id'])) {
                return (int) $data['term_id'];
            }

            if (is_numeric($data)) {
                return (int) $data;
            }
        }

        return 0;
    }
}
