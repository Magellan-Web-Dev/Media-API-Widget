<?php
/**
 * Tests that the podcast path is untouched by the YouTube guards.
 *
 * The daily circuit breaker must count YouTube playlistItems requests only.
 * Podcast RSS, Apple/iTunes lookups, and plugin-update requests are unrelated
 * services with their own limits and must never be blocked by it.
 */

declare(strict_types=1);

use MediaApiWidget\Support\YoutubeGuard;

/**
 * Returns a minimal but valid podcast RSS document.
 *
 * @return string RSS XML.
 */
function maw_podcast_rss(): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Test Podcast</title>
    <description>A test feed.</description>
    <item>
      <title>Podcast Episode 1</title>
      <description>&lt;p&gt;First episode.&lt;/p&gt;</description>
      <guid>ep-1</guid>
    </item>
    <item>
      <title>Podcast Episode 2</title>
      <description>&lt;p&gt;Second episode.&lt;/p&gt;</description>
      <guid>ep-2</guid>
    </item>
  </channel>
</rss>
XML;
}

/**
 * Returns a podcast media config array.
 *
 * @param array<string,mixed> $overrides Values to merge over the defaults.
 * @return array<string,mixed> Resolved config.
 */
function maw_podcast_config(array $overrides = []): array
{
    return array_merge(maw_youtube_config(), [
        'type'             => 'podcast',
        'podcast_platform' => 'custom',
        'playlist_name'    => 'testpod',
        'media_data'       => 'https://feeds.example.com/podcast.xml',
    ], $overrides);
}

return [

    'a custom rss podcast fetch is not counted against the youtube budget' => static function (): void {
        maw_queue_raw(maw_podcast_rss());

        $state = maw_run_podcast_load(maw_podcast_config());

        maw_assert_same(1, count(MawTestState::$httpRequests), 'the RSS feed is fetched');
        maw_assert_same(false, $state['errorLoadingData'], 'the podcast loads successfully');
        maw_assert_same(0, YoutubeGuard::getDailyCallCount(), 'the YouTube daily counter stays at zero');
        maw_assert_same(null, YoutubeGuard::getGuardStatus(), 'no guard event is recorded');
    },

    'a podcast fetch still works when the youtube budget is exhausted' => static function (): void {
        // Spend the whole YouTube budget first.
        maw_assert_same(true, YoutubeGuard::reserveDailyCall(1), 'the single YouTube slot is consumed');
        maw_assert_same(false, YoutubeGuard::reserveDailyCall(1), 'YouTube is now blocked');

        maw_queue_raw(maw_podcast_rss());

        $state = maw_run_podcast_load(maw_podcast_config(['youtube_daily_call_limit' => 1]));

        maw_assert_same(1, count(MawTestState::$httpRequests), 'the podcast request still goes outbound');
        maw_assert_same(false, $state['errorLoadingData'], 'the podcast still loads');
        maw_assert(
            get_transient('podcast_testpod') !== false,
            'the podcast transient is still written'
        );
    },

    'a podcast fetch does not take the youtube playlist lock' => static function (): void {
        global $wpdb;

        maw_queue_raw(maw_podcast_rss());
        maw_run_podcast_load(maw_podcast_config());

        maw_assert_same(
            null,
            $wpdb->peek(YoutubeGuard::lockOptionName('testpod')),
            'no YouTube refresh lock is created for a podcast'
        );
    },

    'an apple lookup podcast is not counted against the youtube budget' => static function (): void {
        maw_queue_json(['results' => [['feedUrl' => 'https://feeds.example.com/apple.xml', 'collectionViewUrl' => 'https://podcasts.apple.com/x']]]);
        maw_queue_raw(maw_podcast_rss());

        // PRE-EXISTING DEFECT, unrelated to the YouTube guards and deliberately
        // left unfixed here because it is outside this change's scope:
        // loadPodcastData() hands the raw wp_remote_get() array straight to
        // parseRssFeed(?string $rssFeedInput), so every Apple/iTunes lookup
        // platform ('omny', 'soundcloud', 'buzzsprout', 'other') throws a
        // TypeError. This test pins the current behavior and still asserts the
        // guard invariant that matters: the lookup consumed no YouTube budget.
        $threw = false;
        try {
            maw_run_podcast_load(maw_podcast_config([
                'podcast_platform' => 'omny',
                'media_data'       => '123456789',
            ]));
        } catch (TypeError $e) {
            $threw = true;
            maw_assert(
                str_contains($e->getMessage(), 'parseRssFeed'),
                'the pre-existing failure is the parseRssFeed argument type, not a guard change'
            );
        }

        maw_assert_same(true, $threw, 'the Apple lookup path still fails exactly as it did before this change');
        maw_assert_same(1, count(MawTestState::$httpRequests), 'the iTunes lookup request went outbound');
        maw_assert_same(0, YoutubeGuard::getDailyCallCount(), 'the YouTube daily counter stays at zero');
        maw_assert_same(null, YoutubeGuard::getGuardStatus(), 'no YouTube guard event is recorded');
    },

    'an embed podcast makes no request and no reservation' => static function (): void {
        $state = maw_run_podcast_load(maw_podcast_config([
            'podcast_platform' => 'embed',
            'media_data'       => 'https://player.example.com/embed/1',
        ]));

        maw_assert_same(0, count(MawTestState::$httpRequests), 'no request is made for an embed');
        maw_assert_same('https://player.example.com/embed/1', $state['parsedData'], 'the embed URL is stored directly');
        maw_assert_same(0, YoutubeGuard::getDailyCallCount(), 'the YouTube daily counter stays at zero');
    },

    'a failed podcast fetch does not record a youtube guard event' => static function (): void {
        maw_queue_error();

        $state = maw_run_podcast_load(maw_podcast_config());

        maw_assert_same(true, $state['errorLoadingData'], 'the podcast failure is reported');
        maw_assert_same(null, YoutubeGuard::getGuardStatus(), 'no YouTube guard event is recorded');
        maw_assert_same(0, YoutubeGuard::getDailyCallCount(), 'the YouTube daily counter stays at zero');
    },
];
