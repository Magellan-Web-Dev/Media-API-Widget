<?php
namespace MediaApiWidget\Admin;

if (!defined('ABSPATH')) { exit; }

/**
 * Renders the About / Help documentation admin sub-page.
 *
 * Provides an in-admin reference guide covering every feature of the
 * Media API Widget plugin: admin setup, shortcode fields, all four
 * shortcode tags with copy-paste examples, the full attribute reference,
 * grid mode, the podcast player, SEO meta tags, and caching architecture.
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
                <a href="#maw-grid">Grid Mode</a>
                <a href="#maw-podcast-player">Podcast Player</a>
                <a href="#maw-seo">SEO</a>
                <a href="#maw-caching">Caching</a>
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
                        <tr><td><strong>Global Shortcode Fields</strong></td><td>Store key/value pairs in the admin and reference them dynamically in any shortcode attribute using <code>&#123;&#123;field_name&#125;&#125;</code> syntax.</td></tr>
                        <tr><td><strong>SEO Meta Tags</strong></td><td>Automatically generates Open Graph and Twitter Card tags based on the media content found on each page.</td></tr>
                        <tr><td><strong>Layered Cache</strong></td><td>Combines WordPress transients, local backup JSON files, and browser localStorage to minimize API calls and handle outages gracefully.</td></tr>
                        <tr><td><strong>API Stats</strong></td><td>Every external API call is logged to the database with 24-hour reporting in the admin.</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- ADMIN SETUP -->
            <section id="maw-admin-setup" class="maw-section">
                <h2>Admin Setup — Media Items</h2>
                <p>Go to <strong>Media API → Settings</strong> to define your YouTube playlists and podcast feeds. Each <em>media item</em> gets a unique <strong>Name (playlist_name)</strong> — a lowercase slug you will reference in shortcodes.</p>

                <h3>YouTube</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Field</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Name (playlist_name)</strong></td><td>A unique slug, e.g. <code>my_show</code>. Used in shortcodes as <code>playlist_name="my_show"</code>.</td></tr>
                        <tr><td><strong>Playlist ID</strong></td><td>The YouTube playlist ID — the <code>list=</code> value from the playlist URL.</td></tr>
                        <tr><td><strong>API Key</strong></td><td>Your Google/YouTube Data API v3 key from Google Cloud Console.</td></tr>
                        <tr><td><strong>Sort Mode</strong></td><td><em>Normal</em> — preserves YouTube order. <em>Number in title</em> — extracts the leading number from each video title and sorts descending. Ideal for TV episodes numbered in their title.</td></tr>
                        <tr><td><strong>Load full playlist</strong></td><td>When checked, fetches all videos (paginating past the 50-item API limit). When unchecked, limits to the first 6.</td></tr>
                    </tbody>
                </table>

                <h3>Podcast</h3>
                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th>Field</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Name (playlist_name)</strong></td><td>A unique slug, e.g. <code>my_podcast</code>.</td></tr>
                        <tr><td><strong>Platform</strong></td><td>See the Podcast Platform table below.</td></tr>
                        <tr><td><strong>RSS URL / ID / Embed URL</strong></td><td>Depends on the platform chosen — RSS URL, Apple numeric ID, or an embed player URL.</td></tr>
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
                <p>These eight fields are created on activation. Their <strong>field names are locked</strong> (cannot be renamed or deleted), but you can change their values freely. They drive the default styling of the podcast player across the entire site.</p>
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
                <p>The plugin registers four shortcode tags:</p>

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

            <!-- GRID MODE -->
            <section id="maw-grid" class="maw-section">
                <h2>Grid Mode</h2>
                <p>Set <code>multiplegrid="true"</code> on <code>[media-api-widget-render]</code> to render all (or a filtered subset of) playlist items in a responsive CSS grid layout. Each item is individually clickable.</p>

                <table class="widefat striped maw-table maw-about-table">
                    <thead><tr><th style="width:240px;">Attribute</th><th style="width:110px;">Default</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>multiplegrid</code></td><td><code>false</code></td><td>Set to <code>true</code> to enable grid mode.</td></tr>
                        <tr><td><code>multiplegridshowall</code></td><td><code>false</code></td><td>When <code>true</code>, disables all filtering — shows every item in the playlist.</td></tr>
                        <tr><td><code>multiplegridsearch</code></td><td>—</td><td>Filter items to those whose title contains this keyword.</td></tr>
                        <tr><td><code>multiplegridlimititems</code></td><td>—</td><td>Maximum number of items to show.</td></tr>
                        <tr><td><code>multiplegridepisoderange</code></td><td>—</td><td>Show only items in an episode range. Format: <code>1-10</code>. Overrides search and limit.</td></tr>
                        <tr><td><code>multiplegridgap</code></td><td><code>48px</code></td><td>CSS <code>gap</code> between grid items.</td></tr>
                        <tr><td><code>multiplegridminsize</code></td><td><code>400px</code></td><td>Minimum column width in the <code>auto-fill</code> grid.</td></tr>
                        <tr><td><code>multiplegridtext</code></td><td>—</td><td>Show <code>title</code> or <code>description</code> below each grid item. YouTube only.</td></tr>
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
                        <tr><td>YouTube request-in-progress TTL</td><td>600 s (10 min)</td><td>Prevents parallel duplicate YouTube API requests.</td></tr>
                        <tr><td>YouTube error TTL</td><td>600 s (10 min)</td><td>After a failed YouTube call, blocks a retry for this duration.</td></tr>
                        <tr><td>YouTube backup window</td><td>7,200 s (2 hrs)</td><td>If the last successful fetch was within this window, the backup JSON is served rather than re-calling the API.</td></tr>
                    </tbody>
                </table>

                <div class="maw-callout">
                    <strong>YouTube API Quota:</strong> The YouTube Data API allows 10,000 units/day. Each page of 50 playlist items costs 1 unit. A high cache TTL means fewer quota calls. You can clear the cache manually from the <a href="<?php echo esc_url(menu_page_url(Menu::SLUG, false)); ?>">Settings page</a> using the <em>Clear plugin cache</em> button.
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
