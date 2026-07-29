<?php
namespace MediaApiWidget\Stats;

use MediaApiWidget\Frontend\MediaContent;

if (!defined('ABSPATH')) { exit; }

/**
 * Read-only inventory of the local backup JSON files the plugin falls back to
 * when a fresh API call fails.
 *
 * Backup files live in {@see MediaContent::backupDir()} and are named
 * `{playlist_name}_{media_type}_backup_data.json`. They are written by
 * {@see MediaContent} only after a refresh completed normally, so the
 * timestamp reported here is the moment of the last *successful* store — not
 * the moment of the last attempt.
 *
 * This class never writes, moves, or deletes a backup file; it only resolves
 * paths and reads metadata so the admin Stats page can report on them. All
 * methods are static; this class is not intended to be instantiated.
 */
final class BackupInventory
{
    /**
     * Media types that have a backup JSON file on disk. Any other media type
     * resolves to no file, which callers surface as "no backup available".
     *
     * @var array<int,string>
     */
    public const SUPPORTED_MEDIA_TYPES = ['youtube', 'podcast'];

    /**
     * How many leading bytes of a backup file are read when looking for the
     * `time_stored` key. The key is always written first by
     * {@see MediaContent}, so a short prefix read avoids decoding a playlist
     * that may be hundreds of kilobytes just to read one integer.
     *
     * @var int
     */
    private const HEADER_READ_BYTES = 512;

    /**
     * Per-request memo of {@see self::describe()} results, keyed by
     * `{media_type}|{playlist_name}`. The Stats page renders one row per
     * playlist/endpoint pair, so the same playlist is commonly asked about
     * several times in a single page load.
     *
     * @var array<string, array<string,mixed>>
     */
    private static array $describeCache = [];

    /**
     * Returns the absolute backup directory path, with a trailing slash.
     *
     * Delegates to {@see MediaContent::backupDir()} so there is a single
     * source of truth for where backups live.
     *
     * @return string Absolute directory path ending in a slash.
     */
    public static function directory(): string
    {
        return MediaContent::backupDir();
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
     * stored by both the settings page and {@see ApiCallLogger}, so a name
     * coming back out of the log table resolves to the same file that
     * {@see MediaContent} wrote.
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

    /**
     * Describes the backup file for a playlist and media type.
     *
     * `stored_at` prefers the `time_stored` value written inside the JSON,
     * which is the moment the successful refresh was persisted. When that key
     * cannot be read (an older or truncated file) it falls back to the file's
     * modification time, so an existing backup always reports a usable time.
     *
     * @param string $playlistName The playlist_name slug.
     * @param string $mediaType    'youtube' or 'podcast'.
     * @return array{exists:bool,path:string,file_name:string,stored_at:int,size:int}
     *         `exists` is false when the type is unsupported or the file is
     *         missing/unreadable; in that case `stored_at` and `size` are 0.
     */
    public static function describe(string $playlistName, string $mediaType): array
    {
        $cacheKey = sanitize_key($mediaType) . '|' . sanitize_key($playlistName);

        if (isset(self::$describeCache[$cacheKey])) {
            return self::$describeCache[$cacheKey];
        }

        $fileName = self::fileName($playlistName, $mediaType);
        $path     = $fileName === '' ? '' : self::directory() . $fileName;

        $info = [
            'exists'    => false,
            'path'      => $path,
            'file_name' => $fileName,
            'stored_at' => 0,
            'size'      => 0,
        ];

        if ($path !== '' && is_file($path) && is_readable($path)) {
            $info['exists']    = true;
            $info['size']      = max(0, (int) filesize($path));
            $info['stored_at'] = self::readStoredTime($path);
        }

        self::$describeCache[$cacheKey] = $info;

        return $info;
    }

    /**
     * Clears the per-request describe() memo.
     *
     * Only needed by tests, which write backup files between assertions within
     * a single PHP process.
     *
     * @return void
     */
    public static function flushCache(): void
    {
        self::$describeCache = [];
    }

    /**
     * Reads the stored-at timestamp for an existing, readable backup file.
     *
     * Scans only the first {@see self::HEADER_READ_BYTES} bytes for the
     * `time_stored` key rather than decoding the whole payload. Falls back to
     * the file modification time when the key is absent or non-numeric.
     *
     * @param string $path Absolute path to a readable backup file.
     * @return int Unix timestamp, or 0 when no time could be determined.
     */
    private static function readStoredTime(string $path): int
    {
        $header = @file_get_contents($path, false, null, 0, self::HEADER_READ_BYTES);

        if (is_string($header) && preg_match('/"time_stored"\s*:\s*(\d+)/', $header, $matches) === 1) {
            $storedAt = (int) $matches[1];
            if ($storedAt > 0) {
                return $storedAt;
            }
        }

        $modifiedAt = @filemtime($path);

        return is_int($modifiedAt) && $modifiedAt > 0 ? $modifiedAt : 0;
    }
}
