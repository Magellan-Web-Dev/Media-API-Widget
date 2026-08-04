<?php

namespace MediaApiWidget\Support;

if (!defined('ABSPATH')) { exit; }

/**
 * Resolves the on-disk locations of the plugin's backup JSON files.
 *
 * Backup files are the local fallback the plugin serves when a fresh API call
 * fails. They live in `{uploads_basedir}/media-api-widget/backups/` and are
 * named `{playlist_name}_{media_type}_backup_data.json`.
 *
 * This class is a neutral path authority with no dependency on the fetch
 * pipeline, the storage service, or the admin Stats page — all three depend on
 * it instead, so the directory layout and the file-naming rule have a single
 * definition. It only resolves and creates paths; it never reads, writes,
 * moves, or deletes a backup payload. Writing is {@see MediaStore}'s job and
 * reporting is {@see \MediaApiWidget\Stats\BackupInventory}'s.
 *
 * All methods are static; this class is not intended to be instantiated.
 */
final class BackupFiles
{
    /**
     * Media types that have a backup JSON file on disk. Any other media type
     * resolves to no file, which callers surface as "no backup available".
     *
     * @var array<int,string>
     */
    public const SUPPORTED_MEDIA_TYPES = ['youtube', 'podcast'];

    /**
     * Returns the absolute backup directory path, with a trailing slash.
     *
     * The directory is created with wp_mkdir_p() if it does not already exist,
     * and a silent `index.php` is dropped inside it so the directory cannot be
     * browsed even if the web server has directory listing enabled.
     *
     * @return string Absolute directory path ending in a slash.
     */
    public static function directory(): string
    {
        $upload = wp_upload_dir();
        $base = rtrim($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads', '/');
        $dir = $base . '/media-api-widget/backups';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }

        // Drop a silent index.php so the backup directory cannot be browsed
        // even if the web server has directory listing enabled.
        $index = $dir . '/index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        return $dir . '/';
    }

    /**
     * Reports whether a media type has backup files at all.
     *
     * @param string $mediaType The media type to check ('youtube' / 'podcast').
     * @return bool True when the type is backed up to a JSON file.
     */
    public static function isSupportedMediaType(string $mediaType): bool
    {
        return in_array($mediaType, self::SUPPORTED_MEDIA_TYPES, true);
    }

    /**
     * Returns the backup file name for a playlist and media type.
     *
     * The playlist slug is passed through sanitize_key(), matching how it is
     * stored by the settings page and by {@see \MediaApiWidget\Stats\ApiCallLogger},
     * so a name coming back out of the log table resolves to the same file the
     * storage service wrote.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return string File name, or an empty string when either value is unusable.
     */
    public static function fileName(string $playlistName, string $mediaType): string
    {
        $playlistName = sanitize_key($playlistName);
        $mediaType    = sanitize_key($mediaType);

        if ($playlistName === '' || !self::isSupportedMediaType($mediaType)) {
            return '';
        }

        return $playlistName . '_' . $mediaType . '_backup_data.json';
    }

    /**
     * Returns the absolute backup file path for a playlist and media type.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return string Absolute path, or an empty string when no path applies.
     */
    public static function filePath(string $playlistName, string $mediaType): string
    {
        $fileName = self::fileName($playlistName, $mediaType);

        return $fileName === '' ? '' : self::directory() . $fileName;
    }
}
