<?php
/**
 * Tests that the podcast path is untouched by the YouTube guards.
 *
 * The daily circuit breaker must count YouTube playlistItems requests only.
 * Podcast RSS, Apple/iTunes lookups, and plugin-update requests are unrelated
 * services with their own limits and must never be blocked by it.
 */

declare(strict_types=1);

use MediaApiWidget\PodcastPlayer\DataParams;
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

        // Until 5.0.0 this path threw a TypeError, because loadPodcastData()
        // handed the raw wp_remote_get() array to parseRssFeed(?string). It now
        // passes the response body, so the lookup and the RSS fetch both
        // complete — which is what lets the extension-api group assert that a
        // successful lookup followed by a *failed* RSS fetch fires no hooks.
        $state = maw_run_podcast_load(maw_podcast_config([
            'podcast_platform' => 'omny',
            'media_data'       => '123456789',
        ]));

        maw_assert_same(false, $state['errorLoadingData'], 'the Apple lookup path completes successfully');
        maw_assert_same(2, count(MawTestState::$httpRequests), 'the iTunes lookup and the RSS feed are both fetched');
        maw_assert(
            str_contains(MawTestState::$httpRequests[0], 'itunes.apple.com/lookup?id=123456789'),
            'the Apple podcast id is sent to the iTunes lookup endpoint'
        );
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

    'the custom podcast player accepts an explicit autoplay flag' => static function (): void {
        $previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;

        try {
            $_SERVER['REQUEST_URI'] = '/podcast/player?url=' . rawurlencode('https://feeds.example.com/podcast.xml') . '&autoplay=1';
            maw_queue_raw(maw_podcast_rss());

            $params = (new DataParams())->build();

            maw_assert_same(false, $params['error_loading_rss'], 'the podcast player data loads');
            maw_assert_same(true, $params['autoplay'], 'autoplay=1 enables autoplay');
        } finally {
            if ($previousRequestUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousRequestUri;
            }
        }
    },

    'the custom podcast player stays paused when autoplay is omitted' => static function (): void {
        $previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;

        try {
            $_SERVER['REQUEST_URI'] = '/podcast/player?url=' . rawurlencode('https://feeds.example.com/podcast.xml');
            maw_queue_raw(maw_podcast_rss());

            $params = (new DataParams())->build();

            maw_assert_same(false, $params['error_loading_rss'], 'the podcast player data loads');
            maw_assert_same(false, $params['autoplay'], 'the existing paused default is preserved');
        } finally {
            if ($previousRequestUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousRequestUri;
            }
        }
    },
];
