<?php
namespace MediaApiWidget\PodcastPlayer;

if (!defined('ABSPATH')) { exit; }

/**
 * Builds the template variable array for the /podcast/player page.
 *
 * Parses the incoming request URI for configuration query parameters
 * (RSS URL, starting track, color theme, font), fetches and parses the
 * RSS feed, processes each episode (stripping tags, optionally appending
 * publish dates, extracting iTunes artwork), and returns a single flat
 * associative array that the podcast-player.php template can extract
 * directly with extract().
 *
 * All external network calls are made via file_get_contents(). The class
 * is intentionally free of WordPress HTTP API dependencies so the player
 * page can remain a lightweight standalone document.
 */
final class DataParams
{
    /**
     * Builds and returns the full set of template variables for the player page.
     *
     * Reads configuration from the current request URI's query string:
     * - url              — required; the RSS feed to load.
     * - track            — 1-based episode index to start on (default 0 = first).
     * - mode             — background color: 'dark', 'light', or a hex string.
     * - color1           — accent color hex.
     * - progressbarcolor — progress bar hex.
     * - buttoncolor      — play button icon hex.
     * - highlightcolor   — selected episode highlight hex.
     * - scrollcolor      — scrollbar hex.
     * - textcolor        — text hex.
     * - font             — Google Fonts font name.
     * - adddatetotitle   — if present, the publish date is appended to each title.
     * - singleepisode    — GUID string; hides the episode list and jumps to that GUID.
     *
     * On error (missing URL, unreachable feed, unparseable XML, or an
     * out-of-range track), error_loading_rss is set to true and err_msg
     * contains a human-readable explanation.
     *
     * @return array<string,mixed> Template variables:
     *   - bool                       $error_loading_rss  True if any error occurred.
     *   - string|null                $err_msg            Human-readable error, or null.
     *   - string|null                $rss_url            The RSS URL from the query string.
     *   - array<string,string>       $style              Resolved color/font settings.
     *   - int                        $track_selected     0-based index of the starting episode.
     *   - string                     $single_episode     GUID of a single episode, or 'false'.
     *   - string|null                $add_date_to_title  Non-null when date appending is on.
     *   - \SimpleXMLElement|null     $parsed_rss_feed    The full parsed RSS document.
     *   - \SimpleXMLElement|null     $channel            The channel element of the feed.
     *   - \SimpleXMLElement|null     $episodes           All episode item elements.
     *   - \SimpleXMLElement|null     $episode_selected   The episode at $track_selected.
     *   - string|null                $starting_episode_id GUID of the starting episode.
     *   - string|null                $rss_data           JSON-encoded RSS document.
     *   - \SimpleXMLElement|null     $audio_data         Enclosure attributes of the episode.
     *   - string|null                $podcast_image      URL of the podcast / episode artwork.
     */
    public function build(): array
    {
        $urlOrigin     = $_SERVER['REQUEST_URI'] ?? '';
        $urlComponents = parse_url($urlOrigin);

        $error  = false;
        $errMsg = null;

        if (empty($urlComponents['query'])) {
            $error = true;
        }

        parse_str($urlComponents['query'] ?? '', $params);

        $rssUrl = $params['url'] ?? null;

        // Styling
        $mode = $params['mode'] ?? 'dark';
        $mode = $this->normalizeColorMode($mode);

        $style = [
            'mode'            => $mode,
            'color1'          => $this->normalizeHex($params['color1'] ?? 'bbbbbb'),
            'progressBarColor'=> $this->normalizeHex($params['progressbarcolor'] ?? '616161'),
            'playButton'      => $this->normalizeHex($params['buttoncolor'] ?? 'ffffff'),
            'highlight'       => $this->normalizeHex($params['highlightcolor'] ?? '888888'),
            'scrollbar'       => $this->normalizeHex($params['scrollcolor'] ?? 'bbbbbb'),
            'textColor'       => $this->normalizeHex($params['textcolor'] ?? 'ffffff'),
            'font'            => sanitize_text_field((string) ($params['font'] ?? 'Poppins')),
        ];

        $trackSelected = intval($params['track'] ?? 0);
        $trackSelected = $trackSelected !== 0 ? $trackSelected - 1 : 0;

        $singleEpisode  = ($params['singleepisode'] ?? null) !== null ? (string) $params['singleepisode'] : 'false';
        $addDateToTitle = $params['adddatetotitle'] ?? null;

        if (!$rssUrl) {
            $errMsg = 'An RSS url parameter must be provided in order to retrieve a podcast.';
            $error  = true;
        }

        $rssFeed = $rssUrl ? @file_get_contents($rssUrl) : null;

        $fallbackImageSet = false;
        $podcastImage     = null;

        $parsed            = null;
        $channel           = null;
        $episodes          = [];
        $episodeSelected   = null;
        $startingEpisodeId = null;
        $rssDataJson       = null;
        $audioData         = null;

        if (!$rssFeed) {
            $error = true;
        } else {
            $parsed = @simplexml_load_string($rssFeed);
            if (!$parsed || !$parsed->channel) {
                $error = true;
            } else {
                $parsed->channel->rssUrl      = $rssUrl;
                $parsed->channel->description = strip_tags((string) $parsed->channel->description);

                $itemIteration = 0;
                foreach ($parsed->channel->item as $item) {
                    $item->title       = strip_tags((string) $item->title);
                    $item->description = strip_tags((string) $item->description);

                    if ($addDateToTitle !== null && isset($item->pubDate)) {
                        try {
                            $date          = new \DateTime((string) $item->pubDate);
                            $publishedDate = $date->format('F j, Y');
                            $item->title   = (string) $item->title . ' - ' . $publishedDate;
                        } catch (\Exception) {
                            // ignore unparseable dates
                        }
                    }

                    $item->guid = strip_tags((string) $item->guid);

                    $itunesImage = $item->children('itunes', true)->image->attributes();
                    if ($itunesImage && isset($itunesImage['href'])) {
                        $item->image = (string) $itunesImage['href'];
                        if ($fallbackImageSet === false) {
                            $fallbackImageSet = true;
                            $podcastImage     = (string) $item->image;
                        }
                    }

                    if ($singleEpisode !== 'false' && (string) $item->guid === $singleEpisode) {
                        $trackSelected = $itemIteration;
                    }
                    $itemIteration++;
                }

                $channel         = $parsed->channel;
                $episodes        = $parsed->channel->item;
                $episodeSelected = $trackSelected ? $episodes[$trackSelected] : $episodes[0];
                $startingEpisodeId = $episodeSelected->guid ?? null;

                if (!$startingEpisodeId) {
                    $error  = true;
                    $errMsg = 'Track number ' . ($trackSelected + 1) . ' does not exist on this podcast. Please enter a lower track number.';
                } else {
                    $rssDataJson = json_encode($parsed);
                    $audioData   = $episodeSelected->enclosure->attributes() ? $episodeSelected->enclosure->attributes() : null;
                }
            }
        }

        // Channel image fallback
        if (!$error) {
            if (!isset($parsed->channel->image->url) || !isset($parsed->channel->image->url[0])) {
                if (!$fallbackImageSet) {
                    $podcastImage = 'will-francis-ZDNyhmgkZlQ-unsplash.jpg';
                }
            } else {
                $podcastImage = (string) $parsed->channel->image->url[0];
            }
        }

        if ($error && !$errMsg) {
            $errMsg = 'The RSS url parameter provided was not able to retrieve a podcast RSS feed. Check the RSS url parameter.';
        }

        return [
            'error_loading_rss'  => $error,
            'err_msg'            => $errMsg,
            'rss_url'            => $rssUrl,
            'style'              => $style,
            'track_selected'     => $trackSelected,
            'single_episode'     => $singleEpisode,
            'add_date_to_title'  => $addDateToTitle,
            'parsed_rss_feed'    => $parsed,
            'channel'            => $channel,
            'episodes'           => $episodes,
            'episode_selected'   => $episodeSelected,
            'starting_episode_id'=> $startingEpisodeId,
            'rss_data'           => $rssDataJson,
            'audio_data'         => $audioData,
            'podcast_image'      => $podcastImage,
        ];
    }

    /**
     * Validates and normalizes a hex color string.
     *
     * Strips a leading '#', lowercases, and validates the string against
     * 3- or 6-character hex patterns. Returns '#ffffff' as a safe fallback
     * for any value that does not match. The returned string always includes
     * the leading '#'.
     *
     * @param string $hex Raw hex value, optionally prefixed with '#'.
     * @return string Normalized hex color with '#' prefix (e.g. '#c7c7c7').
     */
    private function normalizeHex(string $hex): string
    {
        $hex = ltrim(strtolower(trim($hex)), '#');
        if (!preg_match('/^[0-9a-f]{3}([0-9a-f]{3})?$/', $hex)) {
            $hex = 'ffffff';
        }
        return '#' . $hex;
    }

    /**
     * Converts a color mode string into a CSS color value.
     *
     * Accepts the named modes 'dark' (→ '#000') and 'light' (→ '#fff'), or
     * a raw hex string (with or without '#'). Invalid hex values fall back
     * to '#000' (dark mode). The returned string always includes the leading '#'.
     *
     * @param string $mode 'dark', 'light', or a hex color string.
     * @return string CSS color value with '#' prefix (e.g. '#000', '#fff', '#1a1a2e').
     */
    private function normalizeColorMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        $hex  = ltrim($mode, '#');
        return match(true) {
            $mode === 'dark'  => '#000',
            $mode === 'light' => '#fff',
            (bool) preg_match('/^[0-9a-f]{3}([0-9a-f]{3})?$/', $hex) => '#' . $hex,
            default           => '#000',
        };
    }
}
