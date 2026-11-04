<?php

namespace MediaApiWidget\PostIndex;

use MediaApiWidget\Config\Options;
use MediaApiWidget\Support\OptionLock;

if (!defined('ABSPATH')) { exit; }

/**
 * Populates the media post index from data that is already stored.
 *
 * Without this, a site upgrading to the index would see nothing in a page
 * builder until every playlist happened to refresh — up to a full media cache
 * TTL of waiting, and longer for a playlist inside its backup window. So on
 * first run the backfill schedules one synchronization per playlist that has
 * post storage enabled, working entirely from the transient and backup file. No external request is
 * made, and no API quota is consumed.
 *
 * It runs at most once per schema version. The version lives in its own option
 * rather than being compared against MAW_PLUGIN_VERSION, because the index needs
 * rebuilding when *its* shape changes, not on every plugin release — tying it to
 * the plugin version would mean a documentation-only release triggered a full
 * re-index of every playlist on every site.
 *
 * All methods are static except the hook callback.
 */
final class Backfill
{
    /**
     * Option name of the lock held while scheduling.
     *
     * @var string
     */
    private const LOCK_NAME = 'maw_post_index_backfill_lock';

    /**
     * How long the scheduling lock stays valid.
     *
     * Only option reads and writes happen under it, so it can be short.
     *
     * @var int
     */
    private const LOCK_TTL_SECONDS = 60;

    /**
     * Hooks the one-time check.
     *
     * admin_init rather than init: this must never run on a front-end request,
     * on admin-ajax.php, or on wp-cron.php, and requirement is that ordinary
     * page views do not repeatedly scan every configured playlist. The check
     * itself is one read of an option that is already in memory, and after the
     * first successful run it short-circuits immediately and forever.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_init', [$this, 'maybeRun'], 10);
    }

    /**
     * Runs the backfill when the stored schema version is behind.
     *
     * Not capability-gated. Gating on manage_options would mean a site whose
     * administrator only ever visits the front end never backfills at all, and
     * the work here — scheduling a cron event that reads local data — is
     * harmless regardless of who triggered it. The version short-circuit is the
     * real gate.
     *
     * @return void
     */
    public function maybeRun(): void
    {
        if ((int) get_option(PostIndex::OPTION_SCHEMA_VERSION, 0) >= PostIndex::SCHEMA_VERSION) {
            return;
        }

        self::run();
    }

    /**
     * Schedules one synchronization per configured playlist.
     *
     * @return array<string,mixed> Summary: ok, scheduled, failed, pairs, marked.
     */
    public static function run(): array
    {
        $summary = [
            'ok'        => true,
            'scheduled' => 0,
            'failed'    => 0,
            'pairs'     => 0,
            'marked'    => false,
        ];

        if ((int) get_option(PostIndex::OPTION_SCHEMA_VERSION, 0) >= PostIndex::SCHEMA_VERSION) {
            $summary['marked'] = true;

            return $summary;
        }

        $lock = OptionLock::acquire(self::LOCK_NAME, self::LOCK_TTL_SECONDS);

        if ($lock === null) {
            // Another admin request is already scheduling. Doing nothing is
            // correct: that request will mark the version when it finishes.
            $summary['ok'] = false;

            return $summary;
        }

        try {
            // Re-read under the lock. The holder may have completed between the
            // unlocked check above and the acquire, and scheduling twice would
            // be wasted work.
            if ((int) get_option(PostIndex::OPTION_SCHEMA_VERSION, 0) >= PostIndex::SCHEMA_VERSION) {
                $summary['marked'] = true;

                return $summary;
            }

            $pairs = self::configuredPlaylists();
            $summary['pairs'] = count($pairs);

            foreach ($pairs as [$playlistName, $mediaType]) {
                if (PostIndexSync::scheduleSync($playlistName, $mediaType)) {
                    $summary['scheduled']++;
                } else {
                    $summary['failed']++;
                }
            }

            // Marked only once every event is queued, so a scheduling failure
            // leaves the backfill to be retried on the next admin request. Zero
            // configured playlists counts as success — otherwise a site with no
            // media items would re-run this on every admin page load forever.
            if ($summary['failed'] === 0) {
                update_option(PostIndex::OPTION_SCHEMA_VERSION, PostIndex::SCHEMA_VERSION, false);
                $summary['marked'] = true;
            }

            $summary['ok'] = $summary['failed'] === 0;

            return $summary;
        } finally {
            OptionLock::release($lock);
        }
    }

    /**
     * Lists every playlist with post storage enabled, as [playlist_name, media_type].
     *
     * Includes rows from the legacy MEDIA_CONTENT_DATA constant, deduplicated,
     * and only those whose "Store playlist items as WordPress posts" setting is
     * on — the same check every other synchronization entry point applies.
     *
     * @return array<int,array{0:string,1:string}> Deduplicated pairs.
     */
    public static function configuredPlaylists(): array
    {
        return Options::postStoragePlaylists();
    }
}
