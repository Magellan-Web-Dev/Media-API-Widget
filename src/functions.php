<?php

/**
 * Public global functions for the Media API Widget's data enrichment API.
 *
 * These are the documented, supported entry points for reading and altering
 * stored media data from outside the plugin. The classes behind them are
 * internal and may change; these function signatures will not.
 *
 * Loaded from the plugin bootstrap inside the PHP 8.1 version guard, so nothing
 * here parses on an unsupported runtime. Every function is wrapped in
 * function_exists() so a site that already defines one of these names — a
 * must-use plugin providing a shim, for instance — is not fataled by a
 * redeclaration.
 *
 * @see \MediaApiWidget\Support\MediaStore
 */

use MediaApiWidget\Config\Options;
use MediaApiWidget\Support\MediaStore;

if (!defined('ABSPATH')) { exit; }

if (!function_exists('media_api_widget_update_stored_data')) {
    /**
     * Alters media data that has already been stored, without calling any API.
     *
     * Intended for asynchronous enrichment: a background job finishes work for
     * an episode and needs to record the outcome on the stored playlist. The
     * current data is read from the transient (falling back to the backup JSON
     * file), handed to the mutator, validated, and written back to both the
     * transient and the backup file. No external request is made on any path.
     *
     * The mutator runs while this process holds the playlist's storage lock, so
     * a concurrent remote refresh cannot land between the read and the write.
     * Note the limit of that guarantee: a refresh *replaces* the payload with
     * whatever the API returned, so fields added here are absent from the next
     * refresh unless they are also re-applied in the
     * `media_api_widget_data_before_store` filter. Keep large authoritative data
     * (full transcripts especially) in your own storage keyed by YouTube video id
     * or podcast episode GUID, and put only a status or URL on the item.
     *
     * On success `media_api_widget_data_stored` fires with a `source` of
     * `manual_update`, so a listener can tell an enrichment write apart from a
     * remote refresh and avoid looping. The pre-store filter deliberately does
     * *not* fire, for the same reason.
     *
     * Example — record a finished transcript on one YouTube episode:
     *
     *     $result = media_api_widget_update_stored_data(
     *         'my_show',
     *         'youtube',
     *         static function ($data, array $context) {
     *             foreach ($data as &$item) {
     *                 if (($item['id'] ?? '') === 'YOUTUBE_VIDEO_ID') {
     *                     $item['transcript_status'] = 'complete';
     *                     $item['transcript_url'] = 'https://example.com/transcripts/YOUTUBE_VIDEO_ID';
     *                 }
     *             }
     *             unset($item);
     *
     *             return $data;
     *         }
     *     );
     *
     *     if (is_wp_error($result)) {
     *         // Nothing was written; the previous good data is intact.
     *     }
     *
     * The mutator receives the decoded data and the same context array the hooks
     * get, and must return the altered data or a WP_Error. Returning a WP_Error,
     * returning a value that does not match the documented shape for the media
     * type, returning something that cannot be JSON encoded, or throwing all
     * leave the existing transient and backup file byte-for-byte unchanged.
     *
     * Error codes: `maw_unsupported_media_type`, `maw_invalid_playlist_name`,
     * `maw_data_locked`, `maw_no_stored_data`, `maw_unsupported_podcast_payload`,
     * `maw_mutator_threw`, `maw_invalid_youtube_data`, `maw_invalid_podcast_data`,
     * `maw_json_encode_failed`, `maw_transient_write_failed`,
     * `maw_backup_write_failed`.
     *
     * @param string   $playlistName The playlist_name slug, as configured in admin.
     * @param string   $mediaType    'youtube' or 'podcast'.
     * @param callable $mutator      function ($data, array $context) returning data or WP_Error.
     * @return mixed|\WP_Error The updated decoded data, or a WP_Error on failure.
     */
    function media_api_widget_update_stored_data(string $playlistName, string $mediaType, callable $mutator)
    {
        $mediaType = sanitize_key($mediaType);

        if (!in_array($mediaType, MediaStore::SUPPORTED_MEDIA_TYPES, true)) {
            return new WP_Error(
                'maw_unsupported_media_type',
                'Unsupported media type. Expected youtube or podcast.'
            );
        }

        $playlistName = sanitize_key($playlistName);

        if ($playlistName === '') {
            return new WP_Error(
                'maw_invalid_playlist_name',
                'A usable playlist name is required.'
            );
        }

        return MediaStore::withDataLock(
            $mediaType,
            $playlistName,
            static function () use ($playlistName, $mediaType, $mutator) {
                $current = MediaStore::readStored($playlistName, $mediaType);

                if ($current === null) {
                    return new WP_Error(
                        'maw_no_stored_data',
                        'No stored data was found for this playlist. Nothing was changed.'
                    );
                }

                // An embed podcast stores a bare URL string rather than a feed
                // tree, so it has no per-episode structure to enrich. Refused
                // before the mutator runs, so a callback with side effects is
                // not invoked for a payload that could never be written back.
                if ($mediaType === 'podcast' && (!is_array($current) || !isset($current['channel']))) {
                    return new WP_Error(
                        'maw_unsupported_podcast_payload',
                        'This podcast playlist does not store a feed structure that can be updated.'
                    );
                }

                $context = MediaStore::buildContext(
                    $playlistName,
                    $mediaType,
                    MediaStore::SOURCE_MANUAL_UPDATE,
                    $mediaType === 'podcast' ? MediaStore::lookupPodcastPlatform($playlistName) : null
                );

                try {
                    $updated = $mutator($current, $context);
                } catch (\Throwable $e) {
                    // The exception message is deliberately not surfaced or
                    // logged: a callback talking to a third-party API can easily
                    // embed its credentials in the message it throws.
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf(
                            'Media API Widget: a media_api_widget_update_stored_data mutator threw (%s at %s:%d).',
                            get_class($e),
                            $e->getFile(),
                            $e->getLine()
                        ));
                    }

                    return new WP_Error(
                        'maw_mutator_threw',
                        'The mutator callback threw an exception. Nothing was changed.'
                    );
                }

                if (is_wp_error($updated)) {
                    return $updated;
                }

                $result = MediaStore::persist($context, $updated, [
                    'ttl'          => (int) (Options::getCacheExpirations()['media_cache_ttl'] ?? 7200),
                    'write_backup' => true,
                ]);

                return $result['ok'] ? $result['data'] : $result['error'];
            }
        );
    }
}

if (!function_exists('media_api_widget_get_stored_data')) {
    /**
     * Reads the currently stored media data for a playlist without refreshing.
     *
     * Prefers the WordPress transient and falls back to the local backup JSON
     * file, which is the same precedence the front end uses. Makes no external
     * request and never triggers a refresh, so it is safe to call from WP-Cron,
     * Action Scheduler, WP-CLI, or a REST callback.
     *
     * Returns a list of media item arrays for YouTube, and the normalized feed
     * array tree (`channel`, `channel.item`, …) for podcast. Embed-only podcasts
     * return the stored embed URL string.
     *
     * @param string $playlistName The playlist_name slug, as configured in admin.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return mixed|null Decoded stored data, or null when nothing usable is stored.
     */
    function media_api_widget_get_stored_data(string $playlistName, string $mediaType)
    {
        $mediaType    = sanitize_key($mediaType);
        $playlistName = sanitize_key($playlistName);

        if ($playlistName === '' || !in_array($mediaType, MediaStore::SUPPORTED_MEDIA_TYPES, true)) {
            return null;
        }

        return MediaStore::readStored($playlistName, $mediaType);
    }
}
