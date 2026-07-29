<?php
/**
 * Tests the read-only backup inventory that powers the two backup columns on
 * the API Stats page: which file a playlist resolves to, when that file was
 * last stored successfully, and that nothing is reported when no file exists.
 */

declare(strict_types=1);

use MediaApiWidget\Frontend\MediaContent;
use MediaApiWidget\Stats\BackupInventory;

/**
 * Writes a backup file for a playlist with a known stored-at time.
 *
 * @param string $playlistName Playlist slug.
 * @param string $mediaType    'youtube' or 'podcast'.
 * @param int    $storedAt     Value written as `time_stored`.
 * @return string Absolute path of the file written.
 */
function maw_write_backup_file(string $playlistName, string $mediaType, int $storedAt): string
{
    $path = MediaContent::backupDir() . $playlistName . '_' . $mediaType . '_backup_data.json';

    file_put_contents($path, (string) json_encode([
        'time_stored' => $storedAt,
        'data'        => [['title' => 'Episode One', 'id' => 'vid1']],
    ]));

    BackupInventory::flushCache();

    return $path;
}

return [

    'a stored youtube backup reports its time_stored value' => static function (): void {
        maw_write_backup_file('testshow', 'youtube', 1700000000);

        $info = BackupInventory::describe('testshow', 'youtube');

        maw_assert_same(true, $info['exists'], 'the backup file is found');
        maw_assert_same(1700000000, $info['stored_at'], 'the stored-at time comes from time_stored');
        maw_assert_same('testshow_youtube_backup_data.json', $info['file_name'], 'the file name matches what MediaContent writes');
        maw_assert($info['size'] > 0, 'a byte size is reported');
    },

    'a stored podcast backup is resolved independently of the youtube one' => static function (): void {
        maw_write_backup_file('testshow', 'youtube', 1700000000);
        maw_write_backup_file('testshow', 'podcast', 1700009999);

        maw_assert_same(1700000000, BackupInventory::describe('testshow', 'youtube')['stored_at'], 'the youtube backup keeps its own time');
        maw_assert_same(1700009999, BackupInventory::describe('testshow', 'podcast')['stored_at'], 'the podcast backup keeps its own time');
    },

    'a playlist with no backup file reports nothing stored' => static function (): void {
        $info = BackupInventory::describe('neverfetched', 'youtube');

        maw_assert_same(false, $info['exists'], 'no file is reported');
        maw_assert_same(0, $info['stored_at'], 'no stored-at time is reported');
        maw_assert_same(0, $info['size'], 'no size is reported');
    },

    'an unsupported media type resolves to no file at all' => static function (): void {
        maw_assert_same('', BackupInventory::fileName('testshow', 'vimeo'), 'no file name is produced');
        maw_assert_same('', BackupInventory::filePath('testshow', 'vimeo'), 'no path is produced');
        maw_assert_same(false, BackupInventory::describe('testshow', 'vimeo')['exists'], 'nothing is reported as existing');
    },

    'an empty playlist slug resolves to no file at all' => static function (): void {
        maw_assert_same('', BackupInventory::fileName('', 'youtube'), 'no file name is produced');
        maw_assert_same(false, BackupInventory::describe('', 'youtube')['exists'], 'nothing is reported as existing');
    },

    'a backup written without time_stored falls back to the file time' => static function (): void {
        $path = MediaContent::backupDir() . 'legacyshow_youtube_backup_data.json';
        file_put_contents($path, (string) json_encode(['data' => [['title' => 'Legacy', 'id' => 'vid0']]]));
        BackupInventory::flushCache();

        $info = BackupInventory::describe('legacyshow', 'youtube');

        maw_assert_same(true, $info['exists'], 'the file is still found');
        maw_assert_same(filemtime($path), $info['stored_at'], 'the modification time is used instead');
    },

    'the file a successful refresh writes is the file the inventory reports' => static function (): void {
        maw_queue_json(maw_youtube_page(3, null, 3));

        maw_run_youtube_load(maw_youtube_config());
        BackupInventory::flushCache();

        $info = BackupInventory::describe('testshow', 'youtube');

        maw_assert_same(maw_backup_path('testshow'), $info['path'], 'the inventory path matches the written path');
        maw_assert_same(true, $info['exists'], 'the refresh-written backup is found');
        maw_assert($info['stored_at'] > 0, 'the refresh-written backup reports a stored-at time');
    },

];
