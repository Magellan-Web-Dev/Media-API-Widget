<?php

namespace MediaApiWidget\PostIndex;

use MediaApiWidget\Config\Options;

if (!defined('ABSPATH')) { exit; }

/**
 * The record of a playlist's last authoritative refresh.
 *
 * Deleting a stored post because its item "is missing" is only safe when the
 * plugin knows what the complete source contained. The cached payload cannot
 * tell it that: it may be a six-item snapshot, an enrichment write, a backup
 * read after a failed fetch, or simply older than the posts. So absence-based
 * deletion is authorized by this manifest alone, and only a completed,
 * validated, successfully stored remote refresh writes one.
 *
 * A manifest holds the source identity (the same composite key the posts carry)
 * of every item in the *complete* refresh result — for a YouTube playlist with
 * "Load full playlist" off, that is the whole fetched playlist, not the six
 * items that were stored — plus whether that membership is certain. Membership
 * is uncertain, and the manifest is marked incomplete, when any item in the
 * result had no usable identity.
 *
 * Each manifest carries a unique token. A synchronization pass records the
 * token it started under and may prune only if that token is still current when
 * it finishes, so a pass can never delete posts on the strength of a refresh
 * that has since been superseded.
 *
 * Manifests are recorded only while post storage is enabled for the playlist,
 * and are cleared when it is switched off or on, so a manifest can never
 * predate the posts it would be used to prune.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class RefreshManifest
{
    /** Option-name prefix; one non-autoloaded option per playlist. */
    public const OPTION_PREFIX = 'maw_post_index_manifest_';

    /**
     * Returns the option name for a playlist's manifest.
     *
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return string Option name.
     */
    public static function optionName(string $playlistName, string $mediaType): string
    {
        return self::OPTION_PREFIX . sanitize_key($mediaType) . '_' . sanitize_key($playlistName);
    }

    /**
     * Records the membership of a complete refresh result.
     *
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param mixed  $complete     The complete parsed refresh result, before any
     *                             item limit and before the pre-store filter.
     * @return int The new generation, or 0 when nothing was recorded.
     */
    public static function recordFromData(string $playlistName, string $mediaType, $complete): int
    {
        $playlistName = sanitize_key($playlistName);
        $mediaType    = sanitize_key($mediaType);

        if ($playlistName === '' || !is_array($complete)) {
            return 0;
        }

        $mapped = ItemMapper::fromStored($complete, $playlistName, $mediaType);
        $keys   = array_values(array_unique(array_map(
            static fn (array $record): string => (string) $record['source_key'],
            $mapped['records']
        )));

        $previous   = self::get($playlistName, $mediaType);
        $generation = (int) ($previous['generation'] ?? 0) + 1;

        update_option(self::optionName($playlistName, $mediaType), [
            'generation'  => $generation,
            'token'       => self::token(),
            'complete'    => $mapped['skipped'] === 0,
            'unmappable'  => (int) $mapped['skipped'],
            'keys'        => $keys,
            'recorded_at' => time(),
        ], false);

        return $generation;
    }

    /**
     * Builds the callback a refresh hands to MediaStore::store() as `after_write`.
     *
     * Returns null — record nothing — unless post storage is enabled for the
     * playlist. The callback runs under the storage lock, after both the
     * transient and the backup file were written and before the stored action
     * schedules synchronization, so the manifest is in place before any job can
     * read it and is never written for a refused or partial store.
     *
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @param mixed  $complete     Complete refresh result (see recordFromData()).
     * @return callable|null Callback, or null when storage is disabled.
     */
    public static function afterWriteCallback(string $playlistName, string $mediaType, $complete): ?callable
    {
        if (!is_array($complete) || !Options::isPostStorageEnabled($playlistName, $mediaType)) {
            return null;
        }

        return static function () use ($playlistName, $mediaType, $complete): void {
            self::recordFromData($playlistName, $mediaType, $complete);
        };
    }

    /**
     * Returns a playlist's manifest, or null when none is recorded.
     *
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return array<string,mixed>|null Manifest.
     */
    public static function get(string $playlistName, string $mediaType): ?array
    {
        $manifest = get_option(self::optionName($playlistName, $mediaType), null);

        if (!is_array($manifest) || !isset($manifest['token'], $manifest['keys']) || !is_array($manifest['keys'])) {
            return null;
        }

        return $manifest;
    }

    /**
     * Forgets a playlist's manifest, withdrawing deletion authority until the
     * next authoritative refresh.
     *
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return void
     */
    public static function clear(string $playlistName, string $mediaType): void
    {
        delete_option(self::optionName($playlistName, $mediaType));
    }

    /**
     * @return string A token unique to one recorded refresh.
     */
    private static function token(): string
    {
        return function_exists('wp_generate_password')
            ? wp_generate_password(20, false, false)
            : bin2hex(random_bytes(10));
    }
}
