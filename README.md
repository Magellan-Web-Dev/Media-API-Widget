# Media API Widget

**Version:** 3.4.0  
**Author:** Chris Paschall  
**Requires WordPress:** 5.0+  
**Requires PHP:** 7.4+

A WordPress plugin that syncs YouTube playlists and podcast RSS feeds to the front end, with full admin-managed configuration — no WPCode constants required.

---

## Table of Contents

1. [Overview](#overview)
2. [Installation](#installation)
3. [Admin Panel](#admin-panel)
   - [Settings — Media Items](#settings--media-items)
   - [Settings — Shortcode Fields](#settings--shortcode-fields)
   - [Caching](#caching)
   - [API Stats](#api-stats)
4. [Shortcodes](#shortcodes)
   - [`[media-api-widget]` — Field Output](#media-api-widget--field-output)
   - [`[media-api-widget-render]` — Media Item](#media-api-widget-render--media-item)
   - [`[media-api-podcast-player]` — Podcast Player Embed](#media-api-podcast-player--podcast-player-embed)
5. [Shortcode Attribute Reference](#shortcode-attribute-reference)
6. [Admin Shortcode Field References (Dynamic Values)](#admin-shortcode-field-references-dynamic-values)
7. [Podcast Platform Support](#podcast-platform-support)
8. [Caching Architecture](#caching-architecture)
9. [SEO Meta Tags](#seo-meta-tags)
10. [Podcast Player (`/podcast/player`)](#podcast-player-podcastplayer)
11. [API Statistics](#api-statistics)
12. [Backward Compatibility](#backward-compatibility)

---

## Overview

Media API Widget provides a structured system for embedding YouTube playlists and podcast audio on WordPress pages using shortcodes. Key capabilities:

- **YouTube** — Fetches playlist items from the YouTube Data API v3, caches results server-side (transients) and client-side (localStorage), and renders clickable thumbnails with a built-in lightbox video player.
- **Podcasts** — Parses RSS feeds from direct URLs or via Apple/iTunes lookup for Omny, SoundCloud, Buzzsprout, and others. Renders thumbnail-based items that open an embedded audio player.
- **Custom Podcast Player** — A self-hosted, fully themed podcast player served at `/podcast/player` and embeddable via iframe.
- **Global Shortcode Fields** — Store key/value pairs in the admin and reference them in any shortcode attribute using `{{field_name}}` syntax.
- **SEO** — Automatically injects Open Graph and Twitter Card meta tags derived from the media content on each page.
- **API Stats** — Tracks every external API call in a database table with 24-hour reporting.

---

## Installation

1. Upload the `media-api-widget` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Navigate to **Media API** in the WordPress admin sidebar.
4. Add your YouTube playlists and/or podcast feeds under **Media Items**.
5. Place shortcodes on any page or post.

> After activation, visit **Settings → Permalinks** and click **Save Changes** to flush rewrite rules if the podcast player route (`/podcast/player`) does not resolve.

---

## Admin Panel

Access the plugin settings at **WordPress Admin → Media API**.

### Settings — Media Items

Define the YouTube playlists and podcast feeds the plugin should manage. Each item requires:

| Field | Description |
|---|---|
| **Type** | `YouTube` or `Podcast` |
| **Name (playlist_name)** | A unique slug used to reference this item in shortcodes (e.g. `my_show`). Must be lowercase, no spaces. |

#### YouTube-specific fields

| Field | Description |
|---|---|
| **Playlist ID** | The YouTube playlist ID (found in the YouTube URL after `list=`). |
| **API Key** | Your Google/YouTube Data API v3 key. |
| **Sort Mode** | `Normal` — preserves YouTube order. `Number in title` — extracts the leading number from each video title, deduplicates, and sorts descending. Use this for TV/show episodes numbered in their title. |
| **Load full playlist** | When checked, fetches all videos in the playlist. When unchecked, limits to the first 6. |

#### Podcast-specific fields

| Field | Description |
|---|---|
| **Platform** | See [Podcast Platform Support](#podcast-platform-support). |
| **RSS URL / ID / Embed URL** | Depends on the platform selected (see below). |

---

### Settings — Shortcode Fields

Shortcode fields are global key/value pairs stored in the database and referenceable inside any shortcode attribute value using `{{field_name}}` syntax.

**Ten default fields are auto-seeded** on activation and cannot be deleted (their field names are locked). You can change their values:

| Field | Default Value | Used For |
|---|---|---|
| `podcast_player_background_color` | `#151515` | Podcast player background / mode color |
| `podcast_player_text_color` | `#ffffff` | Podcast player text color |
| `podcast_player_play_icon_color` | `#ffffff` | Podcast player play button color |
| `podcast_player_color` | `#c7c7c7` | Podcast player accent color |
| `podcast_player_progress_bar_color` | `#616161` | Podcast player progress bar color |
| `podcast_player_selected_color` | `#7a7a7a` | Podcast player selected episode highlight color |
| `podcast_player_font` | `Roboto` | Podcast player font (Google Fonts name) |
| `podcast_player_scrollbar_color` | `#c7c7c7` | Podcast player scrollbar color |
| `lightbox_playlist_logo` | _(empty)_ | Logo image URL displayed in the lightbox playlist panel header |
| `lightbox_playlist_border_color` | `#ffffff` | Border / theme color of the lightbox playlist panel |

You can add custom fields for any value you want to centrally manage and reuse across shortcodes (e.g. `hero_title`, `brand_color`, `show_name`).

**Example — adding a custom field:**
- Field: `show_tagline`
- Value: `All-new episodes every Tuesday`

Then use it in a shortcode: `[media-api-widget field="show_tagline"]`

---

### Caching

Configure how long data is cached to control YouTube API quota usage. Navigate to **Media API → Caching**.

| Setting | Default | Description |
|---|---|---|
| **Media cache transient** | 7200 s (2 hrs) | How long fetched YouTube or podcast data is held in WordPress transients. Also sets the client-side cookie duration. |
| **YouTube request-in-progress transient** | 600 s (10 min) | Prevents duplicate simultaneous API requests. |
| **YouTube error transient** | 600 s (10 min) | After a failed YouTube API call, blocks retry for this duration. |
| **YouTube backup window** | 7200 s (2 hrs) | If the last successful fetch was within this window, serves the local JSON backup instead of re-calling the API. |

> **Important:** The YouTube Data API allows 10,000 units per day. Each playlist fetch costs 1 unit per page of 50 results. Set the cache TTL high enough to avoid exhausting your quota.

---

### API Stats

Navigate to **Media API → API Stats** to see a summary of all external API calls made in the last 24 hours, broken down by playlist, media type, endpoint, and hour.

Logs are automatically pruned after 48 hours.

---

## Shortcodes

### `[media-api-widget]` — Field Output

Outputs the **value** of a stored shortcode field as plain text. Use this to inject centrally managed text anywhere on a page.

```
[media-api-widget field="field_name"]
```

**Example:**
```
[media-api-widget field="show_tagline"]
```

Outputs: `All-new episodes every Tuesday`

---

### `[media-api-widget-render]` — Media Item

Renders a clickable media thumbnail. Clicking it opens a lightbox with the YouTube video player or podcast audio player.

Also available as the alias `[media-api-widget-item]`.

**Minimal example (YouTube):**
```
[media-api-widget-render playlist_name="my_show" media_platform="youtube"]
```

**Minimal example (Podcast):**
```
[media-api-widget-render playlist_name="my_podcast" media_platform="podcast" thumbnail="https://example.com/cover.jpg"]
```

**Grid of all episodes:**
```
[media-api-widget-render playlist_name="my_show" media_platform="youtube" multiplegrid="true" multiplegridtext="title"]
```

**Specific episode by number:**
```
[media-api-widget-render playlist_name="my_show" media_platform="youtube" episodenumber="5"]
```

**Specific episode by title keyword:**
```
[media-api-widget-render playlist_name="my_show" media_platform="youtube" nameselect="pilot"]
```

**Episode by position:**
```
[media-api-widget-render playlist_name="my_show" media_platform="youtube" orderdescending="1"]
```

**Display episode title text only (no thumbnail):**
```
[media-api-widget-render playlist_name="my_show" media_platform="youtube" orderdescending="3" mediatitle="true"]
```

**Display episode description text only:**
```
[media-api-widget-render playlist_name="my_show" media_platform="youtube" orderdescending="3" mediadescription="true" mediadescriptiontextcolor="#333333"]
```

---

### `[media-api-podcast-player]` — Podcast Player Embed

Renders the custom podcast player as an inline `<iframe>`. This is the recommended shortcode for embedding the full podcast player UI on a page.

**Basic usage (uses default admin shortcode field colors):**
```
[media-api-podcast-player playlist_name="my_podcast"]
```

**With custom colors:**
```
[media-api-podcast-player
    playlist_name="my_podcast"
    podcastplayermode="dark"
    podcastplayercolor="#c7c7c7"
    podcastplayertextcolor="#ffffff"
    podcastplayerbuttoncolor="#ffffff"
    podcastprogressplayerbarcolor="#616161"
    podcastplayerhighlightcolor="#7a7a7a"
    podcastplayerscrollcolor="#c7c7c7"
    podcastplayerfont="Poppins"
]
```

**Starting on a specific episode (episode 3):**
```
[media-api-podcast-player playlist_name="my_podcast" orderdescending="3"]
```

**Append publish date to episode title:**
```
[media-api-podcast-player playlist_name="my_podcast" showepisodedateaftertitle="true"]
```

---

## Shortcode Attribute Reference

### Selection Attributes (apply to `[media-api-widget-render]` and `[media-api-podcast-player]`)

| Attribute | Default | Description |
|---|---|---|
| `playlist_name` | _(required)_ | Slug matching the **Name** defined in admin Media Items. |
| `media_platform` | `youtube` | `youtube` or `podcast`. |
| `podcast_platform` | _(from admin)_ | Override the platform: `custom`, `omny`, `soundcloud`, `buzzsprout`, `other`, `embed`. |
| `orderdescending` | _(none)_ | Select a specific item by its 1-based position in the playlist. `orderdescending="1"` = most recent/first. |
| `episodenumber` | _(none)_ | YouTube only. Select by episode number (extracted from title when sort mode is `number_in_title`). |
| `nameselect` | _(none)_ | Select the first item whose title contains this keyword (case-insensitive). |

> **Priority:** `episodenumber` → `nameselect` → `orderdescending`. For `[media-api-podcast-player]`, `orderdescending` defaults to `1`.

---

### Display Attributes

| Attribute | Default | Description |
|---|---|---|
| `showplaybutton` | `true` | Show the circular play button icon over the thumbnail. |
| `playbuttoniconimgurl` | _(none)_ | URL of a custom play button image. Replaces the default SVG icon. |
| `playbuttonstyling` | `width: 35%; height: 35%; opacity: 0.3;` | Inline CSS applied to the play button container. |
| `showtextoverlay` | `true` | Show a text overlay (episode title + instruction message) over the thumbnail. |
| `instructionmessage` | `Click Here To Watch` / `Click Here To Listen` | The instruction text shown in the overlay. |
| `fontfamily` | _(none)_ | Font family for the media item container. |
| `thumbnail` | _(none)_ | Thumbnail image URL. Required for podcasts; YouTube thumbnails are fetched automatically. |
| `logo` / `lightboxshowlogoimgurl` | _(none)_ | URL of a logo displayed in the lightbox playlist panel. |
| `lightboxfont` | _(none)_ | Font family for the lightbox. |
| `lightboxthemecolor` | _(none)_ | Border color of the lightbox playlist panel (e.g. `#ff0000`). |
| `lightboxshowplaylist` | `false` | When `true`, opening the lightbox shows the full playlist panel. |
| `showplaybar` | `false` | For podcast items: shows an SVG audio waveform bar below the thumbnail. |
| `playbarcolor` | `#fff` | Color of the audio play bar. |
| `mediatitle` | `false` | When `true`, renders only the episode title as a `<p>` tag — no thumbnail. |
| `mediadescription` | `false` | When `true`, renders only the episode description as a `<p>` tag — no thumbnail. |
| `mediadescriptiontextcolor` | _(none)_ | CSS color applied to the `mediatitle` / `mediadescription` output. |

---

### Grid Attributes (apply to `[media-api-widget-render]`)

Enable grid mode to display multiple media items in a responsive CSS grid.

| Attribute | Default | Description |
|---|---|---|
| `multiplegrid` | `false` | Set to `true` to render all (filtered) playlist items in a grid. |
| `multiplegridshowall` | `false` | When `true`, disables all filtering — shows every item. |
| `multiplegridsearch` | _(none)_ | Filter grid items to those whose title contains this keyword. |
| `multiplegridlimititems` | _(none)_ | Limit the grid to this number of items. |
| `multiplegridepisoderange` | _(none)_ | Show only items whose episode number falls in a range. Format: `1-10`. Overrides search and limit. |
| `multiplegridgap` | `48px` | CSS `gap` value for the grid. |
| `multiplegridminsize` | `400px` | Minimum column width in the `auto-fill` grid. |
| `multiplegridtext` | _(none)_ | Show text below each grid item: `title` or `description`. YouTube only. |

**Example — filtered grid of episodes 1–6 with titles:**
```
[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegrid="true"
    multiplegridepisoderange="1-6"
    multiplegridtext="title"
    multiplegridgap="32px"
    multiplegridminsize="300px"
]
```

---

### Podcast Player Styling Attributes

These apply to both `[media-api-widget-render]` (for the click-to-open player) and `[media-api-podcast-player]` (for the inline iframe player). If left empty, the player reads the corresponding **default shortcode fields** from the admin.

| Attribute | Admin Field | Default | Description |
|---|---|---|---|
| `podcastplayermode` | `podcast_player_background_color` | `dark` | Background mode/color. `dark`, `light`, or any hex (e.g. `#1a1a2e`). |
| `podcastplayertextcolor` | `podcast_player_text_color` | `#ffffff` | Text color. |
| `podcastplayerbuttoncolor` | `podcast_player_play_icon_color` | `#ffffff` | Play button icon color. |
| `podcastplayercolor` | `podcast_player_color` | `#c7c7c7` | Accent / UI element color. |
| `podcastprogressplayerbarcolor` | `podcast_player_progress_bar_color` | `#616161` | Progress bar fill color. |
| `podcastplayerhighlightcolor` | `podcast_player_selected_color` | `#7a7a7a` | Selected episode highlight color. |
| `podcastplayerfont` | `podcast_player_font` | `Roboto` | Google Fonts font name. |
| `podcastplayerscrollcolor` | `podcast_player_scrollbar_color` | `#c7c7c7` | Scrollbar color. |
| `showepisodedateaftertitle` | — | `false` | When `true`, appends the publish date to each episode title. |

---

## Admin Shortcode Field References (Dynamic Values)

Any shortcode attribute value can reference a stored admin shortcode field using either syntax:

**Syntax 1 — Mustache-style:**
```
{{field_name}}
```

**Syntax 2 — Shortcode-style:**
```
[media-api-widget field="field_name"]
```

**Example — using the centrally managed podcast colors:**
```
[media-api-widget-render
    playlist_name="my_podcast"
    media_platform="podcast"
    podcast_platform="custom"
    thumbnail="https://example.com/cover.jpg"
    podcastplayermode="{{podcast_player_background_color}}"
    podcastplayercolor="{{podcast_player_color}}"
    podcastplayertextcolor="{{podcast_player_text_color}}"
    podcastplayerbuttoncolor="{{podcast_player_play_icon_color}}"
    podcastprogressplayerbarcolor="{{podcast_player_progress_bar_color}}"
    podcastplayerhighlightcolor="{{podcast_player_selected_color}}"
    podcastplayerfont="{{podcast_player_font}}"
    podcastplayerscrollcolor="{{podcast_player_scrollbar_color}}"
]
```

> When using `[media-api-podcast-player]`, the default shortcode fields are applied **automatically** — you don't need to specify them unless you want to override a specific value.

---

## Podcast Platform Support

| Platform Value | `media_data` Field | Notes |
|---|---|---|
| `custom` | Direct RSS feed URL | The plugin fetches and parses the RSS feed directly. |
| `omny` | Apple Podcast numeric ID | Looks up the RSS URL via the iTunes API, then parses it. |
| `soundcloud` | Apple Podcast numeric ID | Same iTunes lookup path. Audio embed uses SoundCloud's player URL. |
| `buzzsprout` | Apple Podcast numeric ID | Same iTunes lookup path. |
| `other` | Apple Podcast numeric ID | Generic iTunes lookup for any Apple-listed podcast. |
| `embed` | An embed player URL | Skips RSS parsing entirely; embeds the provided URL in an iframe on click. |

---

## Caching Architecture

The plugin uses a layered cache strategy:

```
Request arrives
    │
    ▼
Is WordPress transient set? ──Yes──► Serve from transient
    │ No
    ▼
Is YouTube backup JSON recent enough (backup window)?
    │ Yes ──► Serve backup JSON
    │ No
    ▼
Make YouTube API call / fetch RSS feed
    │
    ├──► Store in transient (configurable TTL)
    ├──► Write backup JSON file to /uploads/media-api-widget/backups/
    └──► Push to browser localStorage via inline <script>
```

The **client-side cookie** (`media_api_widget`) controls when the browser re-requests fresh data. When the cookie is absent or expired, the server pushes the latest cached data into `localStorage`. The front-end JavaScript reads `localStorage` on every page load.

---

## SEO Meta Tags

When a page contains a `[media-api-widget-render]` or `[media-api-podcast-player]` shortcode, the plugin automatically injects Open Graph and Twitter Card meta tags into `<head>` based on the selected media item.

Tags generated:
- `description`
- `og:type` (`video.other` for YouTube, `article` for podcasts)
- `og:title`, `og:description`, `og:site_name`, `og:url`
- `og:image`, `og:image:alt`
- `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`, `twitter:image:alt`
- `article:published_time`

The selected item is resolved using the same `episodenumber`, `nameselect`, and `orderdescending` attributes as the shortcode.

---

## Podcast Player (`/podcast/player`)

The plugin registers the route `/podcast/player` as a standalone HTML page that hosts the full podcast player UI. It is loaded in an `<iframe>` by both the `[media-api-podcast-player]` shortcode and by clicking a podcast thumbnail from `[media-api-widget-render]` when `podcast_platform="custom"`.

### Query Parameters

| Parameter | Description |
|---|---|
| `url` | _(required)_ The RSS feed URL. |
| `track` | 1-based episode number to start on. Defaults to `1` (most recent). |
| `mode` | Background color. `dark`, `light`, or a hex value (without `#`). |
| `buttoncolor` | Play button color hex (without `#`). |
| `color1` | Accent color hex (without `#`). |
| `progressbarcolor` | Progress bar color hex (without `#`). |
| `highlightcolor` | Selected episode highlight color hex (without `#`). |
| `font` | Google Fonts font name (e.g. `Poppins`). |
| `scrollcolor` | Scrollbar color hex (without `#`). |
| `textcolor` | Text color hex (without `#`). |
| `adddatetotitle` | Set to `true` to append the publish date to each episode title. |
| `singleepisode` | A GUID string. If provided, only that episode is shown (no episode list). |

**Direct URL example:**
```
https://yoursite.com/podcast/player?url=https://feeds.example.com/podcast.xml&track=1&mode=dark&font=Roboto
```

---

## API Statistics

Every call to an external API (YouTube, iTunes lookup, podcast RSS) is logged to the `{prefix}_maw_api_call_logs` database table with:

- Playlist name
- Media type (`youtube` / `podcast`)
- Endpoint (`youtube_playlist_items`, `podcast_rss`, `podcast_lookup`, `external_request`)
- HTTP status code
- Error flag
- Timestamp (GMT)

Logs older than **48 hours** are pruned automatically (checked at most once per hour). The **API Stats** admin page shows totals, per-playlist breakdowns, and hourly detail for the **last 24 hours**.

---

## Backward Compatibility

- The plugin merges any `MEDIA_CONTENT_DATA` constant (defined by WPCode or a theme) with admin-configured media items, so legacy setups continue to work without changes.
- The `[media-api-widget-item]` shortcode tag is an alias for `[media-api-widget-render]`.
- The `mutiplegridtext` attribute (legacy typo) is automatically aliased to `multiplegridtext`.
