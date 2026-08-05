<?php

namespace MediaApiWidget\PostIndex;

use MediaApiWidget\Support\XmlText;

if (!defined('ABSPATH')) { exit; }

/**
 * Turns a stored media payload into index records.
 *
 * Pure functions only: this class reads no options, writes no posts, touches no
 * database, and makes no network request. That keeps the interesting logic —
 * identity resolution and field mapping — testable in isolation from
 * WordPress, and it means the same record can be produced from a transient, a
 * backup file, or a fixture.
 *
 * The two payload shapes it accepts are the ones the media pipeline actually
 * stores: a list of parsed YouTube item arrays, and the SimpleXML-derived
 * podcast array tree. Neither is tidy, and both are third-party data, so every
 * read is defensive.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class ItemMapper
{
    /**
     * Identifiers matching this may be embedded in a source key verbatim.
     * Anything else is hashed, so the key stays bounded and printable.
     *
     * @var string
     */
    private const ID_SAFE = '/^[A-Za-z0-9_.\-]{1,64}$/';

    /**
     * Longest playlist slug embedded in a source key before truncation.
     *
     * @var int
     */
    private const KEY_MAX_PLAYLIST = 64;

    /**
     * Largest source payload retained verbatim, in bytes.
     *
     * @var int
     */
    private const PAYLOAD_MAX_BYTES = 65536;

    /**
     * Keys whose values never belong in post meta, at any nesting depth.
     *
     * Stored items carry no credentials today, but the pre-store filter lets a
     * third party add arbitrary keys to an item, and a debugging payload is a
     * poor place to discover that someone stashed a token.
     *
     * @var string
     */
    private const SECRET_KEY_PATTERN = '/(api[_-]?key|secret|token|password|passwd|authorization|bearer|credential)/i';

    /**
     * Maps a stored payload into index records.
     *
     * Reports skipped items alongside the records rather than dropping them
     * silently. An item with no resolvable identity is a real signal — a podcast
     * feed shipping episodes with no guid, link, enclosure, title, or date is
     * broken in a way an administrator should be able to see — so the count
     * reaches the synchronization summary and the post-index-synced action.
     *
     * @param mixed  $data         Stored payload: a YouTube item list or a podcast tree.
     * @param string $playlistName The playlist_name slug the payload belongs to.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return array{records:array<int,array<string,mixed>>,skipped:int}
     */
    public static function fromStored($data, string $playlistName, string $mediaType): array
    {
        if (!is_array($data)) {
            return ['records' => [], 'skipped' => 0];
        }

        if ($mediaType === 'youtube') {
            return self::fromYoutube($data, $playlistName);
        }

        if ($mediaType === 'podcast') {
            return self::fromPodcast($data, $playlistName);
        }

        return ['records' => [], 'skipped' => 0];
    }

    /**
     * Maps parsed YouTube items.
     *
     * Identity is single-tier: the video id, or nothing. parseYoutubeItems()
     * sets `id` to null when the API response carried no resourceId.videoId,
     * and the positional index does not survive its closing array_values(), so
     * an item with no id has no stable identity and is skipped rather than
     * indexed under an invented one.
     *
     * @param array<int|string,mixed> $items        Stored YouTube item list.
     * @param string                  $playlistName The playlist_name slug.
     * @return array{records:array<int,array<string,mixed>>,skipped:int}
     */
    private static function fromYoutube(array $items, string $playlistName): array
    {
        $records  = [];
        $skipped  = 0;
        $position = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                $skipped++;
                $position++;
                continue;
            }

            $videoId = is_string($item['id'] ?? null) ? trim($item['id']) : '';

            if ($videoId === '' || preg_match(self::ID_SAFE, $videoId) !== 1) {
                $skipped++;
                $position++;
                continue;
            }

            // 'default' is read as a fallback because parseYoutubeItems() has a
            // branch that assigns the smallest thumbnail to $item['default']
            // rather than $item['thumbnail']. One ?? costs nothing and tolerates
            // any payload that was stored while that branch was reachable.
            $thumbnail = $item['thumbnail'] ?? $item['default'] ?? null;
            $thumbnailUrl = is_array($thumbnail) && is_string($thumbnail['url'] ?? null)
                ? $thumbnail['url']
                : '';

            $records[] = self::record([
                'source_key'    => self::sourceKey('youtube', $playlistName, 'vid', $videoId),
                'source_id'     => $videoId,
                'id_kind'       => 'vid',
                'title'         => self::text($item['title'] ?? ''),
                'excerpt'       => self::text($item['description'] ?? ''),
                'thumbnail_url' => $thumbnailUrl,
                'published_raw' => is_string($item['publishedDate'] ?? null) ? $item['publishedDate'] : '',
                'episode'       => self::optionalNonNegativeInt($item, 'episode'),
                'season'        => self::optionalNonNegativeInt($item, 'season'),
                'position'      => $position,
                'youtube_id'    => $videoId,
                'podcast_guid'  => '',
            ], $item);

            $position++;
        }

        return ['records' => $records, 'skipped' => $skipped];
    }

    /**
     * Maps a normalized podcast feed tree.
     *
     * @param array<string,mixed> $normalized   Stored podcast tree.
     * @param string              $playlistName The playlist_name slug.
     * @return array{records:array<int,array<string,mixed>>,skipped:int}
     */
    private static function fromPodcast(array $normalized, string $playlistName): array
    {
        $records  = [];
        $skipped  = 0;
        $position = 0;

        foreach (self::podcastItems($normalized) as $item) {
            [$kind, $rawId] = self::podcastIdentity($item);

            if ($kind === '' || $rawId === '') {
                $skipped++;
                $position++;
                continue;
            }

            $guid = XmlText::flatten($item['guid'] ?? '');

            $records[] = self::record([
                'source_key'    => self::sourceKey('podcast', $playlistName, $kind, $rawId),
                'source_id'     => $rawId,
                'id_kind'       => $kind,
                'title'         => self::text($item['title'] ?? ''),
                'excerpt'       => self::text($item['description'] ?? ''),
                'thumbnail_url' => self::podcastThumbnail($item),
                'published_raw' => XmlText::flatten($item['pubDate'] ?? ''),
                'episode'       => self::optionalNonNegativeInt($item, 'episode'),
                'season'        => self::optionalNonNegativeInt($item, 'season'),
                'position'      => $position,
                'youtube_id'    => '',
                'podcast_guid'  => $guid,
            ], $item);

            $position++;
        }

        return ['records' => $records, 'skipped' => $skipped];
    }

    /**
     * Extracts channel.item as a list, whatever shape it was stored in.
     *
     * Three shapes occur in practice. A multi-episode feed stores a list. A
     * single-episode feed stores the episode's own associative array directly,
     * unwrapped, when it came through the wp_head refresh path. A feed with no
     * episodes omits the key entirely, which is valid.
     *
     * array_is_list() is used rather than the isset($items['title']) test found
     * elsewhere in the plugin. That test gets a single episode with no <title>
     * element wrong: isset() returns false, and the episode's own fields are
     * then iterated as though each were a separate episode.
     *
     * @param array<string,mixed> $normalized Stored podcast tree.
     * @return array<int,array<string,mixed>> Episode arrays, possibly empty.
     */
    private static function podcastItems(array $normalized): array
    {
        $channel = $normalized['channel'] ?? null;

        if (!is_array($channel) || !array_key_exists('item', $channel)) {
            return [];
        }

        $items = $channel['item'];

        if (!is_array($items) || $items === []) {
            return [];
        }

        if (!array_is_list($items)) {
            $items = [$items];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * Resolves a podcast episode's identity, most stable source first.
     *
     * Tier 1 is the GUID, which is what a feed is supposed to supply and what
     * the RSS parsers now flatten to plain text before storage precisely so it
     * survives. Tiers 2 to 4 exist for feeds that ship no GUID at all, and for
     * payloads cached before that flattening was added.
     *
     * Tiers 3 and 4 key on mutable fields, so an episode resolved that way
     * gains a second indexed post if it is later relinked or retitled. That is
     * unavoidable without a stable feed identifier; the tier is recorded in the
     * source key so the cause is visible when it happens.
     *
     * @param array<string,mixed> $item One episode array.
     * @return array{0:string,1:string} [kind, raw identifier]; both empty when unresolvable.
     */
    private static function podcastIdentity(array $item): array
    {
        $guid = XmlText::flatten($item['guid'] ?? '');
        if ($guid !== '') {
            return ['guid', $guid];
        }

        // Read via the explicit attribute accessor: an enclosure's value lives
        // entirely in attributes, which XmlText::flatten() skips by design.
        $enclosure = XmlText::enclosureUrl($item['enclosure'] ?? null);
        if ($enclosure !== '') {
            return ['enc', $enclosure];
        }

        $link = esc_url_raw(XmlText::flatten($item['link'] ?? ''));
        if ($link !== '') {
            return ['link', $link];
        }

        $title   = XmlText::flatten($item['title'] ?? '');
        $pubDate = XmlText::flatten($item['pubDate'] ?? '');

        if ($title !== '' || $pubDate !== '') {
            return ['hash', sha1($title . '|' . $pubDate)];
        }

        return ['', ''];
    }

    /**
     * Finds an episode-level image URL, if the feed supplied one.
     *
     * Namespaced elements such as itunes:image do not survive the SimpleXML to
     * JSON conversion, so in practice this only finds a plain <image> child.
     * Returns an empty string rather than falling back to channel artwork,
     * which would make every episode look identical.
     *
     * @param array<string,mixed> $item One episode array.
     * @return string An http(s) URL, or an empty string.
     */
    private static function podcastThumbnail(array $item): string
    {
        $image = $item['image'] ?? null;

        if (is_string($image)) {
            return esc_url_raw(trim($image));
        }

        if (!is_array($image)) {
            return '';
        }

        $attributes = $image['@attributes'] ?? null;
        if (is_array($attributes)) {
            foreach (['href', 'url'] as $key) {
                if (is_string($attributes[$key] ?? null)) {
                    $url = esc_url_raw(trim($attributes[$key]));
                    if ($url !== '') {
                        return $url;
                    }
                }
            }
        }

        return esc_url_raw(XmlText::flatten($image['url'] ?? ''));
    }

    /**
     * Builds the composite identity for one item.
     *
     * Format: `{mediaType}:{playlistName}:{kind}:{idPart}`.
     *
     * The playlist name is part of the identity because the same video or
     * episode can be configured in several playlists, each with its own
     * position and its own indexed post. The resolution tier is included so a
     * mis-resolved podcast identity is diagnosable from the Custom Fields UI
     * alone. The identifier is embedded verbatim when it is short and printable
     * and hashed otherwise, which bounds the whole key at 142 characters.
     *
     * Note the identifier is never passed through sanitize_key(): that would
     * lowercase it, and YouTube video ids are case-sensitive.
     *
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param string $playlistName The playlist_name slug.
     * @param string $kind         Resolution tier: vid, guid, enc, link, or hash.
     * @param string $rawId        The provider-side identifier.
     * @return string The composite key.
     */
    public static function sourceKey(string $mediaType, string $playlistName, string $kind, string $rawId): string
    {
        $idPart = preg_match(self::ID_SAFE, $rawId) === 1 ? $rawId : sha1($rawId);

        return sanitize_key($mediaType)
            . ':' . substr(sanitize_key($playlistName), 0, self::KEY_MAX_PLAYLIST)
            . ':' . sanitize_key($kind)
            . ':' . $idPart;
    }

    /**
     * Completes a record with the fields derived from the whole source item.
     *
     * @param array<string,mixed> $record Partially built record.
     * @param array<string,mixed> $item   The source item it came from.
     * @return array<string,mixed> The finished record.
     */
    private static function record(array $record, array $item): array
    {
        $timestamp = $record['published_raw'] === '' ? false : strtotime($record['published_raw']);

        // array_key_exists(), not isset(): a caller sending transcript => null
        // means "clear it", which isset() would misread as "not supplied".
        $hasTranscript = array_key_exists('transcript', $item);

        $record['published_date']    = $record['published_raw'];
        $record['published_ts']      = $timestamp === false ? null : $timestamp;
        $record['has_transcript']    = $hasTranscript;
        $record['transcript']        = $hasTranscript ? (string) $item['transcript'] : '';
        $record['transcript_fields'] = self::transcriptFields($item);
        $record['payload']           = self::payload($item);

        unset($record['published_raw']);

        return $record;
    }

    /**
     * Collects only the transcript meta keys the item actually carried.
     *
     * A key that is absent must leave the existing meta untouched, so it must
     * not appear here at all — the distinction between "absent" and "present
     * but empty" is what stops a routine refresh from erasing transcript
     * metadata an integration wrote earlier.
     *
     * @param array<string,mixed> $item One source item.
     * @return array<string,string> Meta key => value, for supplied keys only.
     */
    private static function transcriptFields(array $item): array
    {
        $map = [
            'transcript_status'   => PostIndex::META_TRANSCRIPT_STATUS,
            'transcript_url'      => PostIndex::META_TRANSCRIPT_URL,
            'transcript_language' => PostIndex::META_TRANSCRIPT_LANGUAGE,
        ];

        $fields = [];

        foreach ($map as $itemKey => $metaKey) {
            if (!array_key_exists($itemKey, $item)) {
                continue;
            }

            $value = $item[$itemKey];
            $fields[$metaKey] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $fields;
    }

    /**
     * Builds the trimmed JSON copy of a source item kept for debugging.
     *
     * The transcript is removed because its canonical home is post_content:
     * duplicating a large blob doubles storage and creates a second copy that
     * drifts as soon as the post is edited. Secret-looking keys are removed
     * because a third party can add arbitrary keys through the pre-store filter.
     * An oversized result is replaced by a marker rather than stored.
     *
     * @param array<string,mixed> $item One source item.
     * @return string JSON, or an empty string when it cannot be encoded.
     */
    private static function payload(array $item): string
    {
        $copy = $item;
        unset($copy['transcript']);

        $copy = self::stripSecrets($copy);

        $json = wp_json_encode($copy);

        if (!is_string($json)) {
            return '';
        }

        if (strlen($json) > self::PAYLOAD_MAX_BYTES) {
            return (string) wp_json_encode([
                '_maw_truncated' => true,
                'bytes'          => strlen($json),
            ]);
        }

        return $json;
    }

    /**
     * Recursively removes keys whose names suggest they hold a credential.
     *
     * @param array<int|string,mixed> $value Array to filter.
     * @param int                     $depth Current recursion depth.
     * @return array<int|string,mixed> Filtered array.
     */
    private static function stripSecrets(array $value, int $depth = 0): array
    {
        if ($depth >= 12) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $child) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                continue;
            }

            $out[$key] = is_array($child) ? self::stripSecrets($child, $depth + 1) : $child;
        }

        return $out;
    }

    /**
     * Reads an optional non-negative integer field.
     *
     * The media pipeline uses -1 as its "no number available" sentinel for
     * episode and season. Null is returned for that, and for a missing or
     * non-numeric value, so the caller can delete the meta row instead of
     * publishing -1 into a field a page builder will display.
     *
     * @param array<string,mixed> $item One source item.
     * @param string              $key  Field to read.
     * @return int|null The value, or null when there is none.
     */
    private static function optionalNonNegativeInt(array $item, string $key): ?int
    {
        if (!array_key_exists($key, $item) || !is_numeric($item[$key])) {
            return null;
        }

        $value = (int) $item[$key];

        return $value < 0 ? null : $value;
    }

    /**
     * Flattens and sanitizes a field destined for a post title or excerpt.
     *
     * @param mixed $value Raw field value.
     * @return string Plain text.
     */
    private static function text($value): string
    {
        return sanitize_text_field(XmlText::flatten($value));
    }
}
