# Media API Widget

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
   - [`[media-api-widget-grid-search]` — Grid Search Bar](#media-api-widget-grid-search--grid-search-bar)
5. [Shortcode Attribute Reference](#shortcode-attribute-reference)
6. [Admin Shortcode Field References (Dynamic Values)](#admin-shortcode-field-references-dynamic-values)
7. [Podcast Platform Support](#podcast-platform-support)
8. [Caching Architecture](#caching-architecture)
9. [Runaway Protection](#runaway-protection)
10. [Tests](#tests)
11. [SEO Meta Tags](#seo-meta-tags)
12. [Podcast Player (`/podcast/player`)](#podcast-player-podcastplayer)
13. [API Statistics](#api-statistics)
14. [JavaScript Events](#javascript-events)
15. [Developer Hooks / Data Enrichment](#developer-hooks--data-enrichment)
    - [`media_api_widget_data_before_store` (filter)](#media_api_widget_data_before_store-filter)
    - [`media_api_widget_data_stored` (action)](#media_api_widget_data_stored-action)
    - [`media_api_widget_update_stored_data()`](#media_api_widget_update_stored_data)
    - [Recommended asynchronous transcription workflow](#recommended-asynchronous-transcription-workflow)
16. [Elementor Widgets](#elementor-widgets)
    - [Output modes](#output-modes)
    - [Settings: default, value, empty, stored field](#settings-default-value-empty-stored-field)
    - [Search grids and separate search bars](#search-grids-and-separate-search-bars)
    - [Editor behavior](#editor-behavior)
    - [`media_api_widget_grid_search_id` (filter)](#media_api_widget_grid_search_id-filter)
17. [Backward Compatibility](#backward-compatibility)

---

## Overview

Media API Widget provides a structured system for embedding YouTube playlists and podcast audio on WordPress pages using shortcodes. Key capabilities:

- **YouTube** — Fetches playlist items from the YouTube Data API v3, caches results server-side (transients) and client-side (localStorage), and renders clickable thumbnails with a built-in lightbox video player.
- **Podcasts** — Parses RSS feeds from direct URLs or via Apple/iTunes lookup for Omny, SoundCloud, Buzzsprout, and others. Renders thumbnail-based items that open an embedded audio player.
- **Custom Podcast Player** — A self-hosted, fully themed podcast player served at `/podcast/player` and embeddable via iframe.
- **Global Shortcode Fields** — Store key/value pairs in the admin and reference them in any shortcode attribute using `{{field_name}}` syntax.
- **SEO** — Automatically injects Open Graph and Twitter Card meta tags derived from the media content on each page.
- **API Stats** — Tracks every external API call in a database table with 24-hour reporting, including when each playlist's fallback backup was last stored and a direct download link for it.

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
| **YouTube request-in-progress transient** | 600 s (10 min) | Prevents duplicate simultaneous API requests. Also sets how long the atomic per-playlist refresh lock stays valid before an abandoned one can be reclaimed. |
| **YouTube error transient** | 600 s (10 min) | After a failed YouTube API call, blocks retry for this duration. |
| **YouTube backup window** | 7200 s (2 hrs) | If the last successful fetch was within this window, serves the local JSON backup instead of re-calling the API. |
| **YouTube maximum pages per refresh** | 20 pages | Hard ceiling on `playlistItems` requests during a single refresh. Allowed range 1–100. |
| **YouTube daily call limit** | 200 calls | Circuit breaker on total outbound YouTube requests per quota day. Allowed range 1–10,000. |

> **Important:** The YouTube Data API allows 10,000 units per day. Each playlist fetch costs 1 unit per page of 50 results. Set the cache TTL high enough to avoid exhausting your quota.

The Caching page also shows read-only guard status:

- **YouTube calls used today** — count against the configured daily limit.
- **Quota day / reset** — the current quota day and the timezone it resets in.
- **Last guard event** — why a refresh was last abandoned, if one was.

See [Runaway Protection](#runaway-protection) for what the two guards do.

---

### API Stats

Navigate to **Media API → API Stats** to see a summary of all external API calls made in the last 24 hours, broken down by playlist, media type, endpoint, and hour.

The **By Playlist and Endpoint** table also reports on the local backup each playlist falls back to when a live API call fails:

| Column | Shows |
|---|---|
| **Last Successful Backup** | When the playlist's backup JSON was last stored successfully, in the site timezone. Reads the `time_stored` value written inside the file, falling back to the file's modification time for older files. Shows *No backup stored* when no file exists yet. |
| **Backup File** | A **Download** link that streams the backup JSON file directly, with its size beside it. Shows an em dash when there is no file to download. |

Because a backup is only written after a refresh completes every requested page, the timestamp is the last *successful* store rather than the last attempt — a failed or partial refresh leaves the previous good backup, and its timestamp, untouched. YouTube playlists always have a backup once one refresh has succeeded. As of 5.0.0 every podcast platform that fetches an RSS feed has one too — direct RSS feeds and Apple-lookup platforms alike, including those warmed by a shortcode. Only embed platforms have no backup file, because they make no API call and store just the embed URL.

Downloads are served through `admin-post.php` rather than a direct uploads URL, so every request is checked for the `manage_options` capability and a valid nonce, and the file path is resolved and confirmed to be inside the backup directory before anything is sent. The files are only ever read — nothing on the page can write, replace, or delete a backup.

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

The YouTube video whose media item opens the lightbox autoplays. Neighboring carousel videos remain paused, including after they are selected with the navigation arrows. Podcast lightboxes request autoplay for the built-in custom player, Omny, and SoundCloud. Browser autoplay preferences can still block audible playback, Buzzsprout does not support autoplay, and arbitrary `embed` platform URLs retain their provider-defined behavior. Inline `[media-api-podcast-player]` embeds remain paused by default.

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

### `[media-api-widget-grid-search]` — Grid Search Bar

Renders a search bar that is linked to a `[media-api-widget-render]` grid running in `multiplegridusersearch="true"` mode. The bar is matched to the correct grid by `playlist_name` + `media_platform`.

The search bar contains a text input and a dropdown that lets users choose whether to search by **Any** (title and description), **Title**, or **Description**.

**Attributes:**

| Attribute | Required | Default | Description |
|---|---|---|---|
| `playlist_name` | Yes | — | Must match the `playlist_name` on the target grid shortcode. |
| `media_platform` | Yes | — | Must match the `media_platform` on the target grid shortcode. |
| `placeholder` | No | `Search...` | Placeholder text displayed inside the search input. |
| `searchbyenabled` | No | `true` | When `false`, hides the Search By dropdown and defaults to searching across both title and description. |

**Example:**
```
[media-api-widget-grid-search playlist_name="my_show" media_platform="youtube"]
```

**Example — hide the Search By dropdown:**
```
[media-api-widget-grid-search playlist_name="my_show" media_platform="youtube" searchbyenabled="false"]
```

Place this shortcode anywhere on the page — above, below, or beside the grid. It does not need to be adjacent to the grid shortcode.

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
| `showlightbox` | `true` | When `false`, clicking the item dispatches the [JS click event](#javascript-events) but does **not** open the lightbox. Useful for driving custom behavior without the built-in player. Omitting the attribute (default) preserves existing lightbox behavior. |

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
| `multiplegridtext` | _(none)_ | Show text below each grid item: `title`, `description`, `both`, `numberedtitle`, or `numberedtitleanddescription`. YouTube only. The `numberedtitle` options require the playlist's sort mode to be "Number in title": they display `Episode N` (or `Season S Episode N` when the season/episode regex is set), falling back to the plain title for items without a detected number. `numberedtitleanddescription` also appends the description, like `both`. |
| `multiplegridusersearch` | `false` | Set to `true` to enable the user-searchable + paginated grid mode. See [Grid Search & Pagination](#grid-search--pagination). |
| `multiplegridperpage` | `12` | Max items per page when `multiplegridusersearch="true"`. |
| `multiplegridmaxpagedisplay` | _(none)_ | Cap how many numbered page buttons show at once (a sliding window centred on the current page). A clickable `…` on each side jumps one set (this many pages), keeping all pages reachable. Omit/empty to list every page number with no `…`. |
| `noresults` | _(none)_ | Text shown when a user search yields no results (user-search grid only). Falls back to a generic message when empty. |
| `nostyling` | `false` | Set to `true` to suppress the plugin's **styling** class names (`media_item`, `media-item-*`, `media_items_multiple_grid_layout`, …) so you don't inherit the plugin's built-in CSS. The namespaced `maw-` **hook classes** (see [Styling Hooks](#styling-hooks)) are still emitted either way. Data attributes used by the lightbox JS are unaffected. |

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

### Grid Search & Pagination

When `multiplegridusersearch="true"` is set on `[media-api-widget-render]`, the grid is rendered in an interactive mode that supports AJAX-powered user search and pagination. A companion `[media-api-widget-grid-search]` shortcode renders the search bar, which is linked to the grid by `playlist_name` + `media_platform`.

**How it works:**
- The initial page is server-rendered — no AJAX on first load.
- Typing in the search bar triggers a debounced (400 ms) AJAX request that filters and re-renders the grid items.
- The search bar dropdown lets visitors search by **Any** (matches title or description), **Title**, or **Description**. Set `searchbyenabled="false"` on the `[media-api-widget-grid-search]` shortcode to hide the dropdown — the search will always match across both fields.
- A clear button is shown next to the search input by default. Clicking it clears the input, immediately fires the same AJAX request that an empty search would trigger, and returns focus to the input so visitors can type again without clicking. Set `clearsearchbutton="false"` to hide it.
- Clicking Prev / page number / Next buttons also fires an AJAX request.
- While any AJAX request is in flight, the wrapper element receives a `loading` CSS class (grid items and pagination become semi-transparent and non-interactive).
- The `[media-api-widget-grid-search]` shortcode can be placed anywhere on the page — it links to the matching grid via `playlist_name` + `media_platform`.

**Full example:**

```
[media-api-widget-grid-search playlist_name="my_show" media_platform="youtube"]

[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegridusersearch="true"
    multiplegridperpage="12"
    multiplegridtext="both"
    multiplegridgap="32px"
    multiplegridminsize="300px"
]
```

Use `multiplegridmaxpages` to cap how many pages appear in the pagination. For example, `multiplegridmaxpages="5"` means visitors will never see more than 5 pages regardless of how many items match. Omit the attribute (or leave it empty) for no limit.

Use `multiplegridmaxpagedisplay` to limit how many numbered page buttons are visible at once when a playlist has many pages. For example, `multiplegridmaxpagedisplay="4"` renders a sliding window of at most 4 numbers centred on the current page (e.g. `Prev … 7 8 9 10 … Next`). The `…` on each side is clickable and jumps one set — 4 pages — in that direction, so every page stays reachable without crowding the bar with dozens of numbers. Omit (or leave empty) to list every page number with no `…` truncation (the default). This pairs naturally with a large or uncapped `multiplegridmaxpages`.

> `multiplegridusersearch` and `multiplegrid` are mutually exclusive — when `multiplegridusersearch="true"`, the standard static grid path is bypassed entirely. All other existing grid attributes (`multiplegridgap`, `multiplegridminsize`, `multiplegridtext`, `multiplegridepisoderange`, etc.) continue to work as filters and display options within this mode.

---

### Styling Hooks

Every rendered element carries a namespaced `maw-` **hook class** in addition to the plugin's default styling class. The plugin ships **no CSS for the `maw-` classes** — they exist purely as stable targets for your own styles. Because they're independent of the plugin's look, they are emitted **even when `nostyling="true"`**, giving you a blank visual slate plus reliable selectors.

| Element | Hook class |
|---|---|
| Grid container | `maw-media-items-grid-layout` |
| Item link (`<a>`) | `maw-media-item` (＋ `maw-media-item-text-overlay-enabled` when the overlay is on) |
| Grid entry wrapper (shown when grid text is enabled) | `maw-media-item-multiple-grid-entry` |
| Thumbnail wrapper | `maw-media-item-thumbnail-text-wrapper` |
| Thumbnail image | `maw-media-item-thumbnail` |
| Play button | `maw-media-item-play-button` |
| Text overlay | `maw-media-item-text-overlay` |
| Grid text block | `maw-media-item-multiple-grid-text` ＋ `…-text-title` / `…-text-description` |
| Title/description block (`mediatitle` / `mediadescription`) | `maw-media-description-text` |
| Audio play bar | `maw-audio-play-bar` |
| Playlist heading / overlay sub-text | `maw-playlist-episode-text` / `maw-sub-text` |

The grid wrapper, search bar, and pagination controls have always carried their own `maw-` classes (`maw-grid-search-wrapper`, `maw-grid-items`, `maw-grid-pagination`, `maw-page-btn`, `maw-search-input`, …) and are unaffected by `nostyling`.

```css
/* With nostyling="true": no plugin styles to override, just hooks */
.maw-media-items-grid-layout { gap: 2rem; }
.maw-media-item { border-radius: 12px; overflow: hidden; }
.maw-media-item-thumbnail { aspect-ratio: 16 / 9; }
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

The state of the middle layer is visible in the admin: the **By Playlist and Endpoint** table on [API Stats](#api-stats) shows when each playlist's backup JSON was last stored successfully and offers it for download.

---

## Runaway Protection

YouTube playlists are fetched from `wp_head` on ordinary front-end page views, so anything that can loop — or that several PHP workers can enter at once — multiplies directly into billed API quota. Two independent guards bound the worst case, and neither changes the output of a healthy playlist.

### Safe pagination

Pagination follows YouTube's documented model: the first request carries no page token and counts as page 1, and each later request uses the `nextPageToken` from the previous response. Normal termination is the **absence** of a `nextPageToken`.

`pageInfo.totalResults` is deliberately **not** used as the loop's exit condition. For `playlistItems` it counts entries the API will not return (deleted or private videos), so a loop that waits for the collected-item tally to reach it can never satisfy its own exit condition and ends up relying entirely on the token.

That same gap produces a second termination shape. A playlist reporting 67 `totalResults` returned 50 items, then 11, then a page with an empty `items` array whose `nextPageToken` was the very token used to request it. An empty page reached **after** items have been collected is therefore treated as the successful end of pagination: the collected items are promoted normally and the echoed token is never followed. An empty **first** page that still supplies a token remains an abort.

Every page token used is remembered, a request is only ever issued for page 1 or for a nonempty previously-unseen token, and all URL parameters are encoded. The refresh is abandoned when any of these occurs:

| Reason code | Meaning |
|---|---|
| `repeated_page_token` | A nonempty page offered a token that was already used, so pagination would have looped. |
| `empty_page_with_next_token` | The first page returned no items at all yet still supplied another token. |
| `malformed_response` | The body was not valid JSON, or was missing `items` / `pageInfo`. |
| `maximum_pages_reached` | The configured page ceiling was reached. |
| `daily_limit_reached` | The daily call budget is spent; nothing was sent. |
| `concurrent_refresh` | Another worker already held this playlist's refresh lock. |
| `http_error` | A connection failure or a non-200 status on any page. |

**Partial data is never promoted to good data.** The backup JSON file, the primary transient, the cleared error transient, and `maw_yt_last_fetched_{playlist_name}` are written only after *every* requested page completed normally. A refresh that aborts part-way leaves all previously stored data untouched and falls back to it, so a failure can never overwrite a complete playlist with a truncated one.

Diagnostics record only a reason code, the playlist slug, a page count, and a timestamp — never the API key, the request URL, response bodies, or headers. One guard event is recorded per aborted refresh, and nothing is written to the PHP error log unless `WP_DEBUG` is enabled.

### Daily circuit breaker

Every attempted outbound YouTube `playlistItems` request is counted **before** it is sent, including requests that return errors, because a failed call can still consume quota. Once the limit is reached no request goes outbound at all and cached or backup data is served instead.

- The counter is stored per quota day and incremented with a single atomic SQL statement, so parallel PHP workers cannot meaningfully bypass it.
- It resets at **midnight `America/Los_Angeles`**, matching YouTube's own quota day, including across daylight-saving changes.
- Podcast, Apple/iTunes, RSS, and GitHub-updater requests are **not** counted.
- A request blocked before going outbound is not recorded as an API call.
- Counter rows older than yesterday are pruned, so `wp_options` does not grow one row per day forever.

### Concurrency

Simultaneous cache misses for the same playlist no longer all begin fetching. A refresh first takes an atomic per-playlist lock: a `maw_yt_lock_{playlist_name}` row created by a bare `INSERT`, so the database's unique `option_name` constraint performs the arbitration. (`add_option()` is not suitable here — WordPress implements it as an `INSERT ... ON DUPLICATE KEY UPDATE` behind a cached read, so two concurrent callers can both believe they created the row.)

The lock value carries an owner token and an expiry. Only the owner can release it, the release runs on every exit path — success, error, malformed response, page-limit abort, and thrown exception — and a lock left behind by a worker that died mid-refresh is reclaimed once it expires, so a stale lock can never block refreshes permanently. The legacy `{playlist_name}_youtube_request_in_progress` transient is still written and cleared exactly as before for the admin status UI and backward compatibility, but it is no longer what provides mutual exclusion.

---

## Tests

The plugin has no Composer dependencies, and neither does its test suite. It runs on plain PHP with a small set of WordPress doubles in `tests/bootstrap.php`:

```
php tests/run-tests.php            # run everything
php tests/run-tests.php pagination # run one group
```

The runner exits non-zero if any assertion fails. Groups live in `tests/cases/`:

| Group | Covers |
|---|---|
| `pagination` | One-page and eight-page/400-item playlists, termination on an absent token, repeated tokens, empty pages with a token, malformed JSON, missing `items`/`pageInfo`, HTTP and transport errors, the page ceiling, URL encoding, sorting/trimming, and that no API key reaches diagnostics. |
| `daily-limit` | Reservation before sending, enforcement mid-pagination, blocked requests not being logged, the midnight `America/Los_Angeles` rollover in both standard and daylight time, and counter cleanup. |
| `locking` | Two callers contending for one playlist, independent playlists, owner-only release, stale-lock recovery, corrupted lock values, and lock release on every exit path. |
| `cache-integrity` | That a partial or failed refresh leaves the backup file, the transient, and the last-fetched timestamp untouched, and that the existing back-off and backup-window short-circuits still work. |
| `options` | The new defaults, and that installs saved before the guard settings existed receive them at read time without the stored option being rewritten. |
| `podcast` | That podcast, Apple/iTunes, and embed paths consume no YouTube budget, take no YouTube lock, and still work when the YouTube budget is exhausted. |
| `elementor-integration` | That widget output is byte-identical to direct calls of the matching shortcode methods; that default, explicit false, empty, literal and stored-field settings map correctly and same-named stored fields still act as defaults; that hidden settings never reach the renderer; that widgets dispatch through the registered callback and its tag filters; that isolated and connected search grids stay independent while shortcode grids keep their id, grid key, settings transient and AJAX page parameter; that player styling overrides apply only to the widget call; that an editor render cannot request a podcast feed; and that nothing registers or fatals without Elementor. |
| `backup-inventory` | That the API Stats backup columns resolve the same file the media pipeline writes, report `time_stored` (falling back to the file time), keep YouTube and podcast backups separate, and report nothing for a missing file, an unsupported media type, or an empty slug. |
| `extension-api` | That the pre-store filter fires exactly once per complete successful refresh and never for a cache hit, backup read, partial refresh, failed request, unparseable feed, Apple lookup without a successful RSS fetch, or embed-only podcast; that the filtered value is written identically to the transient and backup and is what the current response renders; that a `WP_Error`, wrong shape, or unencodable return leaves the old data byte-for-byte intact; that the shortcode warm-up path cannot bypass the hooks; that the stored action is post-commit; that the global updater changes YouTube and podcast data with no external request while preserving custom item keys and storage formats; and that every lock is released on success, error, and thrown exception. |

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

### Backup visibility

Each row of the per-playlist breakdown also reports the backup that row's playlist would fall back to on failure:

- **Last Successful Backup** — the `time_stored` timestamp inside `{playlist_name}_{media_type}_backup_data.json`, rendered in the site timezone. Only the first few hundred bytes of the file are read to retrieve it, so a large playlist is never decoded just to render the column. Files predating the `time_stored` key fall back to the file modification time.
- **Backup File** — a nonced `admin-post.php?action=maw_download_backup` download link, capability-checked on `manage_options`, that streams the JSON file as an attachment.

Both columns are read-only. Backup files are still written solely by the media pipeline, on the same successful-refresh-only rule described in [Runaway Protection](#runaway-protection).

---

## JavaScript Events

Every time a YouTube, Vimeo, or podcast thumbnail is clicked — regardless of the `showlightbox` attribute — the plugin dispatches a `mediaApiWidgetItemClick` `CustomEvent` on `document`. External scripts can listen for this event to react to media interactions without modifying the plugin.

### `mediaApiWidgetItemClick`

**Fires:** On any click of a media item rendered by the plugin. Fires even when `showlightbox="false"` and the lightbox does not open.

**Listener:**
```javascript
document.addEventListener("mediaApiWidgetItemClick", (e) => {
    const { playlistName, mediaType, embedUrl, itemId, showLightbox, element } = e.detail;
});
```

**`event.detail` properties:**

| Property | Type | Description |
|---|---|---|
| `playlistName` | `string` | The `playlist_name` slug of the item's playlist. |
| `mediaType` | `string` | The media platform: `"youtube"`, `"podcast"`, or `"vimeo"`. |
| `embedUrl` | `string \| null` | The iframe src URL that the lightbox would (or does) load. For YouTube: `https://www.youtube.com/embed/{id}`. For podcasts: the platform-specific embed URL. `null` if the URL cannot be determined. |
| `itemId` | `string` | YouTube video ID or podcast episode GUID. |
| `showLightbox` | `boolean` | `true` when the lightbox is opening; `false` when `showlightbox="false"` is set on the shortcode. |
| `element` | `Element` | The `<a>` DOM node that was clicked. |

**Example — push to a GTM / analytics data layer:**
```javascript
document.addEventListener("mediaApiWidgetItemClick", (e) => {
    const { playlistName, mediaType, itemId, showLightbox } = e.detail;
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
        event: "media_item_click",
        playlist: playlistName,
        media_type: mediaType,
        item_id: itemId,
        opened_lightbox: showLightbox
    });
});
```

**Example — open a custom modal instead of the built-in lightbox:**
```javascript
document.addEventListener("mediaApiWidgetItemClick", (e) => {
    // showlightbox="false" is set on the shortcode — the plugin will not open a lightbox
    if (!e.detail.showLightbox) {
        openMyCustomModal(e.detail.embedUrl, e.detail.playlistName);
    }
});
```

---

## Developer Hooks / Data Enrichment

Two PHP hooks and two global functions let other code alter the playlist data this plugin stores. They exist for asynchronous enrichment — attaching transcript status, custom metadata, or anything else derived from an episode — without forking the plugin or re-fetching from the API.

All four are stable public API. The classes behind them are internal.

### `media_api_widget_data_before_store` (filter)

Alters successfully fetched and parsed media data immediately before it is written to server storage.

```php
$data = apply_filters(
    'media_api_widget_data_before_store',
    $data,
    $context
);
```

**Fires:** exactly once per complete, successful logical remote refresh, after everything has been fetched and parsed but before anything is written. For a paginated YouTube playlist it fires **once for the whole playlist**, after every page has completed — not once per page.

**Never fires for:** a transient cache hit; a backup file read (including the YouTube backup-window short-circuit); a partial refresh (page ceiling reached, repeated page token, daily call limit reached); a malformed API response; an HTTP or transport failure; a request blocked by the concurrency lock; a podcast RSS feed that fails to fetch or parse; an Apple/iTunes lookup that succeeds but whose RSS fetch or parse then fails; or an embed-only podcast, which makes no API call at all.

**The returned value is what gets stored** and what the current page render uses, so the response and the stored payload can never disagree.

**Return value is validated.** If the filter returns a `WP_Error`, a value that does not match the documented shape for that media type, or a value that cannot be JSON encoded, the refresh is treated as **failed**: nothing is written, the previous transient and backup file are left byte-for-byte intact, and a `store_rejected` guard event is recorded for the administrator on the [Caching](#caching) page. A callback that throws is contained the same way rather than taking the page down.

### `media_api_widget_data_stored` (action)

Fires after media data has been written successfully.

```php
do_action(
    'media_api_widget_data_stored',
    $data,
    $context
);
```

**Fires:** only after the storage write has completed. It does not fire for any rejected store, and it does not fire when the transient was written but the backup file write failed — that case is reported as a failure and retried, so firing would hand you the same payload twice.

**Callbacks run synchronously**, inside the request that performed the refresh — which for the front-end pipeline means inside `wp_head` on a visitor's page view. Do not call a transcription API, or anything else slow, directly from a callback. Enqueue background work instead.

**This action is post-commit.** The data is already stored by the time it fires, so a callback that throws is logged and discarded; it cannot turn a completed store into a reported failure or prevent the refresh from finalizing.

### `$context` (both hooks)

| Key | Type | Description |
|---|---|---|
| `playlist_name` | `string` | The `playlist_name` slug of the media item. |
| `media_type` | `string` | `youtube` or `podcast`. |
| `source` | `string` | `remote_refresh`, `shortcode_warmup`, or `manual_update` — see below. |
| `podcast_platform` | `string\|null` | The podcast platform slug (`custom`, `omny`, `soundcloud`, `buzzsprout`, `other`). `null` for YouTube. |

| `source` | Meaning |
|---|---|
| `remote_refresh` | A refresh performed by the front-end `wp_head` pipeline. |
| `shortcode_warmup` | A podcast cache warm-up performed because a shortcode rendered before the cache was populated. |
| `manual_update` | A write made by `media_api_widget_update_stored_data()`. |

No API key, credential, request URL, or response body is ever placed in the context.

### `media_api_widget_update_stored_data()`

Alters data that has **already** been stored, without making any API request.

```php
media_api_widget_update_stored_data(
    string $playlistName,
    string $mediaType,
    callable $mutator
);
```

The mutator receives the current decoded data and the same context array the hooks get:

```php
function ($data, array $context) {
    // Return altered data or WP_Error.
    return $data;
}
```

Returns the updated decoded data on success, and a `WP_Error` on failure.

- Accepts only `youtube` and `podcast`. The playlist name is sanitized and validated.
- Reads the current canonical data from the transient, falling back to the applicable backup file. **Never makes an external request.**
- Runs the mutator while holding the playlist's storage lock (`maw_media_data_lock_{media_type}_{playlist_name}`), so a remote refresh cannot land between the read and the write. The lock is released on success, on error, on `WP_Error`, and on a thrown exception.
- Writes the result to the transient **and** the applicable backup file, using the configured [media cache TTL](#caching), and refreshes the backup's `time_stored` value. The backup is replaced by an atomic rename, so a failed write cannot corrupt the previous one.
- Validates the mutator's return value exactly as the pre-store filter's is validated. On any validation or persistence failure, the existing data is retained.
- Fires `media_api_widget_data_stored` with `source => 'manual_update'` on success. It deliberately does **not** fire `media_api_widget_data_before_store`, so an enrichment write cannot loop back into itself.

**Error codes:** `maw_unsupported_media_type`, `maw_invalid_playlist_name`, `maw_data_locked`, `maw_no_stored_data`, `maw_unsupported_podcast_payload` (an embed-only podcast has no episode structure to enrich), `maw_mutator_threw`, `maw_invalid_youtube_data`, `maw_invalid_podcast_data`, `maw_json_encode_failed`, `maw_transient_write_failed`, `maw_backup_write_failed`.

### `media_api_widget_get_stored_data()`

Reads the currently stored data without refreshing it.

```php
media_api_widget_get_stored_data(string $playlistName, string $mediaType);
```

Prefers the transient and falls back to the backup file — the same precedence the front end uses. Makes no external request, so it is safe from WP-Cron, Action Scheduler, WP-CLI, or a REST callback. Returns `null` when nothing usable is stored.

### Data shapes

**YouTube** — an indexed list (`array_is_list()`) of media item arrays. Each item carries at least `title`, `id` (the YouTube video ID), `episode`, `thumbnail`, `publishedDate`, and `description`. An empty list is valid.

**Podcast** — the parsed RSS feed normalized to a plain nested PHP array (a `SimpleXMLElement` round-tripped through JSON), so callbacks can read and modify it with ordinary array syntax:

```
[
    'channel' => [
        'title'             => 'My Show',
        'rssUrl'            => 'https://example.com/feed.xml',
        'collectionViewUrl' => 'https://podcasts.apple.com/…',
        'item'              => [
            ['title' => '…', 'description' => '…', 'guid' => '…', 'pubDate' => '…'],
            …
        ],
    ],
]
```

`channel.item` is an **associative array rather than a list** for a single-episode feed, and may be absent for a feed with no episodes — handle both. Embed-only podcasts store a bare URL string and are excluded from these hooks entirely.

Arbitrary custom keys you add to individual items are preserved; only the container shape is validated.

Storage formats are unchanged from earlier versions: the YouTube transient holds a PHP array, the podcast transient holds a JSON string, and backup files hold a `{"time_stored": …, "data": …}` wrapper.

### Example — mark every item pending as it is stored

```php
add_filter(
    'media_api_widget_data_before_store',
    static function ($data, array $context) {
        if (
            $context['media_type'] !== 'youtube'
            || $context['playlist_name'] !== 'my_show'
        ) {
            return $data;
        }

        foreach ($data as &$item) {
            $item['transcript_status'] = 'pending';
        }
        unset($item);

        return $data;
    },
    10,
    2
);
```

### Example — record a finished transcript

```php
$result = media_api_widget_update_stored_data(
    'my_show',
    'youtube',
    static function ($data, array $context) {
        foreach ($data as &$item) {
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
}
```

### Recommended asynchronous transcription workflow

This plugin does not integrate with any transcription provider and adds no third-party dependency. The supported pattern is:

1. Hook `media_api_widget_data_stored`.
2. **Ignore events whose `source` is not `remote_refresh` or `shortcode_warmup`.** This is what prevents a loop: your own writes arrive as `manual_update`, and acting on them would trigger another write.
3. Enqueue a WP-Cron event or an Action Scheduler job. Do not call the provider from the callback.
4. Identify YouTube episodes by video ID (`$item['id']`) and podcast episodes by GUID (`$item['guid']`).
5. Call the transcription service asynchronously from that background job.
6. When the result is ready, write it back with `media_api_widget_update_stored_data()`.

```php
add_action(
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
);
```

Note that a remote refresh **replaces** the stored payload with whatever the API returned, so fields a `manual_update` added are not present in the next refresh's data. The storage lock prevents the two writers from interleaving or leaving the transient and backup disagreeing; it does not merge them. Re-apply derived fields in `media_api_widget_data_before_store`, which runs on every refresh, and keep the authoritative record in your own storage.

### Do not store full transcripts in the playlist payload

**Full transcripts can be very large, and this payload is not a private server-side cache.** The plugin embeds playlist data directly into page output and copies it into the browser's `localStorage` (see [Caching Architecture](#caching-architecture)). A few hundred kilobytes of transcript text per episode becomes a few hundred kilobytes on every page load, for every visitor.

Store full transcripts separately — a custom table, a custom post type, post meta, or object storage — and attach only a small reference to the playlist item: a status, a short excerpt, a database key, a REST URL, or a transcript URL.

### Browser cache propagation

A server-side update changes the transient and the backup file. It cannot reach into a visitor's browser. Clients that already have a payload in `localStorage` keep serving it until the normal media cache and cookie refresh cycle expires, which is governed by the **Media cache transient** setting on the [Caching](#caching) page. There is no mechanism — in this plugin or in WordPress — to delete cookies or `localStorage` from every visitor's browser from the server. Plan for enrichment to appear progressively as clients refresh, not instantly.

---

## Elementor Widgets

When Elementor is active the plugin adds two widgets. Both render through the plugin's existing shortcode callbacks — the widget settings are translated into the attributes the matching shortcode would receive, so the output inside Elementor's wrapper is exactly the shortcode output. Nothing changes for sites without Elementor, and Elementor Pro is not required.

| Widget | Requires | Editor |
|---|---|---|
| **Media API — Classic** | Elementor | Classic panel; listed under the **Media API** category. |
| **Media API — Atomic** | Elementor with the Atomic Widgets feature active | V4 panel, Style tab, atomic element; listed with the atomic elements. Not registered when the feature is inactive. |

The playlist selectors list the configured Media Items by name only. API keys, playlist IDs and feed URLs are never sent to the editor.

### Output modes

| Mode | Renders through |
|---|---|
| Media card | `[media-api-widget-render]` |
| Grid | `[media-api-widget-render multiplegrid="true"]` |
| Searchable grid with pagination | `[media-api-widget-render multiplegridusersearch="true"]`, plus `[media-api-widget-grid-search]` when **Show search bar above grid** is on |
| Search bar only | `[media-api-widget-grid-search]` |
| Title text / Description text / Description text (title fallback) | `[media-api-widget-render mediatitle / mediadescription]` |
| Embedded podcast player | `[media-api-podcast-player]` |
| Stored field value | `[media-api-widget field=""]` |

The selected mode sets `mediatitle`, `mediadescription`, `multiplegrid` and `multiplegridusersearch` explicitly, so a stored field with one of those names cannot turn a card widget into a grid. Every other control is shown only for the modes, media types and options where the renderer uses it, and a value saved under a hidden control is never passed to the renderer.

**Embedded podcast player styling.** The `[media-api-podcast-player]` shortcode accepts only `playlist_name`, `media_platform` and `orderdescending`; its colors, font and date option always come from the `podcast_player_*` stored fields. That shortcode behavior is unchanged. The widget's **Embedded Player** controls can override those values for that widget alone; anything left at *Default* still uses the stored fields.

### Settings: default, value, empty, stored field

Each setting that maps to a shortcode attribute offers:

| Choice | Passed to the shortcode renderer |
|---|---|
| **Default** | The attribute is omitted, so the shortcode's built-in default — or a stored field with the same name as the attribute — applies, exactly as for a shortcode. |
| **Yes / No**, a listed option, or **Custom value** | The value. A custom value left blank counts as *Default*. |
| **Empty** | An empty string, where the renderer treats that differently from omitting the attribute. |
| **Stored field: name** | `{{name}}`, resolved by the renderer — available for colors and numbers too. Text values may also contain `{{name}}` directly. |

The search bar renderer does not resolve stored field references, so its settings do not offer them.

### Search grids and separate search bars

Shortcode grids and search bars link through `playlist_name` + `media_platform`, so every grid for one playlist shares one search bar and one `maw_page_{playlist}_{platform}` page parameter. A searchable grid widget chooses its **Search bar link**:

| Link | Behavior |
|---|---|
| **This widget only** (default) | The grid has its own id, settings, and `maw_page_{id}` parameter. Use **Show search bar above grid** for its search bar. |
| **Connection ID** | Shared with a **Search bar only** widget for the same playlist that uses the same Connection ID. |
| **Shared playlist link** | Behaves exactly like the shortcode grid, and links to `[media-api-widget-grid-search]` bars for the playlist. |

A **Search bar only** widget links either by Connection ID or through the shared playlist link.

### Editor behavior

- Widgets render in the classic and atomic editors through the same shortcode callbacks as on the front end. Search and pagination work in the editor preview, including after control changes, duplication, and removal.
- Lightboxes do not open from cards inside the editor canvas. The existing click handler only accepts elements from the page's own document, and Elementor creates editor content in the editor window. This affects shortcodes inside Elementor widgets in the same way, and does not affect published pages or Elementor's Preview.
- The editor never requests a YouTube API or podcast feed. If a podcast's cache is empty, the editor shows a notice instead of triggering the warm-up request the shortcode makes on the front end.
- The atomic editor hides values for controls whose conditions no longer apply and restores them when the conditions apply again, for the rest of the browser session. This is Elementor's own behavior.
- On save, Elementor stores each widget's equivalent shortcode text in the post content, so the page still renders through the shortcodes if Elementor is deactivated. Widget-only behavior (an isolated search id, player styling overrides) has no shortcode equivalent there.
- The widgets are excluded from Elementor's element cache, because their output depends on cached media data, stored fields and the requested page.

### `media_api_widget_grid_search_id` (filter)

Filters the id that links a searchable grid to its search bar and names its `maw_page_{id}` parameter.

```php
$gridId = apply_filters('media_api_widget_grid_search_id', $gridId, $playlistName, $mediaType);
```

The default is `{playlist_name}_{media_platform}`. The Elementor widgets attach this filter only while their own grid renders. A grid rendered with a non-default id stores the id with its settings so AJAX pagination keeps using its parameter; grids with the default id render, hash, and store their settings exactly as before. The returned value is passed through `sanitize_key()`; an empty result falls back to the default.

---

## Backward Compatibility

- The plugin merges any `MEDIA_CONTENT_DATA` constant (defined by WPCode or a theme) with admin-configured media items, so legacy setups continue to work without changes.
- The `[media-api-widget-item]` shortcode tag is an alias for `[media-api-widget-render]`.
- The `mutiplegridtext` attribute (legacy typo) is automatically aliased to `multiplegridtext`.
- The two guard settings added in 4.8.0 are read from the existing `maw_cache_expirations` option. Installs that predate them receive the defaults at read time — no resave is required, no existing option name or value changes, and reading the settings does not rewrite what is stored.
- The backup columns added to the API Stats page in 4.9.0 are purely read-only reporting over the backup files the plugin already wrote. No file name, location, or write rule changed, no new option or database column was introduced, and installs with backups predating the `time_stored` key still report a timestamp via the file modification time.
- The pagination change in 4.10.0 only widens what counts as a successful refresh: an empty tail page reached after items have been collected now ends pagination normally instead of aborting. No setting, option, transient name, backup file format, or guard reason code changed, and a healthy playlist produces byte-identical output. Playlists that were failing every refresh on this shape begin succeeding on their next refresh with no intervention; the `empty_page_with_next_token` guard reason is still recorded when the very first page returns no items.
- The [Developer Hooks](#developer-hooks--data-enrichment) added in 5.0.0 are additive: a site with no callbacks registered behaves exactly as before. No setting, option name, transient name, cache key, or backup file name changed. The YouTube transient still holds a PHP array, the podcast transient still holds a JSON string, and backup files still hold the `{"time_stored": …, "data": …}` wrapper. Five behaviors did change, all of them fixes:
  - **Apple/iTunes podcasts now work in the `wp_head` pipeline.** `omny`, `soundcloud`, `buzzsprout`, and `other` previously threw a `TypeError` there, because the raw HTTP response was passed where the response body was expected. These playlists were already being served correctly by the shortcode path, so most sites will see no visible difference beyond the error going away.
  - **Every RSS-fetching podcast platform now writes a backup file**, not just `custom`. This makes the existing local-fallback and API Stats backup reporting meaningful for Apple-lookup playlists. Nothing needs to be done; the file appears on the next refresh.
  - **The shortcode podcast cache warm-up honors the configured Media cache transient TTL** instead of a hardcoded 2 hours, and writes a backup file. If your TTL is set to something other than 7200 seconds, warmed podcast caches now respect it.
  - **Podcast data is normalized to a plain PHP array before being stored.** The stored transient is still a JSON string and every reader in the plugin already decoded it to an array, so this is not a format change. One serialization detail differs: an empty XML element now serializes as `[]` rather than `{}`, which is what the shortcode path already produced and what the front-end JavaScript handles better.
  - **A refresh whose data is refused now records a `store_rejected` guard event** on the Caching page. This is a new value in an existing reason list; unknown reasons were already displayed generically.
- The Elementor integration is additive. Every shortcode tag, alias, attribute, default and output is unchanged, as are the AJAX search and pagination responses for shortcode grids, their grid keys and stored settings, and the legacy client-side `data-playlistname` rendering. The front-end script now exposes `window.mawGridSearch` and skips grids that are already bound; the page-load binding is unchanged.
- The internal `MediaContent::backupDir()` and `BackupInventory` path methods still exist and still return the same paths in 5.0.0; they now delegate to a shared `Support\BackupFiles` class so the storage service, the fetch pipeline, and the Stats page cannot disagree about where a backup lives.
