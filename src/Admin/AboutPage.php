<?php
namespace MediaApiWidget\Admin;

if (!defined('ABSPATH')) { exit; }

/**
 * Renders the About / Help documentation admin sub-page.
 *
 * Provides an in-admin reference guide covering every feature of the
 * Media API Widget plugin: admin setup, shortcode fields, all four
 * shortcode tags with copy-paste examples, the full attribute reference,
 * grid mode, the carousel layout (including its Elementor controls), the
 * podcast player, SEO meta tags, caching architecture, and
 * the developer hooks and data-enrichment API — the pre-store filter, the
 * post-storage action, and the stored-data updater function, together with
 * the recommended asynchronous transcription workflow.
 *
 * All page content is static HTML generated server-side; no database
 * reads are performed beyond the capability check.
 */
final class AboutPage
{
    /**
     * Renders the About / Help page HTML.
     *
     * Outputs a styled, sectioned documentation page with a jump-navigation
     * bar linking to each major topic. Inline styles are scoped to the
     * .maw-about wrapper so they do not bleed into the rest of wp-admin.
     *
     * Requires manage_options capability; calls wp_die() if the current
     * user does not have permission.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        ?>
        <div class="wrap maw-wrap maw-about">
            <h1>Media API Widget — About &amp; Usage Guide</h1>
            <p class="maw-note">Version <?php echo esc_html(MAW_PLUGIN_VERSION); ?> &nbsp;|&nbsp; Author: Chris Paschall</p>

            <nav class="maw-about-nav">
                <a href="#maw-overview">Overview</a>
                <a href="#maw-admin-setup">Admin Setup</a>
                <a href="#maw-shortcode-fields">Shortcode Fields</a>
                <a href="#maw-shortcodes">Shortcodes</a>
                <a href="#maw-render-attrs">Render Attributes</a>
                <a href="#maw-styling-hooks">Styling Hooks</a>
                <a href="#maw-grid">Grid Mode</a>
                <a href="#maw-grid-search">Grid Search &amp; Pagination</a>
                <a href="#maw-carousel">Carousel</a>
                <a href="#maw-stored-posts">Stored Posts &amp; Loop Grid</a>
                <a href="#maw-podcast-player">Podcast Player</a>
                <a href="#maw-seo">SEO</a>
                <a href="#maw-caching">Caching</a>
                <a href="#maw-js-events">JS Events</a>
                <a href="#maw-dev-hooks">Developer Hooks</a>
            </nav>

            <!-- OVERVIEW -->
            <section id="maw-overview" class="maw-section">
                <h2>Overview</h2>
                <p>Media API Widget connects your WordPress site to <strong>YouTube playlists</strong> and <strong>podcast RSS feeds</strong>, rendering them as interactive media thumbnails and players — all configured through the admin without touching code.</p>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Capability</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><strong>YouTube Playlists</strong></td><td>Fetches playlist items from the YouTube Data API v3, caches them, and renders clickable thumbnails that open a full lightbox video player with carousel navigation.</td></tr>
                        <tr><td><strong>Podcast Feeds</strong></td><td>Parses RSS feeds directly or via Apple/iTunes lookup for Omny, SoundCloud, Buzzsprout, and others. Thumbnails open an embedded audio player.</td></tr>
                        <tr><td><strong>Custom Podcast Player</strong></td><td>A fully-themed, self-hosted podcast player served at <code>/podcast/player</code> and embeddable anywhere as an <code>&lt;iframe&gt;</code>.</td></tr>
                        <tr><td><strong>Grid &amp; Carousel Collections</strong></td><td>Show many episodes as a responsive grid or as a carousel with arrows, swiping, trackpad scrolling and mouse dragging &mdash; from a shortcode or either Elementor widget. See <a href="#maw-carousel">Carousel Layout</a>.</td></tr>
                        <tr><td><strong>Playlist Items as Posts</strong></td><td>Optionally keep every item of a playlist as a WordPress post, kept in sync, so Elementor Pro's Loop Grid and other builders can list them with their own templates. See <a href="#maw-stored-posts">Stored Playlist Posts</a>.</td></tr>
                        <tr><td><strong>Global Shortcode Fields</strong></td><td>Store key/value pairs in the admin and reference them dynamically in any shortcode attribute using <code>&#123;&#123;field_name&#125;&#125;</code> syntax.</td></tr>
                        <tr><td><strong>SEO Meta Tags</strong></td><td>Automatically generates Open Graph and Twitter Card tags based on the media content found on each page.</td></tr>
                        <tr><td><strong>Layered Cache</strong></td><td>Combines WordPress transients, local backup JSON files, and browser localStorage to minimize API calls and handle outages gracefully.</td></tr>
                        <tr><td><strong>API Stats</strong></td><td>Every external API call is logged to the database with 24-hour reporting in the admin, including when each playlist's fallback backup was last stored and a link to download it.</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- ADMIN SETUP -->
            <section id="maw-admin-setup" class="maw-section">
                <h2>Admin Setup — Media Items</h2>
                <p>Go to <strong>Media API → Playlist Settings</strong> to define your YouTube playlists and podcast feeds. Each <em>media item</em> gets a unique <strong>Name (playlist_name)</strong> — a lowercase slug you will reference in shortcodes.</p>

                <h3>YouTube</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Field</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Name (playlist_name)</strong></td><td>A unique slug, e.g. <code>my_show</code>. Used in shortcodes as <code>playlist_name="my_show"</code>.</td></tr>
                        <tr><td><strong>Playlist ID</strong></td><td>The YouTube playlist ID — the <code>list=</code> value from the playlist URL.</td></tr>
                        <tr><td><strong>API Key</strong></td><td>Your Google/YouTube Data API v3 key from Google Cloud Console.</td></tr>
                        <tr><td><strong>Sort Mode</strong></td><td><em>Normal</em> — preserves YouTube order. <em>Number in title</em> — extracts the leading number from each video title and sorts descending. Ideal for TV episodes numbered in their title.</td></tr>
                        <tr><td><strong>Load full playlist</strong></td><td>When checked, fetches all videos (paginating past the 50-item API limit). When unchecked, limits to the first 6.</td></tr>
                        <tr><td><strong>Store playlist items as WordPress posts</strong></td><td>Off by default. Keeps one post per video for Loop Grids and other builders. See <a href="#maw-stored-posts">Stored Playlist Posts</a>.</td></tr>
                    </tbody>
                </table>

                <h3>Podcast</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Field</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Name (playlist_name)</strong></td><td>A unique slug, e.g. <code>my_podcast</code>.</td></tr>
                        <tr><td><strong>Platform</strong></td><td>See the Podcast Platform table below.</td></tr>
                        <tr><td><strong>RSS URL / ID / Embed URL</strong></td><td>Depends on the platform chosen — RSS URL, Apple numeric ID, or an embed player URL.</td></tr>
                        <tr><td><strong>Season/episode regex</strong></td><td>Off by default. When checked, a pattern numbers each episode from its title: named groups <code>season</code> and <code>episode</code> are used when present; otherwise two groups are season then episode, and one group is the episode. Matching ignores case. A title the pattern does not match is left unnumbered &mdash; numbers are never guessed.</td></tr>
                        <tr><td><strong>Sort mode</strong></td><td>Shown with the regex. <em>Season / episode (newest first)</em> (default) orders numbered episodes by season, then episode, newest first, with unnumbered episodes after them in feed order. <em>Feed order</em> keeps the feed's order and only adds the numbers. This is the order of the plugin's podcast cards and grids and of stored posts; the <code>/podcast/player</code> page reads the feed itself and keeps the feed's order.</td></tr>
                        <tr><td><strong>Store playlist items as WordPress posts</strong></td><td>Off by default. Keeps one post per episode. See <a href="#maw-stored-posts">Stored Playlist Posts</a>.</td></tr>
                    </tbody>
                </table>

                <h3>Podcast Platforms</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Platform</th><th>media_data value</th><th>How it works</th></tr></thead>
                    <tbody>
                        <tr><td><code>custom</code> — Direct RSS URL</td><td>Full RSS feed URL</td><td>The plugin fetches and parses the RSS feed directly. The custom in-house podcast player is used on click.</td></tr>
                        <tr><td><code>omny</code></td><td>Apple Podcast numeric ID</td><td>Looks up the RSS feed via the iTunes API, then parses it. Opens the Omny embed player on click.</td></tr>
                        <tr><td><code>soundcloud</code></td><td>Apple Podcast numeric ID</td><td>Same iTunes lookup. Opens the SoundCloud embed player on click.</td></tr>
                        <tr><td><code>buzzsprout</code></td><td>Apple Podcast numeric ID</td><td>Same iTunes lookup. Opens the Buzzsprout embed player on click.</td></tr>
                        <tr><td><code>other</code></td><td>Apple Podcast numeric ID</td><td>Generic iTunes lookup for any Apple-listed podcast.</td></tr>
                        <tr><td><code>embed</code></td><td>An embed player URL</td><td>Skips RSS entirely — embeds the provided URL directly in an iframe on click.</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- SHORTCODE FIELDS -->
            <section id="maw-shortcode-fields" class="maw-section">
                <h2>Shortcode Fields — Centralized Values</h2>
                <p>Shortcode fields are global <strong>key → value</strong> pairs stored in the database. You can reference them inside any shortcode attribute using <code>&#123;&#123;field_name&#125;&#125;</code> or <code>[media-api-widget field="field_name"]</code>. This lets you update a color, font, or label in one place and have it take effect everywhere.</p>

                <h3>Default Shortcode Fields (Auto-Seeded)</h3>
                <p>These ten fields are created on activation. Their <strong>field names are locked</strong> (cannot be renamed or deleted), but you can change their values freely. They drive the default styling of the podcast player and lightbox panel across the entire site.</p>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Field Name</th><th>Default Value</th><th>Controls</th></tr></thead>
                    <tbody>
                        <tr><td><code>podcast_player_background_color</code></td><td><code>#151515</code></td><td>Player background / mode color</td></tr>
                        <tr><td><code>podcast_player_text_color</code></td><td><code>#ffffff</code></td><td>Player text color</td></tr>
                        <tr><td><code>podcast_player_play_icon_color</code></td><td><code>#ffffff</code></td><td>Play button icon color</td></tr>
                        <tr><td><code>podcast_player_color</code></td><td><code>#c7c7c7</code></td><td>Accent / UI element color</td></tr>
                        <tr><td><code>podcast_player_progress_bar_color</code></td><td><code>#616161</code></td><td>Progress bar fill color</td></tr>
                        <tr><td><code>podcast_player_selected_color</code></td><td><code>#7a7a7a</code></td><td>Selected episode highlight color</td></tr>
                        <tr><td><code>podcast_player_font</code></td><td><code>Roboto</code></td><td>Google Fonts font name</td></tr>
                        <tr><td><code>podcast_player_scrollbar_color</code></td><td><code>#c7c7c7</code></td><td>Scrollbar color</td></tr>
                        <tr><td><code>lightbox_playlist_logo</code></td><td><em>(empty)</em></td><td>Logo image URL displayed in the lightbox playlist panel header</td></tr>
                        <tr><td><code>lightbox_playlist_border_color</code></td><td><code>#ffffff</code></td><td>Border / theme color of the lightbox playlist panel</td></tr>
                    </tbody>
                </table>

                <h3>Using Field References in Shortcodes</h3>
                <p>Any shortcode attribute that accepts a string value can reference a stored field. Both syntaxes are equivalent:</p>
                <pre class="maw-code">&#123;&#123;podcast_player_background_color&#125;&#125;
[media-api-widget field="podcast_player_background_color"]</pre>

                <p><strong>Example — referencing the admin color fields in a render shortcode:</strong></p>
                <pre class="maw-code">[media-api-widget-render
    playlist_name="my_podcast"
    media_platform="podcast"
    podcast_platform="custom"
    thumbnail="https://example.com/cover.jpg"
    podcastplayermode="&#123;&#123;podcast_player_background_color&#125;&#125;"
    podcastplayercolor="&#123;&#123;podcast_player_color&#125;&#125;"
    podcastplayertextcolor="&#123;&#123;podcast_player_text_color&#125;&#125;"
    podcastplayerbuttoncolor="&#123;&#123;podcast_player_play_icon_color&#125;&#125;"
    podcastprogressplayerbarcolor="&#123;&#123;podcast_player_progress_bar_color&#125;&#125;"
    podcastplayerhighlightcolor="&#123;&#123;podcast_player_selected_color&#125;&#125;"
    podcastplayerfont="&#123;&#123;podcast_player_font&#125;&#125;"
    podcastplayerscrollcolor="&#123;&#123;podcast_player_scrollbar_color&#125;&#125;"
]</pre>

                <div class="maw-callout">
                    <strong>Tip:</strong> When using <code>[media-api-podcast-player]</code>, the default podcast styling fields are applied <em>automatically</em> — you only need to set attribute values if you want to override a specific field for that particular instance.
                </div>
            </section>

            <!-- SHORTCODES -->
            <section id="maw-shortcodes" class="maw-section">
                <h2>Shortcodes</h2>
                <p>The plugin registers five shortcode tags:</p>

                <h3><code>[media-api-widget]</code> — Output a Field Value</h3>
                <p>Outputs the stored value of a shortcode field as plain text. Use it to inject centrally managed copy anywhere on a page.</p>
                <pre class="maw-code">[media-api-widget field="field_name"]</pre>
                <p><strong>Example:</strong></p>
                <pre class="maw-code">[media-api-widget field="show_tagline"]
&rarr; All-new episodes every Tuesday</pre>

                <hr>

                <h3><code>[media-api-widget-render]</code> — Render a Media Item</h3>
                <p>Renders a clickable thumbnail for a YouTube video or podcast episode. On click, a lightbox opens with the appropriate player. Also available as <code>[media-api-widget-item]</code>.</p>

                <p><strong>YouTube — most recent video:</strong></p>
                <pre class="maw-code">[media-api-widget-render playlist_name="my_show" media_platform="youtube" orderdescending="1"]</pre>

                <p><strong>YouTube — by episode number:</strong></p>
                <pre class="maw-code">[media-api-widget-render playlist_name="my_show" media_platform="youtube" episodenumber="5"]</pre>

                <p><strong>YouTube — by title keyword:</strong></p>
                <pre class="maw-code">[media-api-widget-render playlist_name="my_show" media_platform="youtube" nameselect="pilot"]</pre>

                <p><strong>YouTube — all episodes in a grid:</strong></p>
                <pre class="maw-code">[media-api-widget-render playlist_name="my_show" media_platform="youtube" multiplegrid="true" multiplegridtext="title"]</pre>

                <p><strong>YouTube — the latest 12 episodes in a carousel:</strong></p>
                <pre class="maw-code">[media-api-widget-render playlist_name="my_show" media_platform="youtube" multiplegrid="true" multiplegridlayout="carousel" multiplegridlimititems="12"]</pre>

                <p><strong>Podcast — episode thumbnail (Direct RSS):</strong></p>
                <pre class="maw-code">[media-api-widget-render
    playlist_name="my_podcast"
    media_platform="podcast"
    podcast_platform="custom"
    orderdescending="1"
    thumbnail="https://example.com/cover.jpg"
]</pre>

                <p><strong>Text-only — episode title:</strong></p>
                <pre class="maw-code">[media-api-widget-render playlist_name="my_show" media_platform="youtube" orderdescending="1" mediatitle="true"]</pre>

                <p><strong>Text-only — episode description with color:</strong></p>
                <pre class="maw-code">[media-api-widget-render playlist_name="my_show" media_platform="youtube" orderdescending="1" mediadescription="true" mediadescriptiontextcolor="#555555"]</pre>

                <hr>

                <h3><code>[media-api-podcast-player]</code> — Inline Podcast Player</h3>
                <p>Renders the full custom podcast player as an inline <code>&lt;iframe&gt;</code> (~32.5 rem tall, 100% wide). Styling defaults automatically to the admin shortcode field values.</p>

                <p><strong>Basic usage:</strong></p>
                <pre class="maw-code">[media-api-podcast-player playlist_name="my_podcast"]</pre>

                <p><strong>Start on episode 3:</strong></p>
                <pre class="maw-code">[media-api-podcast-player playlist_name="my_podcast" orderdescending="3"]</pre>

                <p><strong>With explicit colors:</strong></p>
                <pre class="maw-code">[media-api-podcast-player
    playlist_name="my_podcast"
    podcastplayermode="dark"
    podcastplayercolor="#c7c7c7"
    podcastplayertextcolor="#ffffff"
    podcastplayerbuttoncolor="#ffffff"
    podcastprogressplayerbarcolor="#616161"
    podcastplayerhighlightcolor="#7a7a7a"
    podcastplayerscrollcolor="#c7c7c7"
    podcastplayerfont="Poppins"
    showepisodedateaftertitle="true"
]</pre>

                <hr>

                <h3><code>[media-api-widget-grid-search]</code> — Grid Search Bar</h3>
                <p>Renders a search bar that pairs with a <code>[media-api-widget-render]</code> grid running in <code>multiplegridusersearch="true"</code> mode. The bar is linked to the correct grid by matching <code>playlist_name</code> and <code>media_platform</code>. It can be placed anywhere on the page — it does not need to be adjacent to the grid shortcode.</p>

                <p><strong>Basic usage:</strong></p>
                <pre class="maw-code">[media-api-widget-grid-search playlist_name="my_show" media_platform="youtube"]</pre>

                <p><strong>With a custom placeholder:</strong></p>
                <pre class="maw-code">[media-api-widget-grid-search playlist_name="my_show" media_platform="youtube" placeholder="Search episodes..."]</pre>

                <p><strong>Hide the Search By dropdown (always searches across title and description):</strong></p>
                <pre class="maw-code">[media-api-widget-grid-search playlist_name="my_show" media_platform="youtube" searchbyenabled="false"]</pre>

                <p><strong>Hide the clear button:</strong></p>
                <pre class="maw-code">[media-api-widget-grid-search playlist_name="my_show" media_platform="youtube" clearsearchbutton="false"]</pre>

                <table class="widefat striped maw-table maw-about-table" style="margin-top:12px;">
                    <thead><tr><th style="width:200px;">Attribute</th><th style="width:160px;">Default</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>playlist_name</code></td><td><em>required</em></td><td>Must match the <code>playlist_name</code> on the target grid shortcode.</td></tr>
                        <tr><td><code>media_platform</code></td><td><em>required</em></td><td>Must match the <code>media_platform</code> on the target grid shortcode.</td></tr>
                        <tr><td><code>placeholder</code></td><td><code>Search...</code></td><td>Placeholder text shown inside the search input field.</td></tr>
                        <tr><td><code>searchbyenabled</code></td><td><code>true</code></td><td>When <code>false</code>, hides the Search By dropdown and always searches across both title and description.</td></tr>
                        <tr><td><code>clearsearchbutton</code></td><td><code>true</code></td><td>When <code>false</code>, hides the clear (×) button that appears next to the search input. When visible, clicking it clears the field, fires the search AJAX immediately, and returns focus to the input.</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- RENDER ATTRIBUTES -->
            <section id="maw-render-attrs" class="maw-section">
                <h2>Full Attribute Reference — <code>[media-api-widget-render]</code></h2>

                <h3>Selection</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:220px;">Attribute</th><th style="width:130px;">Default</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>playlist_name</code></td><td><em>required</em></td><td>Slug matching the Name defined in admin Media Items.</td></tr>
                        <tr><td><code>media_platform</code></td><td><code>youtube</code></td><td><code>youtube</code> or <code>podcast</code>.</td></tr>
                        <tr><td><code>podcast_platform</code></td><td><em>from admin</em></td><td>Override the podcast platform: <code>custom</code>, <code>omny</code>, <code>soundcloud</code>, <code>buzzsprout</code>, <code>other</code>, <code>embed</code>.</td></tr>
                        <tr><td><code>orderdescending</code></td><td>—</td><td>Select by 1-based position. <code>1</code> = most recent / first item.</td></tr>
                        <tr><td><code>episodenumber</code></td><td>—</td><td>YouTube only. Select by episode number (extracted from title in <em>number_in_title</em> sort mode).</td></tr>
                        <tr><td><code>nameselect</code></td><td>—</td><td>Select the first item whose title contains this keyword (case-insensitive).</td></tr>
                    </tbody>
                </table>
                <p class="description"><strong>Priority:</strong> <code>episodenumber</code> → <code>nameselect</code> → <code>orderdescending</code>.</p>

                <h3>Display</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:220px;">Attribute</th><th style="width:200px;">Default</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>showplaybutton</code></td><td><code>true</code></td><td>Show the play button icon over the thumbnail.</td></tr>
                        <tr><td><code>playbuttoniconimgurl</code></td><td>—</td><td>URL of a custom play button image. Replaces the default SVG.</td></tr>
                        <tr><td><code>playbuttonstyling</code></td><td><code>width: 35%; height: 35%; opacity: 0.3;</code></td><td>Inline CSS applied to the play button container.</td></tr>
                        <tr><td><code>showtextoverlay</code></td><td><code>true</code></td><td>Show episode title + instruction message over the thumbnail.</td></tr>
                        <tr><td><code>instructionmessage</code></td><td><em>Click Here To Watch/Listen</em></td><td>The call-to-action text shown in the text overlay.</td></tr>
                        <tr><td><code>fontfamily</code></td><td>—</td><td>Font family for the media item container.</td></tr>
                        <tr><td><code>thumbnail</code></td><td>—</td><td>Thumbnail image URL. Required for podcasts; YouTube thumbnails are fetched automatically.</td></tr>
                        <tr><td><code>logo</code> / <code>lightboxshowlogoimgurl</code></td><td>—</td><td>URL of a logo displayed in the lightbox playlist panel header.</td></tr>
                        <tr><td><code>lightboxfont</code></td><td>—</td><td>Font family for the lightbox.</td></tr>
                        <tr><td><code>lightboxthemecolor</code></td><td>—</td><td>Border color of the lightbox playlist panel.</td></tr>
                        <tr><td><code>lightboxshowplaylist</code></td><td><code>false</code></td><td>When <code>true</code>, the lightbox opens showing the full playlist panel.</td></tr>
                        <tr><td><code>showplaybar</code></td><td><code>false</code></td><td>For podcast items: show an SVG audio waveform bar below the thumbnail.</td></tr>
                        <tr><td><code>playbarcolor</code></td><td><code>#fff</code></td><td>Color of the audio play bar.</td></tr>
                        <tr><td><code>mediatitle</code></td><td><code>false</code></td><td>When <code>true</code>, output the episode title as a <code>&lt;p&gt;</code> tag only — no thumbnail rendered.</td></tr>
                        <tr><td><code>mediadescription</code></td><td><code>false</code></td><td>When <code>true</code>, output the episode description as a <code>&lt;p&gt;</code> tag only — no thumbnail rendered.</td></tr>
                        <tr><td><code>mediadescriptiontextcolor</code></td><td>—</td><td>CSS color for the <code>mediatitle</code> / <code>mediadescription</code> output.</td></tr>
                        <tr><td><code>nostyling</code></td><td><code>false</code></td><td>When <code>true</code>, suppresses the plugin's <strong>styling</strong> class names (<code>media_item</code>, <code>media-item-*</code>, <code>media_items_multiple_grid_layout</code>, <code>media-carousel*</code>, &hellip;) so you don't inherit the plugin's built-in CSS. The namespaced <code>maw-</code> <strong>hook classes</strong> (see <a href="#maw-styling-hooks">Styling Hooks</a>) are still emitted either way. Applies to all render modes: single item, text-only, static grid, user-search grid, and carousel (which keeps its functional layout &mdash; see <a href="#maw-carousel">Carousel Layout</a>). Data attributes used by the lightbox JS are unaffected.</td></tr>
                        <tr><td><code>showlightbox</code></td><td><code>true</code></td><td>When <code>false</code>, clicking the item dispatches the <a href="#maw-js-events"><code>mediaApiWidgetItemClick</code></a> JS event but does <strong>not</strong> open the lightbox. Omitting this attribute (the default) preserves normal lightbox behavior for all existing shortcodes.</td></tr>
                    </tbody>
                </table>

                <h3>Podcast Player Styling</h3>
                <p>These attributes control the podcast player opened when a podcast thumbnail is clicked, or the inline player from <code>[media-api-podcast-player]</code>. If left empty, the <strong>admin shortcode fields</strong> are used automatically.</p>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:270px;">Attribute</th><th style="width:130px;">Default</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>podcastplayermode</code></td><td><code>dark</code></td><td>Background mode: <code>dark</code>, <code>light</code>, or any hex color (e.g. <code>#1a1a2e</code>).</td></tr>
                        <tr><td><code>podcastplayertextcolor</code></td><td><code>#ffffff</code></td><td>Text color.</td></tr>
                        <tr><td><code>podcastplayerbuttoncolor</code></td><td><code>#ffffff</code></td><td>Play button icon color.</td></tr>
                        <tr><td><code>podcastplayercolor</code></td><td><code>#c7c7c7</code></td><td>Accent / UI element color.</td></tr>
                        <tr><td><code>podcastprogressplayerbarcolor</code></td><td><code>#616161</code></td><td>Progress bar fill color.</td></tr>
                        <tr><td><code>podcastplayerhighlightcolor</code></td><td><code>#7a7a7a</code></td><td>Selected episode highlight color.</td></tr>
                        <tr><td><code>podcastplayerfont</code></td><td><code>Roboto</code></td><td>Google Fonts font name.</td></tr>
                        <tr><td><code>podcastplayerscrollcolor</code></td><td><code>#c7c7c7</code></td><td>Scrollbar color.</td></tr>
                        <tr><td><code>showepisodedateaftertitle</code></td><td>—</td><td>Set to <code>true</code> to append the publish date to each episode title.</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- STYLING HOOKS -->
            <section id="maw-styling-hooks" class="maw-section">
                <h2>Styling Hooks</h2>
                <p>Every rendered element carries a namespaced <code>maw-</code> <strong>hook class</strong> in addition to the plugin's default styling class. The plugin ships <strong>no CSS for the <code>maw-</code> classes</strong> — they exist purely as stable targets for your own styles, so they are emitted <strong>even when <code>nostyling="true"</code></strong>. That gives you a blank visual slate plus reliable selectors.</p>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:340px;">Element</th><th>Hook class</th></tr></thead>
                    <tbody>
                        <tr><td>Grid container</td><td><code>maw-media-items-grid-layout</code></td></tr>
                        <tr><td>Item link (<code>&lt;a&gt;</code>)</td><td><code>maw-media-item</code> (＋ <code>maw-media-item-text-overlay-enabled</code> when the overlay is on)</td></tr>
                        <tr><td>Grid entry wrapper (when grid text is enabled)</td><td><code>maw-media-item-multiple-grid-entry</code></td></tr>
                        <tr><td>Thumbnail wrapper</td><td><code>maw-media-item-thumbnail-text-wrapper</code></td></tr>
                        <tr><td>Thumbnail image</td><td><code>maw-media-item-thumbnail</code></td></tr>
                        <tr><td>Play button</td><td><code>maw-media-item-play-button</code></td></tr>
                        <tr><td>Text overlay</td><td><code>maw-media-item-text-overlay</code></td></tr>
                        <tr><td>Grid text block</td><td><code>maw-media-item-multiple-grid-text</code> ＋ <code>&hellip;-text-title</code> / <code>&hellip;-text-description</code></td></tr>
                        <tr><td>Title/description block (<code>mediatitle</code> / <code>mediadescription</code>)</td><td><code>maw-media-description-text</code></td></tr>
                        <tr><td>Audio play bar</td><td><code>maw-audio-play-bar</code></td></tr>
                        <tr><td>Playlist heading / overlay sub-text</td><td><code>maw-playlist-episode-text</code> / <code>maw-sub-text</code></td></tr>
                        <tr><td>Carousel container / scrolling row / track / slide</td><td><code>maw-carousel</code> / <code>maw-carousel-viewport</code> / <code>maw-carousel-track</code> / <code>maw-carousel-slide</code></td></tr>
                        <tr><td>Carousel arrow button</td><td><code>maw-carousel-arrow</code> ＋ <code>maw-carousel-arrow-left</code> / <code>maw-carousel-arrow-right</code> (disabled: <code>[aria-disabled="true"]</code>)</td></tr>
                        <tr><td>Carousel arrow icon or image</td><td><code>maw-carousel-arrow-icon</code></td></tr>
                    </tbody>
                </table>
                <p class="description">The carousel's functional layout is attached to <code>data-maw-carousel*</code> attributes, not to these classes, so the hook classes stay free of plugin CSS. Its arrow appearance uses the <code>media-carousel</code> / <code>media-carousel-arrow</code> styling classes, which <code>nostyling</code> omits.</p>
                <p class="description">The grid wrapper, search bar, and pagination controls have always carried their own <code>maw-</code> classes (<code>maw-grid-search-wrapper</code>, <code>maw-grid-items</code>, <code>maw-grid-pagination</code>, <code>maw-page-btn</code>, <code>maw-search-input</code>, &hellip;) and are unaffected by <code>nostyling</code>.</p>
                <pre class="maw-code">/* With nostyling="true": no plugin styles to override, just hooks */
.maw-media-items-grid-layout &#123; gap: 2rem; &#125;
.maw-media-item &#123; border-radius: 12px; overflow: hidden; &#125;
.maw-media-item-thumbnail &#123; aspect-ratio: 16 / 9; &#125;</pre>
            </section>

            <!-- GRID MODE -->
            <section id="maw-grid" class="maw-section">
                <h2>Grid Mode</h2>
                <p>Set <code>multiplegrid="true"</code> on <code>[media-api-widget-render]</code> to render all (or a filtered subset of) playlist items in a responsive CSS grid layout. Each item is individually clickable. Add <code>multiplegridlayout="carousel"</code> to show the same items in a <a href="#maw-carousel">carousel</a> instead.</p>

                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:240px;">Attribute</th><th style="width:110px;">Default</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>multiplegrid</code></td><td><code>false</code></td><td>Set to <code>true</code> to enable grid mode.</td></tr>
                        <tr><td><code>multiplegridlayout</code></td><td><code>grid</code></td><td><code>grid</code> or <code>carousel</code>, for both <code>multiplegrid</code> and <code>multiplegridusersearch</code>. Any other value keeps the grid. See <a href="#maw-carousel">Carousel Layout</a>.</td></tr>
                        <tr><td><code>multiplegridshowall</code></td><td><code>false</code></td><td>When <code>true</code>, disables all filtering — shows every item in the playlist.</td></tr>
                        <tr><td><code>multiplegridsearch</code></td><td>—</td><td>Filter items to those whose title contains this keyword.</td></tr>
                        <tr><td><code>multiplegridlimititems</code></td><td>—</td><td>Maximum number of items to show.</td></tr>
                        <tr><td><code>multiplegridepisoderange</code></td><td>—</td><td>Show only items in an episode range. Format: <code>1-10</code>. Overrides search and limit.</td></tr>
                        <tr><td><code>multiplegridgap</code></td><td><code>48px</code></td><td>CSS <code>gap</code> between grid items. In a carousel, the space between cards.</td></tr>
                        <tr><td><code>multiplegridminsize</code></td><td><code>400px</code></td><td>Minimum column width in the <code>auto-fill</code> grid. In a carousel, the minimum card width.</td></tr>
                        <tr><td><code>multiplegridtext</code></td><td>—</td><td>Show text below each grid item: <code>title</code>, <code>description</code>, <code>both</code>, <code>numberedtitle</code>, or <code>numberedtitleanddescription</code>. YouTube only. The <code>numberedtitle</code> options require the playlist's sort mode to be "Number in title": they display "Episode N" (or "Season S Episode N" when the season/episode regex is set), falling back to the plain title for items without a number.</td></tr>
                        <tr><td><code>multiplegridusersearch</code></td><td><code>false</code></td><td>Set to <code>true</code> to enable the user-searchable + paginated grid mode. See <a href="#maw-grid-search">Grid Search &amp; Pagination</a>.</td></tr>
                        <tr><td><code>multiplegridperpage</code></td><td><code>12</code></td><td>Max items per page when <code>multiplegridusersearch="true"</code>.</td></tr>
                        <tr><td><code>multiplegridmaxpages</code></td><td>—</td><td>Cap the maximum number of pagination pages when <code>multiplegridusersearch="true"</code>. Omit or leave empty for no limit.</td></tr>
                        <tr><td><code>multiplegridmaxpagedisplay</code></td><td>—</td><td>Cap how many numbered page buttons show at once (a sliding window centred on the current page). A clickable <code>&hellip;</code> on each side jumps one set (this many pages) so all pages stay reachable. Omit or leave empty to list every page number with no <code>&hellip;</code>.</td></tr>
                        <tr><td><code>noresults</code></td><td>—</td><td>Text shown when a user search yields no results (user-search grid only). Falls back to a generic message when empty.</td></tr>
                    </tbody>
                </table>

                <p><strong>Example — episodes 1–6, with title, tighter layout:</strong></p>
                <pre class="maw-code">[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegrid="true"
    multiplegridepisoderange="1-6"
    multiplegridtext="title"
    multiplegridgap="24px"
    multiplegridminsize="280px"
]</pre>

                <p><strong>Example — keyword search in grid:</strong></p>
                <pre class="maw-code">[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegrid="true"
    multiplegridsearch="season 2"
    multiplegridlimititems="12"
]</pre>
            </section>

            <!-- GRID SEARCH & PAGINATION -->
            <section id="maw-grid-search" class="maw-section">
                <h2>Grid Search &amp; Pagination</h2>
                <p>When <code>multiplegridusersearch="true"</code> is added to <code>[media-api-widget-render]</code>, the grid switches to an interactive mode with AJAX-powered live search and page-by-page navigation. A companion <code>[media-api-widget-grid-search]</code> shortcode provides the search bar.</p>

                <h3>How It Works</h3>
                <ul>
                    <li>The first page renders server-side — no AJAX on initial load.</li>
                    <li>Typing in the search bar fires a debounced (400 ms) AJAX request that filters and re-renders the grid.</li>
                    <li>The search bar dropdown lets the visitor search by <strong>Any</strong> (title or description), <strong>Title</strong>, or <strong>Description</strong>. Set <code>searchbyenabled="false"</code> to hide the dropdown — the search will always match across both fields.</li>
                    <li>Clicking <strong>Prev</strong>, a page number, or <strong>Next</strong> also fires an AJAX request.</li>
                    <li>A CSS class <code>loading</code> is applied to the wrapper element while any AJAX request is in flight, making the grid semi-transparent and non-interactive.</li>
                    <li>The search bar shortcode can be placed anywhere on the page — it does not need to be adjacent to the grid.</li>
                    <li>Use <code>noresults="..."</code> to set custom text that appears when a search returns zero items.</li>
                    <li>Use <code>multiplegridmaxpages="N"</code> to cap the pagination to at most <em>N</em> pages. Omit the attribute for no limit.</li>
                    <li>Use <code>multiplegridmaxpagedisplay="N"</code> to show at most <em>N</em> numbered page buttons at a time (a sliding window). A clickable <code>&hellip;</code> appears on each side that has more pages and jumps one set (<em>N</em> pages) in that direction, keeping every page reachable without crowding the bar. Pairs well with a large or uncapped <code>multiplegridmaxpages</code>.</li>
                </ul>

                <div class="maw-callout">
                    <strong>Note:</strong> <code>multiplegridusersearch</code> and <code>multiplegrid</code> are mutually exclusive. When user search is enabled, the standard static grid path is bypassed entirely. All other grid attributes (<code>multiplegridgap</code>, <code>multiplegridminsize</code>, <code>multiplegridtext</code>, <code>multiplegridepisoderange</code>, etc.) continue to work as filters and display options within this mode. Add <code>multiplegridlayout="carousel"</code> to show each result page as a carousel &mdash; see <a href="#maw-carousel-search">Searchable carousels</a>.
                </div>

                <h3>Full Example</h3>
                <pre class="maw-code">[media-api-widget-grid-search
    playlist_name="my_show"
    media_platform="youtube"
    placeholder="Search episodes..."
]

[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegridusersearch="true"
    multiplegridperpage="12"
    multiplegridtext="both"
    multiplegridgap="32px"
    multiplegridminsize="300px"
    noresults="No episodes matched your search."
]</pre>

                <h3>Generated HTML Structure</h3>
                <p>The wrapper element exposes data attributes used by the JavaScript to wire up the search bar and pagination. The <code>loading</code> class is toggled on this element during AJAX requests.</p>
                <pre class="maw-code">&lt;div class="maw-grid-search-wrapper"
     data-maw-grid-id="my_show_youtube"
     data-maw-playlist="my_show"
     data-maw-mediatype="youtube"
     data-maw-perpage="12"
     data-maw-grid-key="..."&gt;
  &lt;div class="maw-grid-items"&gt;...&lt;/div&gt;
  &lt;div class="maw-grid-pagination"&gt;...&lt;/div&gt;
&lt;/div&gt;</pre>
            </section>

            <!-- CAROUSEL -->
            <section id="maw-carousel" class="maw-section">
                <h2>Carousel Layout</h2>
                <p>A carousel shows the same media cards as a grid &mdash; same filters, same cards, same playback &mdash; in one horizontally scrolling row. It sizes its cards from the space it actually has, so the same carousel fits a full-width section, an Elementor column, or a phone. It works on any page; Elementor is not required.</p>

                <div class="maw-callout">
                    <strong>New in 6.1.0.</strong> The default layout is still the grid, so existing shortcodes and widgets look exactly as before until you choose the carousel.
                </div>
                <div class="maw-callout">
                    <strong>New in 6.1.1.</strong> Set how long each move takes with <code>carouseltransitionduration</code> (Elementor: <strong>Transition duration</strong>), including from your own CSS variables, and use SVG arrows that are placed in the page as SVG code &mdash; like the Loop Carousel's arrows &mdash; with <code>carouselarrowleftsvg</code> / <code>carouselarrowrightsvg</code> (Elementor: <strong>Left/Right arrow SVG &rarr; Upload SVG</strong>).
                </div>

                <h3>Turning It On</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:220px;">Where</th><th>How</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Shortcode</strong></td><td>Add <code>multiplegridlayout="carousel"</code> to a <code>[media-api-widget-render]</code> shortcode that has <code>multiplegrid="true"</code> or <code>multiplegridusersearch="true"</code>.</td></tr>
                        <tr><td><strong>Elementor &mdash; Classic and Atomic widgets</strong></td><td>Set <strong>Output</strong> to <em>Grid or carousel</em> or <em>Searchable grid or carousel with pagination</em>, then <strong>Grid &amp; Carousel &rarr; Layout</strong> to <em>Carousel</em>. Minimum item width and Gap are in the same section; arrow settings appear in <strong>Carousel Navigation</strong>. In the Classic widget arrow images come from the media library; in the Atomic widget, enter the image URL.</td></tr>
                    </tbody>
                </table>

                <h3>Attributes</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:240px;">Attribute (Elementor control)</th><th style="width:150px;">Default</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>multiplegridlayout</code><br>(Layout)</td><td><code>grid</code></td><td><code>carousel</code> enables the carousel.</td></tr>
                        <tr><td><code>multiplegridminsize</code><br>(Minimum item width)</td><td><code>400px</code></td><td>Cards never get narrower than this &mdash; unless only one card fits, which then fills the full width.</td></tr>
                        <tr><td><code>multiplegridgap</code><br>(Gap)</td><td><code>48px</code></td><td>Space between cards.</td></tr>
                        <tr><td><code>carouselstep</code><br>(Arrow step)</td><td><code>item</code></td><td><code>item</code> moves one card per arrow click; <code>group</code> moves as many cards as are visible.</td></tr>
                        <tr><td><code>carouseltransitionduration</code><br>(Transition duration)</td><td><code>0.25s</code></td><td>How long each arrow, keyboard or drag-release move takes: <code>0.6s</code>, <code>400ms</code>, a plain number of milliseconds, or <code>0</code> for instant moves. Also accepts a CSS variable such as <code>var(--slide-speed)</code> or <code>var(--slide-speed, 300ms)</code>, and <code>calc()</code> / <code>min()</code> / <code>max()</code> / <code>clamp()</code>.</td></tr>
                        <tr><td><code>carouselarrowleftsvg</code><br>(Left arrow SVG)</td><td><em>none</em></td><td>An SVG from the media library (its attachment ID or URL), placed in the page as SVG code so it takes the arrow color and icon size &mdash; unless the SVG sets its own colors. Takes precedence over the left arrow image.</td></tr>
                        <tr><td><code>carouselarrowrightsvg</code><br>(Right arrow SVG)</td><td><em>none</em></td><td>The same for the right arrow.</td></tr>
                        <tr><td><code>carouselarrowleftimgurl</code><br>(Left arrow image)</td><td><em>built-in arrow</em></td><td>Image for the left arrow, used when no left arrow SVG is set.</td></tr>
                        <tr><td><code>carouselarrowrightimgurl</code><br>(Right arrow image)</td><td><em>built-in arrow</em></td><td>Image for the right arrow. Each side falls back to the built-in arrow on its own.</td></tr>
                        <tr><td><code>carouselarrowsize</code><br>(Arrow button size)</td><td><code>48px</code></td><td>Width and height of each arrow button.</td></tr>
                        <tr><td><code>carouselarrowiconsize</code><br>(Arrow icon size)</td><td><code>24px</code></td><td>Size of the arrow icon or image inside the button.</td></tr>
                        <tr><td><code>carouselarrowcolor</code><br>(Arrow color)</td><td><code>#ffffff</code></td><td>Color of the built-in arrow.</td></tr>
                        <tr><td><code>carouselarrowbackground</code><br>(Arrow background)</td><td><code>rgba(0, 0, 0, 0.6)</code></td><td>Button background. Use <code>transparent</code> with custom arrow images.</td></tr>
                        <tr><td><code>carouselarrowoffset</code><br>(Arrow offset)</td><td><code>12px</code></td><td>Space between an arrow and the cards (or the inset from the edge when arrows overlay the cards).</td></tr>
                    </tbody>
                </table>
                <p class="description">Sizes accept <code>px</code>, <code>rem</code>, <code>em</code>, <code>%</code> (of the carousel's width), <code>vw</code> and other CSS units, a plain number (pixels), or <code>calc()</code> / <code>clamp()</code>. Times accept <code>s</code>, <code>ms</code>, a plain number (milliseconds), a CSS variable or <code>calc()</code>. Colors accept hex, <code>rgb()</code>/<code>rgba()</code>, color names, or a CSS variable such as an Elementor global color. An invalid value falls back to the default shown above. All other grid, search, card, lightbox and podcast attributes, <code>&#123;&#123;field&#125;&#125;</code> references, and same-named stored-field defaults work exactly as they do for a grid.</p>

                <h3>How Cards Are Sized</h3>
                <p>The carousel shows as many cards as fit at the minimum width, then shares the space evenly between them: <code>visible cards = floor((available width + gap) &divide; (minimum width + gap))</code>, at least one and never more than there are cards. With a <code>300px</code> minimum and a <code>24px</code> gap:</p>
                <table class="widefat striped maw-table maw-about-table" style="max-width:520px;">
                    <thead><tr><th>Available width</th><th>Cards shown</th></tr></thead>
                    <tbody>
                        <tr><td>1,000px</td><td>3</td></tr>
                        <tr><td>650px</td><td>2</td></tr>
                        <tr><td>600px</td><td>1, filling the full width</td></tr>
                    </tbody>
                </table>
                <p>It recalculates whenever its space changes &mdash; resizing the window, changing an Elementor column, opening a tab or accordion it sits in, or rotating a phone &mdash; and keeps the same first card in view where it can. Arrows sit in gutters beside the cards, vertically centered, while at least two cards still fit next to them; in narrower spaces they overlay the cards' edges so the cards keep the full width. When every card fits, the arrows are hidden. The carousel never makes the page scroll sideways.</p>

                <h3>Navigation &amp; Touch</h3>
                <ul>
                    <li><strong>Arrows</strong> move one card per click (or one visible group with <code>carouselstep="group"</code>), stop at the first and last positions, and disable themselves there. The last card always ends flush with the edge &mdash; no empty space after it.</li>
                    <li><strong>Phones and tablets:</strong> swipe sideways. Up/down swipes still scroll the page.</li>
                    <li><strong>Computers:</strong> scroll sideways with a trackpad or a horizontal mouse wheel, or <strong>click and drag</strong> the row with the mouse. Scrolling always settles on a card edge.</li>
                    <li>Swiping or dragging never opens a video or podcast. A normal click or tap on a card plays it, exactly as in a grid, and clicking an arrow never plays anything.</li>
                    <li><strong>Keyboard:</strong> the arrows are labelled buttons with a visible focus ring (Enter or Space). Focusing the row lets the arrow keys, Page Up/Down and Home/End move through it.</li>
                    <li>Visitors who have turned on their device's reduce-motion setting see moves happen instantly instead of animating, whatever the transition duration.</li>
                    <li>Several carousels on one page &mdash; even for the same playlist &mdash; scroll independently.</li>
                </ul>

                <h3>Transition Duration</h3>
                <p><code>carouseltransitionduration</code> sets how long arrow, keyboard and drag-release moves take (swipes and trackpads keep the device's own feel). It becomes the <code>--maw-carousel-transition-duration</code> CSS variable, which the browser resolves &mdash; so it can point at your own variable and change with your media queries:</p>
                <pre class="maw-code">carouseltransitionduration="var(--slide-speed, 300ms)"

:root { --slide-speed: 450ms; }
@media (max-width: 767px) { :root { --slide-speed: 250ms; } }</pre>
                <p class="description">A <code>var()</code> whose variable is not defined (and has no fallback) uses <code>0.25s</code>. A variable that holds something other than a time is invalid CSS, which browsers treat as an instant move.</p>

                <h3>Arrow SVGs</h3>
                <p>An arrow SVG is written into the page as SVG code, the way Elementor's Loop Carousel renders its arrow icons, so it takes the <strong>arrow color</strong> and <strong>arrow icon size</strong>. An SVG that sets its own fill colors keeps them, and an outline icon with <code>fill="none"</code> stays an outline.</p>
                <ul>
                    <li><strong>Elementor:</strong> in <strong>Carousel Navigation</strong>, set <strong>Left arrow SVG</strong> / <strong>Right arrow SVG</strong> to <em>Custom value</em>, then <strong>Upload SVG</strong> to upload a file or pick one from the media library. The first time, Elementor asks an administrator to enable <em>Unfiltered File Uploads</em>, as it does for the Loop Carousel &mdash; WordPress blocks SVG uploads otherwise.</li>
                    <li><strong>Shortcode:</strong> <code>carouselarrowleftsvg="123"</code> (the attachment ID, from <code>post.php?post=123</code> when editing the file) or the file's media-library URL.</li>
                    <li><strong>Safety:</strong> the SVG is rebuilt from an allowlist of drawing elements and attributes before it is placed in the page. Scripts, event handlers, links, <code>&lt;style&gt;</code> blocks and external references are removed; a file that is not a valid SVG, declares entities, or is over 256&nbsp;KB is not used. Only media-library SVGs are used &mdash; remote files are never fetched.</li>
                    <li>An SVG's own <code>&lt;style&gt;</code> block (common in Illustrator exports) is removed, so those shapes take the arrow color. Export with presentation attributes to keep several colors.</li>
                </ul>

                <h3 id="maw-carousel-search">Searchable Carousels</h3>
                <p>With <code>multiplegridusersearch="true"</code> (or the <em>Searchable grid or carousel with pagination</em> output), each page of results is a carousel.</p>
                <div class="maw-callout">
                    <strong>Arrows vs. pagination.</strong> The carousel arrows (and swiping) move through the cards of the <em>results page that is already loaded</em>. The pagination buttons below load a <em>different results page</em>, exactly as they do for a grid &mdash; same page links, URLs and back-button behavior. Each new search or page starts its carousel at the first card.
                </div>

                <h3>Plugin Styling Off (<code>nostyling</code>)</h3>
                <p><code>nostyling="true"</code> (Elementor: <strong>Plugin Styling &rarr; Disable plugin styling classes</strong>) removes the look: unstyled cards, and arrow buttons without the round dark background, colors or hover effect &mdash; so <code>carouselarrowcolor</code> and <code>carouselarrowbackground</code> do nothing. The carousel still scrolls, sizes its cards, places and sizes its arrows, disables them at the ends and shows focus rings, because it cannot work without that. Style the <code>maw-carousel-*</code> classes listed under <a href="#maw-styling-hooks">Styling Hooks</a> to design your own.</p>

                <h3>Good to Know</h3>
                <ul>
                    <li>Text below cards (<code>multiplegridtext</code>) is still YouTube-only, as in the grid.</li>
                    <li>The layout only affects collections; single cards, title/description text and the podcast player ignore it.</li>
                    <li>Hand-written legacy <code>data-playlistname</code> placeholders (rendered in the browser) have no carousel layout.</li>
                    <li>Media cards cannot be reached with the Tab key in either layout; keyboard users use the arrows and the row.</li>
                    <li>Card hover effects that grow outside a card (scaling, shadows) are clipped by the scrolling row.</li>
                    <li>In the Elementor editor, changing a setting redraws the carousel from its first card.</li>
                </ul>

                <h3>Examples</h3>
                <p><strong>Basic carousel:</strong></p>
                <pre class="maw-code">[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegrid="true"
    multiplegridlayout="carousel"
    multiplegridlimititems="12"
    multiplegridminsize="300px"
    multiplegridgap="24px"
    multiplegridtext="title"
]</pre>

                <p><strong>Custom arrows, one visible group per click:</strong></p>
                <pre class="maw-code">[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegrid="true"
    multiplegridlayout="carousel"
    carouselstep="group"
    carouselarrowleftimgurl="https://example.com/arrows/left.svg"
    carouselarrowrightimgurl="https://example.com/arrows/right.svg"
    carouselarrowbackground="transparent"
    carouselarrowsize="3.5rem"
    carouselarrowiconsize="2.5rem"
    carouselarrowoffset="0.5rem"
]</pre>

                <p><strong>Inline SVG arrows in the brand color, with slower moves:</strong></p>
                <pre class="maw-code">[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegrid="true"
    multiplegridlayout="carousel"
    carouselarrowleftsvg="123"
    carouselarrowrightsvg="124"
    carouselarrowcolor="var(--e-global-color-primary)"
    carouselarrowbackground="transparent"
    carouselarrowiconsize="2rem"
    carouseltransitionduration="0.6s"
]</pre>

                <p><strong>Searchable carousel with its search bar:</strong></p>
                <pre class="maw-code">[media-api-widget-grid-search playlist_name="my_show" media_platform="youtube" placeholder="Search episodes..."]

[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegridusersearch="true"
    multiplegridlayout="carousel"
    multiplegridperpage="9"
    multiplegridminsize="300px"
    multiplegridgap="24px"
    noresults="No episodes matched your search."
]</pre>
            </section>

            <!-- STORED PLAYLIST POSTS -->
            <section id="maw-stored-posts" class="maw-section">
                <h2>Stored Playlist Posts (Loop Grid &amp; Page Builders)</h2>
                <p>Optionally, per playlist, the plugin keeps every item of a playlist as a WordPress post of the <code>maw_media_item</code> type, synchronized with the playlist. Elementor Pro's <strong>Loop Grid</strong>, other page builders, <code>WP_Query</code> and the REST API can then list YouTube videos and podcast episodes like any other posts, with your own templates. The shortcodes, widgets and players do not use these posts and work exactly as before.</p>

                <div class="maw-callout">
                    <strong>New in 6.1.0, off by default.</strong> Nothing is stored, scheduled or added to the menu until a playlist turns it on.
                </div>

                <h3>Turning It On</h3>
                <ol>
                    <li>On <a href="<?php echo esc_url(menu_page_url(Menu::SLUG, false)); ?>">Playlist Settings</a>, check <strong>Store playlist items as WordPress posts</strong> on a playlist and click <strong>Save</strong>.</li>
                    <li>An initial population is queued. It runs in the background through WP-Cron, in batches, from the playlist data already stored &mdash; no extra API request. A status panel under the checkbox shows its progress.</li>
                    <li><strong>All Media Items</strong> and <strong>Media API - {playlist_name}</strong> appear in this menu.</li>
                </ol>
                <div class="maw-callout">
                    <strong>Storing a full playlist as WordPress posts can create many database records.</strong> Each item becomes one post with about fifteen custom fields. Initial imports and refreshes may take time. Synchronization runs in the background; changed items are updated in place and removed source items are deleted after a successful complete refresh. WP-Cron runs when the site receives traffic; on a low-traffic site, trigger <code>wp-cron.php</code> from a real server cron job.
                </div>

                <h3>Status, Actions and Admin Views</h3>
                <p>The status panel shows the state (<em>Pending</em>, <em>Running</em>, <em>Complete</em>, <em>Error</em>, <em>Off</em>), the number of stored posts, the last successful synchronization, the last result, and &mdash; when nothing was removed &mdash; why. Its actions require the <code>manage_options</code> capability and a nonce for that action and playlist.</p>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:200px;">Action</th><th>Effect</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Resync now</strong></td><td>Queues a full synchronization from the stored data, starting from the first item.</td></tr>
                        <tr><td><strong>View posts</strong></td><td>Opens <strong>Media API - {playlist_name}</strong>.</td></tr>
                        <tr><td><strong>Delete stored posts</strong></td><td>After a confirmation, permanently deletes this playlist's generated posts (up to 500 per click). While storage is on, the next synchronization recreates them.</td></tr>
                    </tbody>
                </table>
                <p><strong>Media API - {playlist_name}</strong> lists the playlist's posts in playlist order with Image, Season, Episode and Position columns and Playlist and Media type filters. <strong>View details</strong> opens a read-only screen with every stored field and the key builders read; the title and synchronized fields cannot be changed there. The post <strong>status</strong> stays editable: <strong>Draft</strong> hides an item from loops (synchronization never republishes it), and a trashed item stays in the Trash.</p>

                <h3>Stored Fields</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:230px;">Key</th><th style="width:140px;">Label</th><th>Value</th></tr></thead>
                    <tbody>
                        <tr><td><code>post_title</code></td><td>Title</td><td>The item title.</td></tr>
                        <tr><td><code>post_excerpt</code></td><td>Description</td><td>The description as plain text, line breaks kept. An empty podcast <code>&lt;description&gt;</code> falls back to <code>itunes:summary</code> or <code>content:encoded</code>.</td></tr>
                        <tr><td><code>maw_source_id</code></td><td>ID</td><td>The YouTube video ID, or the podcast episode ID (its GUID; for an episode without one, its audio URL, page URL, or a content hash).</td></tr>
                        <tr><td><code>maw_season</code>, <code>maw_episode</code></td><td>Season, Episode</td><td>Absent when they cannot be determined.</td></tr>
                        <tr><td><code>maw_playlist_position</code></td><td>Playlist position</td><td>0-based position in the playlist order; also the post's <code>menu_order</code>.</td></tr>
                        <tr><td><code>maw_playlist_name</code></td><td>Playlist</td><td>Also a term of the <code>maw_playlist</code> taxonomy.</td></tr>
                        <tr><td><code>maw_media_type</code></td><td>Media type</td><td><code>youtube</code> or <code>podcast</code>; also a term of the <code>maw_media_type</code> taxonomy.</td></tr>
                        <tr><td><code>maw_thumbnail_url</code></td><td>Image URL</td><td>The remote thumbnail. Nothing is imported into the Media Library.</td></tr>
                        <tr><td><code>maw_media_url</code></td><td>Media URL</td><td>The YouTube watch URL, or the episode page (its audio file when it has no page).</td></tr>
                        <tr><td><code>maw_audio_url</code></td><td>Audio URL</td><td>The episode's audio file. Absent for YouTube.</td></tr>
                        <tr><td><code>maw_youtube_id</code>, <code>maw_podcast_guid</code></td><td>Provider IDs</td><td>Each absent for the other media type.</td></tr>
                        <tr><td><code>maw_published_date</code></td><td>Published date</td><td>ISO 8601 when parseable.</td></tr>
                        <tr><td><code>maw_transcript_*</code>, <code>post_content</code></td><td>Transcript</td><td>Written only by integrations; never overwritten by a synchronization.</td></tr>
                        <tr><td><code>maw_last_seen</code></td><td>Last seen</td><td>Unix time of the last synchronization that saw the item.</td></tr>
                    </tbody>
                </table>
                <p>All are registered post meta, available to builders, <code>get_post_meta()</code>, and the REST API at <code>/wp-json/wp/v2/maw-media-items</code> (an unknown season or episode is <code>null</code> there). The <strong>Media API Field</strong> tag adds <code>episode_label</code> (<em>Season 2 Episode 5</em>), <code>position_number</code> (1-based) and <code>published_date_formatted</code>. Season and episode come from the playlist's settings: <em>Number in title</em> with its regex for YouTube, the <strong>Season/episode regex</strong> for podcasts.</p>
                <p><strong>Order.</strong> Posts are stored in playlist order. Front-end queries that only ask for the default date order &mdash; Elementor's Loop Grid default &mdash; list media items in that order; any explicit order (Title, Random, a custom field, <em>Date, ascending</em>) is used as chosen. Filter <code>media_api_widget_post_index_default_order</code> returns <code>false</code> to turn this off. Post Types Order's auto-sort still honors explicit orders, and its Re-Order page is not offered for media items because synchronization sets the order.</p>

                <h3>Elementor Pro Loop Grid</h3>
                <ol>
                    <li><strong>Loop item template:</strong> <em>Templates &rarr; Theme Builder &rarr; Loop Item</em> (or <em>Create Template</em> from a Loop Grid). Set <strong>Preview as</strong> to a <em>Media Item</em>.</li>
                    <li><strong>Bind widgets</strong> with the tags in the <strong>Media API</strong> group of the dynamic tags list (next to ACF):
                        <table class="widefat striped maw-table maw-about-table" style="margin-top:8px;">
                            <thead><tr><th style="width:190px;">Tag</th><th style="width:200px;">Controls</th><th>Shows</th></tr></thead>
                            <tbody>
                                <tr><td><strong>Media API Field</strong></td><td>Text, number, custom field</td><td>Any stored field from a list: Title, Description, ID, Season, Episode, the season &amp; episode label, dates, positions, playlist, URLs, IDs, transcript fields.</td></tr>
                                <tr><td><strong>Media API Image</strong></td><td>Image</td><td>The remote thumbnail, with an optional fallback image.</td></tr>
                                <tr><td><strong>Media API URL</strong></td><td>Link</td><td>Media, audio, image or transcript URL. Plain navigation.</td></tr>
                                <tr><td><strong>Media API Click Action</strong></td><td>Link</td><td>Makes the element act like the item's media card (below).</td></tr>
                            </tbody>
                        </table>
                        Elementor's own <em>Post Title</em>, <em>Post Excerpt</em> and <em>Post Custom Field</em> (custom key, e.g. <code>maw_season</code>) work too.</li>
                    <li><strong>Loop Grid:</strong> <strong>Query &rarr; Source</strong> <em>Media API Items</em>; for one playlist, <strong>Include By &rarr; Term</strong> <em>Playlists: {playlist_name}</em>; leave the order at its default for playlist order.</li>
                </ol>
                <p>The thumbnail is not a featured image, so use <strong>Media API Image</strong> rather than <em>Featured Image</em>. Media items have no front-end page, so link cards with <strong>Media API URL</strong> or <strong>Media API Click Action</strong>, not the <em>Post URL</em>.</p>

                <h3 id="maw-click-action">Media API Click Action</h3>
                <p>For any link control &mdash; Button, Image, Heading, Icon, a Container link &mdash; in a loop item. A click dispatches <a href="#maw-js-events"><code>mediaApiWidgetItemClick</code></a> with the same details as a card click, then:</p>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:230px;">On click</th><th>YouTube</th><th>Podcast</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Open lightbox (this item only)</strong> (default)</td><td>The lightbox plays that video.</td><td>The player opens on that episode, without the episode list.</td></tr>
                        <tr><td><strong>Open lightbox with the playlist</strong></td><td>Opens at that video with the playlist panel. Optional border color, font and logo URL.</td><td>The player opens on that episode with the episode list.</td></tr>
                        <tr><td><strong>Click event only (no lightbox)</strong></td><td colspan="2">Nothing else; <code>showLightbox</code> is <code>false</code>. For your own listener.</td></tr>
                    </tbody>
                </table>
                <p>The link is a <code>#maw-media-…</code> fragment that never navigates. <code>e.detail.element.dataset.postid</code> is the post ID. A video missing from the page's playlist data (for example beyond six items) plays on its own. Clicking in the Elementor editor selects the element and plays nothing. The playlist must still be configured for its player to open; otherwise only the event is dispatched. Podcasts open the playlist platform's player: <code>custom</code> feeds use <code>/podcast/player</code>, Omny and Buzzsprout open the clicked episode, and SoundCloud opens at its default track, as podcast cards do.</p>

                <h3>When Posts Are Updated and Deleted</h3>
                <p>Synchronization is queued whenever the playlist's data is stored (a refresh, a shortcode warm-up, an enrichment write) and runs through the <code>maw_sync_post_index</code> WP-Cron event: one queued event and one run at a time per playlist, 50 items per batch (filter <code>media_api_widget_post_index_batch_size</code>), retries after 5 minutes up to 5 times. Items are matched by YouTube video ID or podcast GUID within their playlist. Changed fields are rewritten in place, values that disappear are cleared, and post IDs never change; post content (transcripts), status, transcript fields and other custom fields are never touched. The <code>media_api_widget_post_index_synced</code> action fires after each run, for purging page caches.</p>
                <p>A post is deleted &mdash; permanently &mdash; only when a <strong>complete, validated refresh</strong> (every page fetched, or the whole feed parsed, and stored) no longer contains its item, once per refresh. A valid empty playlist removes every post. Nothing is removed after a failed, partial or malformed refresh, because of the six-item limit (every page is still fetched and recorded), after an expired cache, after an enrichment write, while an item has no usable identity, after a failed save, while a newer refresh is arriving, or on the first run after storage is turned on. Only posts the plugin generated for that playlist are ever removed.</p>

                <h3>Turning It Off and Retention</h3>
                <ul>
                    <li><strong>Unchecking</strong> the setting stops synchronization at once, including queued and in-progress batches, and keeps the posts (still published and visible to loops). Set them to Draft or use <strong>Delete stored posts</strong>. Turning it back on starts a fresh initial population.</li>
                    <li><strong>Removing a playlist</strong> does the same; its posts and view remain while posts exist.</li>
                    <li><strong>Deactivating the plugin</strong> leaves the posts in the database, unregistered and invisible; reactivating restores them.</li>
                </ul>

                <h3>Limitations</h3>
                <ul>
                    <li>Media items have no front-end single page, archive, or search results.</li>
                    <li>Images are remote URLs: no Media Library entries, featured images, or generated sizes.</li>
                    <li>Synchronization depends on WP-Cron. Imports of hundreds of items take several minutes of background batches.</li>
                    <li>Only YouTube and podcast playlists can store posts; embed-only podcasts have no items.</li>
                    <li>Synchronized fields cannot be edited in WordPress.</li>
                </ul>
            </section>

            <!-- PODCAST PLAYER -->
            <section id="maw-podcast-player" class="maw-section">
                <h2>Podcast Player</h2>
                <p>The plugin registers a standalone podcast player page at <code>/podcast/player</code>. It is a complete HTML document (not a WordPress template) that loads an RSS feed and renders a fully themed, interactive audio player with an episode list.</p>
                <p>The player is used in two ways:</p>
                <ol>
                    <li><strong><code>[media-api-podcast-player]</code></strong> — embeds the player as an <code>&lt;iframe&gt;</code> directly on a page.</li>
                    <li><strong><code>[media-api-widget-render]</code> with <code>podcast_platform="custom"</code></strong> — renders a thumbnail; clicking it opens the player in a lightbox iframe.</li>
                </ol>

                <div class="maw-callout">
                    <strong>Permalinks notice:</strong> If <code>/podcast/player</code> returns a 404 after activation, go to <strong>Settings → Permalinks</strong> and click <strong>Save Changes</strong> to flush rewrite rules.
                </div>

                <h3>Player URL Query Parameters</h3>
                <p>You can link directly to the player or build iframes manually using these parameters:</p>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:200px;">Parameter</th><th style="width:140px;">Default</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>url</code></td><td><em>required</em></td><td>The RSS feed URL to load.</td></tr>
                        <tr><td><code>track</code></td><td><code>1</code></td><td>1-based episode number to start playback on.</td></tr>
                        <tr><td><code>mode</code></td><td><code>dark</code></td><td>Background: <code>dark</code>, <code>light</code>, or a hex value without <code>#</code> (e.g. <code>151515</code>).</td></tr>
                        <tr><td><code>buttoncolor</code></td><td><code>ffffff</code></td><td>Play button icon color (hex without <code>#</code>).</td></tr>
                        <tr><td><code>color1</code></td><td><code>bbbbbb</code></td><td>Accent / UI color (hex without <code>#</code>).</td></tr>
                        <tr><td><code>progressbarcolor</code></td><td><code>616161</code></td><td>Progress bar fill color (hex without <code>#</code>).</td></tr>
                        <tr><td><code>highlightcolor</code></td><td><code>888888</code></td><td>Selected episode highlight (hex without <code>#</code>).</td></tr>
                        <tr><td><code>scrollcolor</code></td><td><code>bbbbbb</code></td><td>Scrollbar color (hex without <code>#</code>).</td></tr>
                        <tr><td><code>textcolor</code></td><td><code>ffffff</code></td><td>Text color (hex without <code>#</code>).</td></tr>
                        <tr><td><code>font</code></td><td><code>Poppins</code></td><td>Google Fonts font name.</td></tr>
                        <tr><td><code>adddatetotitle</code></td><td>—</td><td>Set to <code>true</code> to append the publish date to each episode title.</td></tr>
                        <tr><td><code>singleepisode</code></td><td>—</td><td>A GUID string. If provided, only that episode is shown — the full episode list is hidden.</td></tr>
                        <tr><td><code>startepisode</code></td><td>—</td><td>A GUID string. Starts on that episode and keeps the episode list. Ignored when <code>singleepisode</code> is set.</td></tr>
                    </tbody>
                </table>

                <p><strong>Direct URL example:</strong></p>
                <pre class="maw-code">https://yoursite.com/podcast/player?url=https://feeds.example.com/podcast.xml&track=1&mode=dark&font=Roboto&textcolor=ffffff</pre>

                <p><strong>Manual iframe example:</strong></p>
                <pre class="maw-code">&lt;iframe
    style="width: 100%; min-height: 32.5rem; display: block;"
    src="https://yoursite.com/podcast/player?url=https://feeds.example.com/podcast.xml&mode=151515&textcolor=ffffff&font=Poppins"
    loading="lazy"
    title="Podcast player"&gt;
&lt;/iframe&gt;</pre>
            </section>

            <!-- SEO -->
            <section id="maw-seo" class="maw-section">
                <h2>SEO Meta Tags</h2>
                <p>When a page contains a <code>[media-api-widget-render]</code> or <code>[media-api-podcast-player]</code> shortcode, the plugin automatically injects <strong>Open Graph</strong> and <strong>Twitter Card</strong> meta tags into <code>&lt;head&gt;</code> based on the media content selected by the shortcode.</p>
                <p>The plugin respects the same <code>episodenumber</code>, <code>nameselect</code>, and <code>orderdescending</code> attributes to identify which item's data should populate the meta tags.</p>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Tag</th><th>Source</th></tr></thead>
                    <tbody>
                        <tr><td><code>description</code></td><td>Episode description → series description → fallback sentence.</td></tr>
                        <tr><td><code>og:type</code></td><td><code>video.other</code> for YouTube, <code>article</code> for podcasts.</td></tr>
                        <tr><td><code>og:title</code> / <code>twitter:title</code></td><td>Episode title | Series title | Site name.</td></tr>
                        <tr><td><code>og:image</code> / <code>twitter:image</code></td><td>Episode thumbnail or podcast cover art.</td></tr>
                        <tr><td><code>article:published_time</code></td><td>Episode publish date (ISO 8601).</td></tr>
                    </tbody>
                </table>
                <p class="description">Tags are only output when cached media data is available. No external request is made at tag-output time — the cache is read in-place.</p>
            </section>

            <!-- JAVASCRIPT EVENTS -->
            <section id="maw-js-events" class="maw-section">
                <h2>JavaScript Events</h2>
                <p>Every time a YouTube, Vimeo, or podcast thumbnail is clicked — regardless of the <code>showlightbox</code> attribute — the plugin dispatches a <code>mediaApiWidgetItemClick</code> <code>CustomEvent</code> on <code>document</code>. External scripts on the page can listen for this event to react to media interactions without touching the plugin code.</p>

                <div class="maw-callout">
                    <strong>Fires on every click.</strong> The event fires even when <code>showlightbox="false"</code> is set and no lightbox opens, making it the correct hook for custom modals, analytics, or any other side effect you want to trigger on media item click.
                </div>

                <p>Elements linked with the Elementor <a href="#maw-click-action">Media API Click Action</a> tag dispatch the same event. A listener that opens its own player should check <code>showLightbox</code>, as in the last example below; otherwise it opens on top of the plugin's lightbox for every card that has one.</p>

                <h3>Listening for the Event</h3>
                <pre class="maw-code">document.addEventListener("mediaApiWidgetItemClick", (e) =&gt; {
    const { playlistName, mediaType, embedUrl, itemId, showLightbox, element } = e.detail;
});</pre>

                <h3><code>event.detail</code> Properties</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:160px;">Property</th><th style="width:120px;">Type</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>playlistName</code></td><td><code>string</code></td><td>The <code>playlist_name</code> slug of the item's playlist.</td></tr>
                        <tr><td><code>mediaType</code></td><td><code>string</code></td><td>The media platform: <code>"youtube"</code>, <code>"podcast"</code>, or <code>"vimeo"</code>.</td></tr>
                        <tr><td><code>embedUrl</code></td><td><code>string | null</code></td><td>The iframe <code>src</code> URL the lightbox would (or does) load. For YouTube: <code>https://www.youtube.com/embed/{id}</code>. For podcasts: the platform-specific embed URL. <code>null</code> if the URL cannot be determined.</td></tr>
                        <tr><td><code>itemId</code></td><td><code>string</code></td><td>YouTube video ID or podcast episode GUID.</td></tr>
                        <tr><td><code>showLightbox</code></td><td><code>boolean</code></td><td><code>true</code> when the lightbox is opening; <code>false</code> when <code>showlightbox="false"</code> is set on the shortcode.</td></tr>
                        <tr><td><code>element</code></td><td><code>Element</code></td><td>The <code>&lt;a&gt;</code> DOM node that was clicked. For a <a href="#maw-click-action">Media API Click Action</a> link, <code>element.dataset.postid</code> is the stored post's ID.</td></tr>
                    </tbody>
                </table>

                <h3>Example — Push to a GTM / Analytics Data Layer</h3>
                <pre class="maw-code">document.addEventListener("mediaApiWidgetItemClick", (e) =&gt; {
    const { playlistName, mediaType, itemId, showLightbox } = e.detail;
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
        event: "media_item_click",
        playlist: playlistName,
        media_type: mediaType,
        item_id: itemId,
        opened_lightbox: showLightbox
    });
});</pre>

                <h3>Example — Open a Custom Modal Instead of the Built-in Lightbox</h3>
                <p>Set <code>showlightbox="false"</code> on the shortcode, then handle the click entirely in your own code:</p>
                <pre class="maw-code">// Shortcode: [media-api-widget-render playlist_name="my_show" media_platform="youtube" orderdescending="1" showlightbox="false"]

document.addEventListener("mediaApiWidgetItemClick", (e) =&gt; {
    if (!e.detail.showLightbox) {
        // The plugin will not open a lightbox — open your own instead
        openMyCustomModal(e.detail.embedUrl, e.detail.playlistName);
    }
});</pre>
            </section>

            <!-- CACHING -->
            <section id="maw-caching" class="maw-section">
                <h2>Caching</h2>
                <p>The plugin uses a three-layer cache to minimize external API calls and handle outages gracefully:</p>
                <ol>
                    <li><strong>WordPress Transients</strong> — server-side, keyed as <code>{type}_{playlist_name}</code>. The primary cache; TTL is configurable in <a href="<?php echo esc_url(menu_page_url(Menu::CACHING_SLUG, false)); ?>">Caching settings</a>.</li>
                    <li><strong>Backup JSON Files</strong> — written to <code>wp-content/uploads/media-api-widget/backups/</code>. Used when the transient has expired and the backup window has not yet elapsed, or as a fallback when a fresh API call fails.</li>
                    <li><strong>Browser localStorage</strong> — the parsed playlist data is pushed into <code>localStorage</code> via an inline <code>&lt;script&gt;</code> on every page load where the server cookie has expired. The front-end JS reads this on every subsequent page load without a round trip.</li>
                </ol>

                <h3>Cache Settings</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Setting</th><th>Default</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td>Media cache transient TTL</td><td>7,200 s (2 hrs)</td><td>How long server transients and the browser cookie are valid.</td></tr>
                        <tr><td>YouTube request-in-progress TTL</td><td>600 s (10 min)</td><td>How long the per-playlist refresh lock stays valid before an abandoned one can be reclaimed.</td></tr>
                        <tr><td>YouTube error TTL</td><td>600 s (10 min)</td><td>After a failed YouTube call, blocks a retry for this duration.</td></tr>
                        <tr><td>YouTube backup window</td><td>7,200 s (2 hrs)</td><td>If the last successful fetch was within this window, the backup JSON is served rather than re-calling the API.</td></tr>
                        <tr><td>YouTube maximum pages per refresh</td><td>20 pages</td><td>Hard ceiling on <code>playlistItems</code> requests during one refresh. Allowed 1&ndash;100.</td></tr>
                        <tr><td>YouTube daily call limit</td><td>200 calls</td><td>Circuit breaker on total outbound YouTube requests per quota day. Allowed 1&ndash;10,000.</td></tr>
                    </tbody>
                </table>

                <div class="maw-callout">
                    <strong>YouTube API Quota:</strong> The YouTube Data API allows 10,000 units/day. Each page of 50 playlist items costs 1 unit. A high cache TTL means fewer quota calls. You can clear the cache manually from the <a href="<?php echo esc_url(menu_page_url(Menu::SLUG, false)); ?>">Settings page</a> using the <em>Clear plugin cache</em> button.
                </div>

                <h3>Backup Files &amp; Downloads</h3>
                <p>Each backup is a single JSON file named <code>{playlist_name}_{media_type}_backup_data.json</code> holding the stored playlist plus the timestamp it was written. The <strong>By Playlist and Endpoint</strong> table on the <a href="<?php echo esc_url(menu_page_url(Menu::STATS_SLUG, false)); ?>">API Stats page</a> reports on that file for every playlist it logged a call for:</p>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Column</th><th>Shows</th></tr></thead>
                    <tbody>
                        <tr><td>Last Successful Backup</td><td>When the backup was last stored successfully, in your site timezone. Reads the timestamp inside the file, falling back to the file's modification time for backups written before that timestamp was recorded. Reads <em>No backup stored</em> when no file exists yet.</td></tr>
                        <tr><td>Backup File</td><td>A <strong>Download</strong> link that streams the JSON file straight to your browser, with its size beside it. Shows an em dash when there is nothing to download.</td></tr>
                    </tbody>
                </table>
                <p>A backup is written only after a refresh completes every requested page, so the timestamp is the last <em>successful</em> store &mdash; not the last attempt. If a refresh fails part-way, the previous good backup and its timestamp are left exactly as they were, which is why an old timestamp next to a row full of errors is the expected, healthy result. A YouTube playlist has a backup once any refresh has succeeded. As of 5.0.0 so does every podcast platform that fetches an RSS feed &mdash; direct feeds and Apple-lookup platforms alike, including caches warmed by a shortcode. Only embed platforms have no backup file, because they make no API call and store just the embed URL.</p>

                <div class="maw-callout">
                    <strong>Downloads are gated, and read-only:</strong> the link goes through <code>admin-post.php</code> rather than a public uploads URL, so every request is checked for the <code>manage_options</code> capability and a valid nonce, and the path is resolved and confirmed to sit inside the backups directory before any bytes are sent. Nothing on the Stats page can write, replace, or delete a backup file.
                </div>

                <h3>Runaway Protection</h3>
                <p>Two independent guards bound how many YouTube requests a single refresh, and a single day, can produce. Both are configured on the <a href="<?php echo esc_url(menu_page_url(Menu::CACHING_SLUG, false)); ?>">Caching page</a>, which also shows read-only status: calls used today, the quota reset date and timezone, and the most recent guard event.</p>

                <h4>Safe pagination</h4>
                <p>Pagination is driven by YouTube's <code>nextPageToken</code>, never by <code>pageInfo.totalResults</code> &mdash; for <code>playlistItems</code> that total counts deleted and private videos the API will not return, so a loop that waits for the item tally to reach it may never finish. The first request counts as page 1, and every token used is remembered.</p>
                <p>That same gap means a playlist can end on a page with no items at all: one reporting 67 total entries returned 50 items, then 11, then an empty page whose <code>nextPageToken</code> was the very token used to request it. An empty page reached <em>after</em> items have been collected is treated as the successful end of pagination &mdash; the collected items are stored normally and the repeated token is never followed. Otherwise the refresh is abandoned when any of the following happens:</p>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Reason code</th><th>Meaning</th></tr></thead>
                    <tbody>
                        <tr><td><code>repeated_page_token</code></td><td>A nonempty page offered a token that was already used, so pagination would have looped.</td></tr>
                        <tr><td><code>empty_page_with_next_token</code></td><td>The first page returned no items at all yet still supplied another token.</td></tr>
                        <tr><td><code>malformed_response</code></td><td>The body was not valid JSON, or was missing <code>items</code> / <code>pageInfo</code>.</td></tr>
                        <tr><td><code>maximum_pages_reached</code></td><td>The configured page ceiling was reached.</td></tr>
                        <tr><td><code>daily_limit_reached</code></td><td>The daily call budget is spent; nothing was sent.</td></tr>
                        <tr><td><code>concurrent_refresh</code></td><td>Another worker already held this playlist's refresh lock.</td></tr>
                        <tr><td><code>http_error</code></td><td>A connection failure or a non-200 status on any page.</td></tr>
                        <tr><td><code>store_rejected</code></td><td>A callback refused the fetched data, or it could not be stored. See <a href="#maw-dev-hooks">Developer Hooks</a>.</td></tr>
                    </tbody>
                </table>

                <div class="maw-callout">
                    <strong>Partial data is never promoted:</strong> the backup JSON file, the transient, and the last-successful-fetch timestamp are replaced only after <em>every</em> requested page completed normally. A refresh that aborts part-way leaves all previously stored data untouched and falls back to it, so a failure can never overwrite a good playlist with a truncated one. Guard diagnostics record only a reason code, playlist slug, page count, and timestamp &mdash; never the API key, request URL, response body, or headers.
                </div>

                <h4>Daily circuit breaker</h4>
                <p>Every attempted outbound YouTube <code>playlistItems</code> request is counted before it is sent, including requests that come back as errors, because a failed call can still consume quota. Once the limit is reached no request is sent at all and cached or backup data is served instead. The counter resets at midnight <code>America/Los_Angeles</code>, matching YouTube's own quota day across daylight-saving changes. Podcast, Apple/iTunes, RSS, and plugin-update requests are not counted.</p>

                <h4>Concurrency</h4>
                <p>Simultaneous cache misses for the same playlist no longer all start fetching. A refresh takes an atomic per-playlist lock (a <code>maw_yt_lock_{playlist_name}</code> row created by a bare insert, so the database's unique-name constraint does the arbitration) carrying an owner token and expiry. Only the owner can release it, the release runs on every exit path including thrown exceptions, and a lock left behind by a worker that died mid-refresh is reclaimed once it expires &mdash; so a stale lock can never block refreshes permanently.</p>
                <p>A second, separate lock (<code>maw_media_data_lock_{media_type}_{playlist_name}</code>) is held around the moment data is written, by every writer: refreshes, shortcode cache warm-ups, and the developer updater function. It keeps a refresh and an enrichment write from interleaving, so the transient and the backup file can never end up disagreeing.</p>
            </section>

            <!-- DEVELOPER HOOKS -->
            <section id="maw-dev-hooks" class="maw-section">
                <h2>Developer Hooks / Data Enrichment</h2>
                <p>Two PHP hooks and two global functions let other code alter the playlist data this plugin stores. They exist for asynchronous enrichment &mdash; attaching transcript status, custom metadata, or anything else derived from an episode &mdash; without forking the plugin or re-fetching from the API. All four are stable public API; the classes behind them are internal.</p>

                <h3>Filter &mdash; <code>media_api_widget_data_before_store</code></h3>
                <p>Alters successfully fetched and parsed data immediately before it is written.</p>
                <pre class="maw-code">$data = apply_filters(
    'media_api_widget_data_before_store',
    $data,
    $context
);</pre>
                <p><strong>Fires</strong> exactly once per complete, successful remote refresh. For a paginated YouTube playlist it fires once for the whole playlist after every page has completed &mdash; <em>not</em> once per page.</p>
                <p><strong>Never fires</strong> for a transient cache hit, a backup file read, a partial refresh (page ceiling, repeated page token, daily limit), a malformed response, an HTTP failure, a request blocked by the concurrency lock, an RSS feed that fails to fetch or parse, an Apple/iTunes lookup whose RSS fetch then fails, or an embed-only podcast (which makes no API call).</p>
                <p>The value you return is exactly what gets stored <em>and</em> what the current page render uses, so the response and storage can never disagree.</p>

                <div class="maw-callout">
                    <strong>Return values are validated.</strong> Returning a <code>WP_Error</code>, a value that does not match the documented shape for the media type, or a value that cannot be JSON encoded makes the refresh count as <em>failed</em>: nothing is written, the previous transient and backup file are left byte-for-byte intact, and a <code>store_rejected</code> guard event is recorded above. A callback that throws is contained the same way rather than breaking the page.
                </div>

                <h3>Action &mdash; <code>media_api_widget_data_stored</code></h3>
                <p>Fires after data has been written successfully. It does not fire for a rejected store.</p>
                <pre class="maw-code">do_action(
    'media_api_widget_data_stored',
    $data,
    $context
);</pre>

                <div class="maw-callout">
                    <strong>Callbacks run synchronously</strong> inside the request that performed the refresh &mdash; for the front-end pipeline, that is inside <code>wp_head</code> on a visitor's page view. Never call a transcription API, or anything else slow, directly from a callback. Enqueue a WP-Cron event or an Action Scheduler job instead. The action is post-commit: the data is already stored, so a callback that throws is logged and discarded and cannot undo the store.
                </div>

                <h3>The <code>$context</code> Array (both hooks)</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:190px;">Key</th><th style="width:130px;">Type</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>playlist_name</code></td><td><code>string</code></td><td>The <code>playlist_name</code> slug of the media item.</td></tr>
                        <tr><td><code>media_type</code></td><td><code>string</code></td><td><code>youtube</code> or <code>podcast</code>.</td></tr>
                        <tr><td><code>source</code></td><td><code>string</code></td><td><code>remote_refresh</code> (the front-end pipeline), <code>shortcode_warmup</code> (a shortcode rendered before the cache was warm), or <code>manual_update</code> (a write from the updater function below).</td></tr>
                        <tr><td><code>podcast_platform</code></td><td><code>string | null</code></td><td>The podcast platform slug. <code>null</code> for YouTube.</td></tr>
                    </tbody>
                </table>
                <p class="description">No API key, credential, request URL, or response body is ever placed in the context.</p>

                <h3>Data Shapes</h3>
                <p><strong>YouTube</strong> &mdash; an indexed list of media item arrays, each with at least <code>title</code>, <code>id</code> (the video ID), <code>episode</code>, <code>thumbnail</code>, <code>publishedDate</code>, and <code>description</code>. An empty list is valid.</p>
                <p><strong>Podcast</strong> &mdash; the parsed RSS feed normalized to a plain nested array, so callbacks can use ordinary array syntax:</p>
                <pre class="maw-code">[
    'channel' =&gt; [
        'title'             =&gt; 'My Show',
        'rssUrl'            =&gt; 'https://example.com/feed.xml',
        'collectionViewUrl' =&gt; 'https://podcasts.apple.com/&hellip;',
        'item'              =&gt; [
            ['title' =&gt; '&hellip;', 'description' =&gt; '&hellip;', 'guid' =&gt; '&hellip;', 'pubDate' =&gt; '&hellip;'],
            &hellip;
        ],
    ],
]</pre>
                <p><code>channel.item</code> is an associative array rather than a list for a single-episode feed, and may be absent for a feed with no episodes &mdash; handle both. Custom keys you add to individual items are preserved; only the container shape is validated. Storage formats are unchanged: the YouTube transient holds a PHP array, the podcast transient a JSON string.</p>

                <h3>Example &mdash; Mark Every Item Pending as It Is Stored</h3>
                <pre class="maw-code">add_filter(
    'media_api_widget_data_before_store',
    static function ($data, array $context) {
        if (
            $context['media_type'] !== 'youtube'
            || $context['playlist_name'] !== 'my_show'
        ) {
            return $data;
        }

        foreach ($data as &amp;$item) {
            $item['transcript_status'] = 'pending';
        }
        unset($item);

        return $data;
    },
    10,
    2
);</pre>

                <h3>Updater &mdash; <code>media_api_widget_update_stored_data()</code></h3>
                <p>Alters data that has <em>already</em> been stored, without making any API request. Returns the updated data, or a <code>WP_Error</code> on failure.</p>
                <pre class="maw-code">$result = media_api_widget_update_stored_data(
    'my_show',
    'youtube',
    static function ($data, array $context) {
        foreach ($data as &amp;$item) {
            if (($item['id'] ?? '') === 'YOUTUBE_VIDEO_ID') {
                $item['transcript_status'] = 'complete';
                $item['transcript_url'] = 'https://example.com/transcripts/YOUTUBE_VIDEO_ID';
            }
        }
        unset($item);

        return $data;
    }
);

if (is_wp_error($result)) {
    // Nothing was written; the previous good data is intact.
}</pre>
                <p>Reads the current data from the transient, falling back to the backup file. Runs your callback while holding the playlist's storage lock, so a refresh cannot land between the read and the write, and releases that lock on success, on error, and on a thrown exception. Writes the result to both the transient and the backup file using the configured cache TTL, replacing the backup by an atomic rename. Validates your return value exactly as the filter's is validated, retaining the old data on any failure. Fires <code>media_api_widget_data_stored</code> with a <code>source</code> of <code>manual_update</code>, and deliberately does <em>not</em> fire the pre-store filter, so an enrichment write cannot loop back into itself.</p>
                <p>To read stored data without changing it, use <code>media_api_widget_get_stored_data($playlistName, $mediaType)</code>. It makes no external request and returns <code>null</code> when nothing is stored.</p>

                <h3>Recommended Asynchronous Transcription Workflow</h3>
                <p>This plugin does not integrate with any transcription provider and adds no third-party dependency. The supported pattern is:</p>
                <ol>
                    <li>Hook <code>media_api_widget_data_stored</code>.</li>
                    <li><strong>Ignore events whose <code>source</code> is not <code>remote_refresh</code> or <code>shortcode_warmup</code>.</strong> This is what prevents a loop &mdash; your own writes arrive as <code>manual_update</code>, and acting on them would trigger another write.</li>
                    <li>Enqueue a WP-Cron event or Action Scheduler job. Do not call the provider from the callback.</li>
                    <li>Identify YouTube episodes by video ID (<code>$item['id']</code>) and podcast episodes by GUID (<code>$item['guid']</code>).</li>
                    <li>Call the transcription service asynchronously from that background job.</li>
                    <li>When the result is ready, write it back with <code>media_api_widget_update_stored_data()</code>.</li>
                </ol>
                <pre class="maw-code">add_action(
    'media_api_widget_data_stored',
    static function ($data, array $context): void {
        // Only react to fresh remote data; skip our own manual updates.
        if (!in_array($context['source'], ['remote_refresh', 'shortcode_warmup'], true)) {
            return;
        }

        wp_schedule_single_event(
            time() + 60,
            'my_plugin_queue_transcripts',
            [$context['playlist_name'], $context['media_type']]
        );
    },
    10,
    2
);</pre>
                <p>A remote refresh <em>replaces</em> the stored payload with whatever the API returned, so fields a manual update added are not present in the next refresh's data. The storage lock keeps the two writers from interleaving; it does not merge them. Re-apply derived fields in the pre-store filter, which runs on every refresh, and keep the authoritative record in your own storage.</p>

                <div class="maw-callout">
                    <strong>Do not store full transcripts in the playlist payload.</strong> Transcripts can be very large, and this payload is not a private server-side cache: the plugin embeds playlist data into page output and copies it into the browser's <code>localStorage</code>. A few hundred kilobytes of transcript per episode becomes a few hundred kilobytes on every page load, for every visitor. Store transcripts separately &mdash; a custom table, a custom post type, post meta, or object storage &mdash; and attach only a small reference to the item: a status, a short excerpt, a database key, a REST URL, or a transcript URL.
                </div>

                <div class="maw-callout">
                    <strong>Browser caches propagate on their own schedule.</strong> A server-side update changes the transient and the backup file; it cannot reach into a visitor's browser. Clients that already hold a payload in <code>localStorage</code> keep serving it until the normal media cache and cookie cycle expires, governed by the <strong>Media cache transient</strong> setting on the <a href="<?php echo esc_url(menu_page_url(Menu::CACHING_SLUG, false)); ?>">Caching page</a>. Nothing in this plugin &mdash; or in WordPress &mdash; can delete cookies or <code>localStorage</code> from every visitor's browser from the server. Expect enrichment to appear progressively as clients refresh, not instantly.
                </div>
            </section>

        </div>

        <style>
        .maw-about { max-width: 1100px; }
        .maw-about-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 16px 0 28px;
            padding: 14px 16px;
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 4px;
        }
        .maw-about-nav a {
            font-size: 13px;
            padding: 4px 10px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            text-decoration: none;
            color: #2271b1;
        }
        .maw-about-nav a:hover { background: #2271b1; color: #fff; border-color: #2271b1; }
        .maw-section { margin-bottom: 48px; }
        .maw-section h2 {
            font-size: 1.4em;
            margin-top: 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #2271b1;
        }
        .maw-section h3 { font-size: 1.1em; margin-top: 24px; margin-bottom: 8px; }
        .maw-section h4 { font-size: 1em; margin-top: 20px; margin-bottom: 6px; }
        .maw-section ul { list-style: disc; margin-left: 20px; }
        .maw-about-table th { font-weight: 600; white-space: nowrap; }
        .maw-about-table td code,
        .maw-about-table th code {
            background: #f0f0f1;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 12px;
        }
        .maw-code {
            background: #1e1e2e;
            color: #cdd6f4;
            padding: 14px 16px;
            border-radius: 4px;
            font-size: 12.5px;
            line-height: 1.7;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
            margin: 8px 0 16px;
            font-family: 'Courier New', Courier, monospace;
        }
        .maw-callout {
            background: #eaf3fb;
            border-left: 4px solid #2271b1;
            padding: 12px 16px;
            border-radius: 0 4px 4px 0;
            margin: 12px 0 20px;
            font-size: 13px;
        }
        .maw-callout strong { display: block; margin-bottom: 4px; }
        </style>
        <?php
    }
}
