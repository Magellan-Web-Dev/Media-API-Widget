<?php

namespace MediaApiWidget\PostIndex;

if (!defined('ABSPATH')) { exit; }

/**
 * Per-playlist synchronization status shown in the admin.
 *
 * One non-autoloaded option per playlist. Written by the background job (under
 * its lock) and by the admin actions that queue work; read by the settings page.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class SyncStatus
{
    /** Option-name prefix. */
    public const OPTION_PREFIX = 'maw_post_index_status_';

    public const STATE_IDLE     = 'idle';
    public const STATE_PENDING  = 'pending';
    public const STATE_RUNNING  = 'running';
    public const STATE_COMPLETE = 'complete';
    public const STATE_ERROR    = 'error';
    public const STATE_DISABLED = 'disabled';

    /**
     * Why missing items were or were not removed, keyed by note code.
     *
     * @return array<string,string> Code => admin-facing explanation.
     */
    public static function pruneNotes(): array
    {
        return [
            'removed'            => 'Posts for items no longer in the source were removed after a complete refresh.',
            'none_missing'       => 'No stored items are missing from the last complete refresh.',
            'no_refresh_record'  => 'Missing items are only removed after a complete refresh has been recorded since post storage was enabled.',
            'already_reconciled' => 'The last complete refresh was already reconciled.',
            'superseded'         => 'Newer data arrived during this synchronization, so removing missing items was left to the next run.',
            'incomplete_refresh' => 'The last refresh contained items without a usable identity, so missing items were not removed.',
            'unidentified_items' => 'Some stored items had no usable identity, so missing items were not removed.',
            'write_failures'     => 'Some posts could not be written, so missing items were not removed. The synchronization will retry.',
            'delete_failures'    => 'Some posts for missing items could not be deleted. The synchronization will retry.',
        ];
    }

    /**
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return string Option name.
     */
    public static function optionName(string $playlistName, string $mediaType): string
    {
        return self::OPTION_PREFIX . sanitize_key($mediaType) . '_' . sanitize_key($playlistName);
    }

    /**
     * Returns a playlist's status with every key present.
     *
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return array<string,mixed> Status.
     */
    public static function get(string $playlistName, string $mediaType): array
    {
        $stored = get_option(self::optionName($playlistName, $mediaType), []);

        return array_merge([
            'state'             => self::STATE_IDLE,
            'message'           => '',
            'updated_at'        => 0,
            'last_success_at'   => 0,
            'last_result'       => [],
            'progress'          => null,
            'attempts'          => 0,
            'prune_note'        => '',
            'reconciled_token'  => '',
        ], is_array($stored) ? $stored : []);
    }

    /**
     * Merges changes into a playlist's status.
     *
     * @param string              $playlistName Playlist slug.
     * @param string              $mediaType    'youtube' or 'podcast'.
     * @param array<string,mixed> $changes      Keys to set.
     * @return array<string,mixed> The new status.
     */
    public static function update(string $playlistName, string $mediaType, array $changes): array
    {
        $status = array_merge(self::get($playlistName, $mediaType), $changes, ['updated_at' => time()]);
        update_option(self::optionName($playlistName, $mediaType), $status, false);

        return $status;
    }

    /**
     * Removes a playlist's status.
     *
     * @param string $playlistName Playlist slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return void
     */
    public static function clear(string $playlistName, string $mediaType): void
    {
        delete_option(self::optionName($playlistName, $mediaType));
    }
}
