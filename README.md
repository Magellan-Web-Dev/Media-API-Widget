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
   - [Grid Attributes](#grid-attributes-apply-to-media-api-widget-render)
   - [Grid Search & Pagination](#grid-search--pagination)
   - [Carousel Layout](#carousel-layout)
   - [Styling Hooks](#styling-hooks)
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
    - [Carousel controls](#carousel-controls)
    - [Editor behavior](#editor-behavior)
    - [`media_api_widget_grid_search_id` (filter)](#media_api_widget_grid_search_id-filter)
17. [Stored Playlist Posts (Loop Grid & Page Builders)](#stored-playlist-posts-loop-grid--page-builders)
    - [Enabling post storage](#enabling-post-storage)
    - [Stored fields](#stored-fields)
    - [Elementor Pro Loop Grid](#elementor-pro-loop-grid)
    - [Media API Click Action](#media-api-click-action)
    - [When posts are updated and deleted](#when-posts-are-updated-and-deleted)
    - [Turning storage off and retention](#turning-storage-off-and-retention)
18. [Backward Compatibility](#backward-compatibility)

---

## Overview

Media API Widget provides a structured system for embedding YouTube playlists and podcast audio on WordPress pages using shortcodes. Key capabilities:

- **YouTube** — Fetches playlist items from the YouTube Data API v3, caches results server-side (transients) and client-side (localStorage), and renders clickable thumbnails with a built-in lightbox video player.
- **Podcasts** — Parses RSS feeds from direct URLs or via Apple/iTunes lookup for Omny, SoundCloud, Buzzsprout, and others. Renders thumbnail-based items that open an embedded audio player.
- **Custom Podcast Player** — A self-hosted, fully themed podcast player served at `/podcast/player` and embeddable via iframe.
- **Grid or Carousel Collections** — Show many episodes as a responsive grid or, since 6.1.0, as a carousel that sizes its cards from its container and supports arrows, touch swiping, trackpad scrolling, mouse dragging and the keyboard. See [Carousel Layout](#carousel-layout).
- **Playlist Items as WordPress Posts** — Optionally, per playlist, keep every item as a WordPress post with its title, description, image, season, episode and IDs, so Elementor Pro's Loop Grid and other builders can list them with their own templates. See [Stored Playlist Posts](#stored-playlist-posts-loop-grid--page-builders).
- **Global Shortcode Fields** — Store key/value pairs in the admin and reference them in any shortcode attribute using `{{field_name}}` syntax.
- **SEO** — Automatically injects Open Graph and Twitter Card meta tags derived from the media content on each page.
- **API Stats** — Tracks every external API call in a database table with 24-hour reporting, including when each playlist's fallback backup was last stored and a direct download link for it.

---

## Installation

1. Upload the `media-api-widget` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Navigate to **Media API** in the WordPress admin sidebar (it opens **Playlist Settings**).
4. Add your YouTube playlists and/or podcast feeds under **Media Items**.
5. Place shortcodes on any page or post.

> After activation, visit **Settings → Permalinks** and click **Save Changes** to flush rewrite rules if the podcast player route (`/podcast/player`) does not resolve.

---

## Admin Panel

Access the plugin settings at **WordPress Admin → Media API**. The menu lists, in order:

| Entry | Shown | Opens |
|---|---|---|
| **Playlist Settings** | Always (also where **Media API** itself goes) | The Media Items and Shortcode Fields settings. |
| **All Media Items** | Once a playlist stores its items as posts | Every stored media item post. See [Stored Playlist Posts](#stored-playlist-posts-loop-grid--page-builders). |
| **Media API - {playlist_name}** | One per playlist with stored posts | That playlist's posts, in playlist order. |
| **API Stats**, **Caching**, **About / Help** | Always | See below. |

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
| **Store playlist items as WordPress posts** | Off by default. Keeps one post per video for Loop Grids and other builders. See [Stored Playlist Posts](#stored-playlist-posts-loop-grid--page-builders). |

#### Podcast-specific fields

| Field | Description |
|---|---|
| **Platform** | See [Podcast Platform Support](#podcast-platform-support). |
| **RSS URL / ID / Embed URL** | Depends on the platform selected (see below). |
| **Season/episode regex** | Off by default. When checked, a pattern numbers each episode from its title: named groups `season` and `episode` are used when present; otherwise two groups are season then episode, and one group is the episode. Matching ignores case. A title the pattern does not match is left unnumbered — numbers are never guessed. |
| **Sort mode** | Shown with the regex. *Season / episode (newest first)* (default) orders numbered episodes by season, then episode, newest first, with unnumbered episodes after them in feed order. *Feed order* keeps the feed's order and only adds the numbers. This is the order of the plugin's podcast cards and grids, and of stored posts; the `/podcast/player` page reads the feed itself and keeps the feed's order. |
| **Store playlist items as WordPress posts** | Off by default. Keeps one post per episode. See [Stored Playlist Posts](#stored-playlist-posts-loop-grid--page-builders). |

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

**Carousel of the latest 12 episodes:**
```
[media-api-widget-render playlist_name="my_show" media_platform="youtube" multiplegrid="true" multiplegridlayout="carousel" multiplegridlimititems="12" multiplegridminsize="300px" multiplegridgap="24px"]
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

Enable grid mode to display multiple media items in a responsive CSS grid — or, with `multiplegridlayout="carousel"`, the same items in a [carousel](#carousel-layout).

| Attribute | Default | Description |
|---|---|---|
| `multiplegrid` | `false` | Set to `true` to render all (filtered) playlist items in a grid. |
| `multiplegridlayout` | `grid` | `grid` or `carousel`. Applies to both `multiplegrid="true"` and `multiplegridusersearch="true"`. Any other value keeps the grid. See [Carousel Layout](#carousel-layout). |
| `multiplegridshowall` | `false` | When `true`, disables all filtering — shows every item. |
| `multiplegridsearch` | _(none)_ | Filter grid items to those whose title contains this keyword. |
| `multiplegridlimititems` | _(none)_ | Limit the grid to this number of items. |
| `multiplegridepisoderange` | _(none)_ | Show only items whose episode number falls in a range. Format: `1-10`. Overrides search and limit. |
| `multiplegridgap` | `48px` | CSS `gap` value for the grid. In a carousel, the space between cards. |
| `multiplegridminsize` | `400px` | Minimum column width in the `auto-fill` grid. In a carousel, the minimum card width (see [Responsive sizing](#responsive-sizing)). |
| `multiplegridtext` | _(none)_ | Show text below each grid item: `title`, `description`, `both`, `numberedtitle`, or `numberedtitleanddescription`. YouTube only. The `numberedtitle` options require the playlist's sort mode to be "Number in title": they display `Episode N` (or `Season S Episode N` when the season/episode regex is set), falling back to the plain title for items without a detected number. `numberedtitleanddescription` also appends the description, like `both`. |
| `multiplegridusersearch` | `false` | Set to `true` to enable the user-searchable + paginated grid mode. See [Grid Search & Pagination](#grid-search--pagination). |
| `multiplegridperpage` | `12` | Max items per page when `multiplegridusersearch="true"`. |
| `multiplegridmaxpagedisplay` | _(none)_ | Cap how many numbered page buttons show at once (a sliding window centred on the current page). A clickable `…` on each side jumps one set (this many pages), keeping all pages reachable. Omit/empty to list every page number with no `…`. |
| `noresults` | _(none)_ | Text shown when a user search yields no results (user-search grid only). Falls back to a generic message when empty. |
| `nostyling` | `false` | Set to `true` to suppress the plugin's **styling** class names (`media_item`, `media-item-*`, `media_items_multiple_grid_layout`, `media-carousel*`, …) so you don't inherit the plugin's built-in CSS. The namespaced `maw-` **hook classes** (see [Styling Hooks](#styling-hooks)) are still emitted either way. Data attributes used by the lightbox JS are unaffected. For carousels, see [`nostyling` and carousels](#nostyling-and-carousels). |

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

Add `multiplegridlayout="carousel"` to show each result page as a carousel — see [Searchable carousels](#searchable-carousels).

---

### Carousel Layout

`multiplegridlayout="carousel"` shows the same media cards a grid would — same filters, same card markup, same playback — in one horizontally scrolling row. Cards are sized from the carousel's own width, so it adapts to Elementor columns, sidebars and phones alike. It needs no Elementor runtime and works on any page.

**Enable it**

- **Shortcode:** add `multiplegridlayout="carousel"` to a `[media-api-widget-render]` (or `[media-api-widget-item]`) shortcode that has `multiplegrid="true"` or `multiplegridusersearch="true"`.
- **Elementor (Classic and Atomic widgets):** set **Output** to *Grid or carousel* or *Searchable grid or carousel with pagination*, then **Grid & Carousel → Layout** to *Carousel*. Arrow settings are in **Carousel Navigation**. See [Carousel controls](#carousel-controls).

The default is `grid`, so existing content is unchanged. On a single card, title text or description text output, the layout has no effect.

**Attributes**

| Attribute | Default | Description |
|---|---|---|
| `multiplegridlayout` | `grid` | `carousel` to enable the carousel. |
| `multiplegridminsize` | `400px` | Minimum card width. Shared with the grid. |
| `multiplegridgap` | `48px` | Space between cards. Shared with the grid. |
| `carouselstep` | `item` | How far one arrow click moves: `item` (one card) or `group` (as many cards as are currently visible). Any other value means `item`. |
| `carouselarrowleftimgurl` | _(built-in arrow)_ | Image URL for the left arrow. |
| `carouselarrowrightimgurl` | _(built-in arrow)_ | Image URL for the right arrow. Each direction falls back to the built-in arrow independently. |
| `carouselarrowsize` | `48px` | Width and height of each arrow button. |
| `carouselarrowiconsize` | `24px` | Width and height of the arrow icon or image inside the button. |
| `carouselarrowcolor` | `#ffffff` | Arrow (icon) color for the built-in arrow. |
| `carouselarrowbackground` | `rgba(0, 0, 0, 0.6)` | Arrow button background. `transparent` suits custom arrow images. |
| `carouselarrowoffset` | `12px` | Space between an arrow and the cards. When the arrows overlay the cards (narrow containers), the inset from the edge. |

Every other collection attribute keeps working unchanged: `multiplegridtext`, `multiplegridsearch`, `multiplegridlimititems`, `multiplegridepisoderange`, `multiplegridshowall`, the search and pagination attributes, `noresults`, every card, overlay, play button, thumbnail, lightbox and podcast player attribute, `showlightbox`, `nostyling`, `{{field}}` references, and stored fields named after an attribute acting as its default (for example a stored field `multiplegridlayout` = `carousel` makes carousels the site default).

**Value formats.** Lengths accept a non-negative number with `px`, `rem`, `em`, `%`, `vw`, `vh` and other CSS length units, a bare number (read as pixels), or a `calc()`, `min()`, `max()` or `clamp()` expression. Percentages are relative to the carousel's width. Colors accept hex (with or without `#`), `rgb()`/`rgba()`/`hsl()`/`hsla()`, a named color such as `transparent`, or a CSS variable such as `var(--e-global-color-primary)`. An invalid or unusable value — a negative length, a zero minimum width or arrow size, anything that is not a length or color — uses that attribute's default.

#### Responsive sizing

The carousel measures the width actually available to it — not the browser window — and recalculates whenever that width changes (window or container resizes, Elementor column changes, a hidden tab or accordion becoming visible, device rotation).

With **W** the available width, **M** the minimum card width and **G** the gap:

```
visible cards = max(1, floor((W + G) / (M + G)))   — never more than the number of cards
card width    = (W − (visible − 1) × G) / visible
```

For example, with `multiplegridminsize="300px"` and `multiplegridgap="24px"`:

| Available width | Visible cards | Card width |
|---|---|---|
| 1,000px | 3 | 317.3px |
| 650px | 2 | 313px |
| 600px | 1 | 600px (the full width) |

When two cards cannot fit, the single card fills the full width — even if that is narrower than the minimum — so a narrow column never overflows. With fewer cards than fit, the cards share the width evenly (two cards in a wide container are each half the width). When the container resizes, the carousel keeps the same first card in view where it can, moving back only as far as needed to stay at a valid position.

**Arrow placement.** When every card fits, there are no arrows and nothing scrolls. Otherwise the arrows sit in gutters on the left and right of the cards (button size + offset each), vertically centred on the row of cards (including any text below them) — as long as at least two cards still fit beside them. In narrower containers the gutters would cost the cards space, so the arrows overlay the cards' edges instead, inset by the offset, and the cards use the full width. **W** is the width left for the cards after any gutters. The row never causes horizontal page scrolling, and a carousel placed in a shrink-to-fit container (for example an auto-width flex item) never widens it.

#### Navigation and touch

- **Arrows** advance one card per click (`carouselstep="group"`: the number of cards currently visible). They stop at the first and last positions, and the arrow for an unavailable direction is disabled. The last position puts the final card flush with the right edge — there is never blank space after it.
- **Touch:** swipe horizontally on phones and tablets; vertical swipes still scroll the page.
- **Trackpads and mice:** two-finger horizontal scrolling and horizontal wheels scroll the row (Shift + wheel also works where the operating system or browser converts it). With a mouse, **click and drag** the row. Every scroll settles on a card edge.
- A swipe or drag never opens a lightbox or fires `mediaApiWidgetItemClick`; a plain click or tap on a card plays it exactly as in a grid. Arrow clicks never trigger card events.
- **Keyboard:** the arrows are buttons labelled "Previous item"/"Next item" ("…items" for group steps) with a visible focus ring, operated with Enter or Space; a disabled arrow keeps focus. The row itself is a focus stop when it scrolls: ←/→ move one card, Page Up/Page Down one visible group, Home/End to the first/last position. When keyboard focus moves onto content inside a card that is offscreen (for example a focusable element added by your theme), the carousel scrolls that whole card into view.
- **Reduced motion:** with the operating system's reduce-motion setting on, arrow and keyboard moves jump without animation.
- **Right-to-left pages:** the carousel starts at the right; the right arrow is "previous" and the left arrow "next".
- Each carousel is independent, including several carousels of the same playlist on one page. Cards are never cloned.

#### Searchable carousels

With `multiplegridusersearch="true"`, each result page is shown as a carousel. The two controls do different things:

- **Carousel arrows** (and swiping) move through the cards of the **currently loaded result page** only. They never request data.
- **Pagination** (Prev / page numbers / Next) loads **another result page** from the server, exactly as for a grid, including `maw_page_*` URLs, browser back/forward, crawlable links and `multiplegridmaxpages` / `multiplegridmaxpagedisplay`.

A new search or result page replaces the carousel, and the new one starts at its first card. Each carousel's settings are stored with its search configuration, so AJAX results keep the same layout and arrow settings; two collections that differ only in layout or arrow settings never share stored settings. A search with no results shows the `noresults` message without carousel controls.

```
[media-api-widget-grid-search playlist_name="my_show" media_platform="youtube" placeholder="Search episodes..."]

[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegridusersearch="true"
    multiplegridlayout="carousel"
    multiplegridperpage="9"
    multiplegridminsize="300px"
    multiplegridgap="24px"
    noresults="No episodes matched your search."
]
```

#### `nostyling` and carousels

`nostyling="true"` removes the plugin's **appearance** styles: the cards are unstyled exactly as in a `nostyling` grid, and the arrow buttons lose their round shape, colors and hover effect (`carouselarrowcolor` and `carouselarrowbackground` therefore have no effect; their values stay available as the `--maw-carousel-arrow-color` / `--maw-carousel-arrow-background` CSS variables). The carousel's **functional layout** is kept, because it cannot work without it: the scrolling row and snapping, card sizing, arrow placement and size (`carouselarrowsize`, `carouselarrowiconsize`, `carouselarrowoffset`), hidden and disabled arrow states, and focus rings. Style the [hook classes](#styling-hooks) — for example `.maw-carousel-arrow` and `.maw-carousel-arrow[aria-disabled="true"]` — to design your own arrows.

#### Limitations

- `multiplegridtext` (text below cards) remains YouTube-only, as in the grid.
- The carousel applies to collections rendered by the shortcodes and Elementor widgets. The legacy hand-written `data-playlistname` placeholder markup (rendered client-side) has no carousel layout.
- Media cards are not keyboard-focusable in either layout (an existing limitation of the card markup); keyboard users navigate with the arrows and the row.
- Card effects that extend outside a card (a scale-up on hover, a shadow) are clipped by the scrolling row; give `.maw-carousel-viewport` some padding if you add them.
- Container-based sizing uses `ResizeObserver` and CSS containment (all current browsers). Without JavaScript the row still scrolls natively, with cards at the minimum width and no arrows.

#### Examples

**Basic carousel:**
```
[media-api-widget-render
    playlist_name="my_show"
    media_platform="youtube"
    multiplegrid="true"
    multiplegridlayout="carousel"
    multiplegridlimititems="12"
    multiplegridminsize="300px"
    multiplegridgap="24px"
    multiplegridtext="title"
]
```

**Custom arrows, moving one visible group per click:**
```
[media-api-widget-render
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
]
```

**Podcast episodes with a brand-colored arrow from a stored field:**
```
[media-api-widget-render
    playlist_name="my_podcast"
    media_platform="podcast"
    multiplegrid="true"
    multiplegridlayout="carousel"
    thumbnail="https://example.com/cover.jpg"
    carouselarrowbackground="{{brand_color}}"
]
```

The searchable example is under [Searchable carousels](#searchable-carousels).

**JavaScript API.** The front-end script exposes `window.mawCarousel` for code that inserts carousel markup after page load: `init(root)` initializes carousels inside `root` (already initialized ones are skipped), `destroy(root)` releases their listeners and observers, and `refresh(root)` re-measures them. Grid search and the Elementor widgets already call these; most sites never need them.

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
| Carousel container / scrolling row / track / slide | `maw-carousel` / `maw-carousel-viewport` / `maw-carousel-track` / `maw-carousel-slide` |
| Carousel arrow button | `maw-carousel-arrow` ＋ `maw-carousel-arrow-left` / `maw-carousel-arrow-right` (state: `[aria-disabled="true"]`) |
| Carousel arrow icon or image | `maw-carousel-arrow-icon` |

The carousel's functional layout is attached to `data-maw-carousel*` attributes rather than to these classes, so the hook classes stay free of plugin CSS; the arrow appearance uses the `media-carousel` / `media-carousel-arrow` styling classes, which `nostyling` omits. The script also sets `data-maw-carousel-mode` (`static`, `gutter` or `overlay`) on the container, usable in your own selectors.

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
| `carousel` | That the grid stays the default and unknown layouts fall back to it; that carousel cards are byte-identical to the grid's cards for the same filters, text, card, lightbox and podcast attributes, in the static, initial searchable and AJAX paths; zero, one and many items; length and color validation with safe defaults; arrow images, step and labels; `nostyling`; stored-field references and defaults; that a searchable carousel stores its validated configuration, includes it in its grid key, and keeps it across search and pagination; and that Classic and Atomic widget settings render byte-identically to the shortcode, with hidden carousel controls never reaching the renderer. |
| `backup-inventory` | That the API Stats backup columns resolve the same file the media pipeline writes, report `time_stored` (falling back to the file time), keep YouTube and podcast backups separate, and report nothing for a missing file, an unsupported media type, or an empty slug. |
| `media-post-index` | The stored-post content model: post type, taxonomies and public meta registration; identity (video ID, podcast GUID with fallbacks, CDATA and attribute-carrying GUIDs) and field mapping; idempotent upserts that never duplicate; transcript and enrichment preservation; deduplicated scheduling; the backfill; and `media_api_widget_rebuild_post_index()`. |
| `post-storage` | Per-playlist post storage: off by default and isolated per playlist; initial population without removals; in-place updates that keep post IDs, clear vanished values and keep editorial status and enrichment; bounded, deduplicated background batches; removal only after a complete refresh — never after failed, partial or malformed refreshes, the six-item limit, a cache miss, an enrichment write, unidentifiable items, partial write failures, or a newer refresh mid-pass; retries and their limit; lock contention; stopping on disable or removal while keeping posts; capability and per-playlist nonce checks on Resync and Delete; the ID and other builder fields; the Click Action link; podcast season/episode numbering and order; loop ordering and Post Types Order; REST nulls; the read-only edit screen; and the admin menu order and highlighting. |
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
| `startepisode` | A GUID string. Starts on that episode and keeps the episode list. Ignored when `singleepisode` is set. |

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

**Fires:** On any click of a media item rendered by the plugin, and of any element linked with the Elementor [Media API Click Action](#media-api-click-action) tag. Fires even when `showlightbox="false"` (or the tag's *Click event only*) is set and the lightbox does not open.

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
| `element` | `Element` | The `<a>` DOM node that was clicked. For a [Media API Click Action](#media-api-click-action) link, `element.dataset.postid` is the stored post's ID. |

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

A listener that opens its own player should check `showLightbox`, as below; otherwise it opens on top of the plugin's lightbox for every card that has one.

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
| Grid or carousel | `[media-api-widget-render multiplegrid="true"]` (with `multiplegridlayout="carousel"` when **Layout** is *Carousel*) |
| Searchable grid or carousel with pagination | `[media-api-widget-render multiplegridusersearch="true"]` (likewise), plus `[media-api-widget-grid-search]` when **Show search bar above grid** is on |
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

### Carousel controls

Both widgets expose the [carousel](#carousel-layout) through the same shared settings, so equal settings render exactly what the equivalent shortcode renders.

| Panel section | Control | Shortcode attribute |
|---|---|---|
| **Grid & Carousel** | Layout — *Default (Grid)*, *Grid*, *Carousel*, or a stored field | `multiplegridlayout` |
| **Grid & Carousel** | Gap / Minimum item width | `multiplegridgap` / `multiplegridminsize` |
| **Carousel Navigation** | Arrow step — *One item* or *Visible group* | `carouselstep` |
| **Carousel Navigation** | Left arrow image / Right arrow image | `carouselarrowleftimgurl` / `carouselarrowrightimgurl` |
| **Carousel Navigation** | Arrow button size, Arrow icon size, Arrow color, Arrow background, Arrow offset | `carouselarrowsize`, `carouselarrowiconsize`, `carouselarrowcolor`, `carouselarrowbackground`, `carouselarrowoffset` |

- The Layout control appears for the *Grid or carousel* and *Searchable grid or carousel with pagination* outputs. **Carousel Navigation** appears once Layout is *Carousel* (or a stored field, which may resolve to carousel); while Layout is *Default* or *Grid* its values are never passed to the renderer.
- Arrow images use Elementor's media library picker in the Classic widget. The Atomic widget takes an image URL, the same way its other image settings do.
- Colors take a color picker (Classic, with transparency) or a CSS color value (Atomic).
- Every control offers *Default*, a value, and stored fields, exactly like the other settings.

### Editor behavior

- Widgets render in the classic and atomic editors through the same shortcode callbacks as on the front end. Search and pagination work in the editor preview, including after control changes, duplication, and removal.
- Carousels work in the editor preview too (arrows, dragging, resizing columns, switching device modes). Each re-render starts a fresh carousel at its first card; the replaced one's listeners and resize observer are released, as they are when a widget is removed.
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

## Stored Playlist Posts (Loop Grid & Page Builders)

Optionally, per playlist, the plugin keeps every item of a playlist as a WordPress post of the `maw_media_item` type, synchronized with the playlist. Elementor Pro's **Loop Grid**, other page builders, `WP_Query` and the REST API can then list YouTube videos and podcast episodes like any other posts, with your own templates. The plugin's shortcodes, widgets and players do not use these posts and work exactly as before.

Post storage is **off for every playlist** until you turn it on.

### Enabling post storage

1. Go to **Media API → Playlist Settings**, check **Store playlist items as WordPress posts** on a playlist, and click **Save**.
2. An initial population is queued. It runs in the background through WP-Cron, in batches, from the playlist data already stored — no extra API request is made. A status panel under the checkbox shows its progress.
3. **All Media Items** and **Media API - {playlist_name}** appear in the Media API menu.

> **Storing a full playlist as WordPress posts can create many database records.** Each item becomes one post with about fifteen custom fields. Initial imports and refreshes may take time. Synchronization runs in the background; changed items are updated in place and removed source items are deleted after a successful complete refresh.

WP-Cron runs when the site receives traffic. On a low-traffic site, or to make imports predictable, trigger `wp-cron.php` from a real server cron job.

#### Status and actions

The status panel on each playlist row shows the state (*Pending*, *Running*, *Complete*, *Error*, *Off*), the number of stored posts, the last successful synchronization, the last result, and — when nothing was removed — why. Its actions require the `manage_options` capability and a nonce bound to the action and the playlist:

| Action | Effect |
|---|---|
| **Resync now** | Queues a full synchronization from the stored data, starting from the first item. |
| **View posts** | Opens **Media API - {playlist_name}**. |
| **Delete stored posts** | After a confirmation, permanently deletes this playlist's generated posts (up to 500 per click). While storage is on, the next synchronization recreates them. |

#### Admin views

**Media API - {playlist_name}** lists that playlist's posts in playlist order, with Image, Season, Episode and Position columns and Playlist and Media type filters. **All Media Items** lists every playlist. Clicking an item (**View details**) opens a read-only screen showing every stored field with the key builders read. The title cannot be edited and the synchronized fields cannot be changed there, even by a modified form. The post **status** stays editable: set an item to **Draft** to hide it from loops (synchronization never republishes it), or move it to the **Trash** (synchronization leaves trashed items alone). New items cannot be added by hand.

### Stored fields

| Key | Label | Value |
|---|---|---|
| `post_title` | Title | The item title. |
| `post_excerpt` | Description | The video or episode description as plain text, line breaks kept. For podcast episodes with an empty `<description>`, `itunes:summary` or `content:encoded` is used. |
| `maw_source_id` | ID | The YouTube video ID, or the podcast episode ID (its GUID). For an episode without a GUID: its audio URL, page URL, or a content hash. |
| `maw_season` | Season | The season number. **Absent** when it cannot be determined. |
| `maw_episode` | Episode | The episode number. **Absent** when it cannot be determined. |
| `maw_playlist_position` | Playlist position | 0-based position in the playlist order; also the post's `menu_order`. |
| `maw_playlist_name` | Playlist | The `playlist_name`. Also assigned as a term of the `maw_playlist` taxonomy. |
| `maw_media_type` | Media type | `youtube` or `podcast`. Also a term of the `maw_media_type` taxonomy. |
| `maw_thumbnail_url` | Image URL | The remote thumbnail URL. Nothing is imported into the Media Library. |
| `maw_media_url` | Media URL | The YouTube watch URL, or the episode's page (its audio file when it has no page). |
| `maw_audio_url` | Audio URL | The episode's audio file. Absent for YouTube. |
| `maw_youtube_id` | YouTube video ID | Case-sensitive. Absent for podcasts. |
| `maw_podcast_guid` | Podcast GUID | Absent for YouTube. |
| `maw_published_date` | Published date | ISO 8601 when parseable. |
| `maw_transcript_status`, `maw_transcript_url`, `maw_transcript_language`, `post_content` | Transcript fields | Written only by an integration through `media_api_widget_update_stored_data()`; never overwritten by a synchronization. |
| `maw_last_seen` | Last seen | Unix time of the last synchronization that saw the item. |

These are registered post meta (`show_in_rest`), so they are available to builders, `get_post_meta()`, and the REST API at `/wp-json/wp/v2/maw-media-items`, where an unknown season or episode is `null`. The Elementor **Media API Field** tag also offers three formatted fields: `episode_label` (*Season 2 Episode 5* or *Episode 5*), `position_number` (1-based) and `published_date_formatted` (the site's date format). Internal keys (`_maw_source_key`, `_maw_source_payload`, `_maw_managed`) are hidden from the Custom Fields box and REST.

#### Season and episode numbers

Numbers come from the playlist's settings and are left blank when they cannot be determined: for YouTube, the **Number in title** sort mode with its season/episode regex; for podcasts, the **Season/episode regex** described in [Settings — Media Items](#podcast-specific-fields). Without numbers, posts still carry the playlist's own order.

#### Loop order

Posts are stored in playlist order: newest season and episode first for numbered playlists, otherwise the source's order. Front-end queries for media items that only ask for the default date order — as Elementor's Loop Grid does by default — list them in that order (`menu_order` ascending, then date). Any explicit order, such as Title, Random, a custom field, or *Date, ascending*, is used as chosen. Return `false` from the `media_api_widget_post_index_default_order` filter to keep plain date ordering. With the Post Types Order plugin's auto-sort on, explicit orders for media items are still honored, and its **Re-Order** page is not offered for them because synchronization sets the order.

### Elementor Pro Loop Grid

1. **Create a loop item template:** *Templates → Theme Builder → Loop Item*, or **Create Template** from a Loop Grid. In the template's settings, set **Preview as** to a *Media Item* so the editor shows real values.
2. **Bind widgets with the Media API dynamic tags** — they are grouped under **Media API** in the dynamic tags list, next to ACF:

| Tag | Controls | Shows |
|---|---|---|
| **Media API Field** | Text, number, custom-field controls (Heading, Text Editor, …) | Any stored field, picked from a list: Title, Description, ID, Season, Episode, the season & episode label, dates, positions, playlist, media type, URLs, IDs and transcript fields. |
| **Media API Image** | Image controls (Image widget, backgrounds) | The remote thumbnail. Optional fallback image. |
| **Media API URL** | Link controls | The media URL (default), audio URL, image URL or transcript URL. Plain navigation. |
| **Media API Click Action** | Link controls | Makes the element act like the item's media card. See [below](#media-api-click-action). |

   Elementor's own tags work too: *Post Title*, *Post Excerpt*, and *Post Custom Field* with a custom key such as `maw_season`.
3. **Add a Loop Grid** to a page and set **Query → Source** to *Media API Items*. To show one playlist, set **Include By → Term** to *Playlists: {playlist_name}*. Leave the order at its default for playlist order.

The thumbnail is not a WordPress featured image, so *Featured Image* tags and widgets do not see it; use **Media API Image**. Media items have no front-end page of their own, so do not link cards to the *Post URL*; use **Media API URL** or **Media API Click Action**.

### Media API Click Action

A dynamic tag for any link control — Button, Image, Heading, Icon, a Container's link — inside a loop item. A click dispatches [`mediaApiWidgetItemClick`](#mediaapiwidgetitemclick) with the same details as a card click, then:

| On click | YouTube | Podcast |
|---|---|---|
| **Open lightbox (this item only)** (default) | The lightbox plays that video. | The player opens on that episode, without the episode list. |
| **Open lightbox with the playlist** | The lightbox opens at that video with the playlist panel. Optional border color, font and logo URL. | The player opens on that episode with the episode list. |
| **Click event only (no lightbox)** | Nothing else; `showLightbox` is `false`. For your own listener. | Same. |

- The link is a `#maw-media-…` fragment, so it never navigates and is ignored by anchor-scrolling scripts.
- `e.detail.element.dataset.postid` is the stored post's ID.
- If a video is not in the playlist data on the page — for example beyond the first six items of a playlist that does not load in full — the lightbox plays that video on its own.
- Clicking in the Elementor editor selects the element and plays nothing.
- The playlist must still be configured, because its player is what opens. For a playlist removed from the settings whose posts were kept, the click still dispatches the event, with `showLightbox` `false`.
- Podcasts open the player of the playlist's platform. `custom` feeds use the [`/podcast/player`](#podcast-player-podcastplayer) page (`singleepisode` / `startepisode`); Omny and Buzzsprout open the clicked episode; SoundCloud opens at its default track, as podcast cards do.

#### Other builders, `WP_Query` and REST

Any tool that queries posts can use the `maw_media_item` post type, the `maw_playlist` taxonomy and the meta keys above:

```php
$items = new WP_Query([
    'post_type'      => 'maw_media_item',
    'posts_per_page' => 12,
    'tax_query'      => [[
        'taxonomy' => 'maw_playlist',
        'field'    => 'slug',
        'terms'    => 'my_show',
    ]],
]);

while ($items->have_posts()) {
    $items->the_post();
    $season = get_post_meta(get_the_ID(), 'maw_season', true); // '' when unknown
}
wp_reset_postdata();
```

### When posts are updated and deleted

Synchronization is queued whenever the playlist's data is stored — a refresh, a shortcode warm-up, or an enrichment write — and runs in the background through the `maw_sync_post_index` WP-Cron event, with one queued event per playlist and a lock so two runs never overlap. It writes 50 items per batch (filter `media_api_widget_post_index_batch_size`, 1–500), each batch on the next WP-Cron run a few seconds later. If items fail to save or to be removed, it retries after 5 minutes, up to 5 times; **Resync now** starts over. If newer data arrives between batches, the run starts over on the new data. The `media_api_widget_post_index_synced` action fires after each completed run with the counts, for purging page caches.

**Identity.** An item is matched to its post by the YouTube video ID or the podcast GUID (then audio URL, page URL, or a content hash), within its playlist; the same video in two playlists has two posts.

**Updates.** Changed titles, descriptions, images, numbers, positions, dates and URLs are rewritten in place; values that disappear from the source are cleared; post IDs never change. The post content (transcripts), post status, transcript fields and any other custom fields are never touched. An item an editor trashed stays in the Trash.

**Removal.** A post is deleted — permanently, bypassing the Trash — only when a **complete, validated refresh** no longer contains its item. A refresh counts only when every page was fetched (YouTube) or the whole feed was parsed (podcast) and its data was stored. Then:

| Situation | Posts removed? |
|---|---|
| The item is missing from a complete refresh | Yes, once per refresh. |
| A valid playlist that is now empty | Yes, all of them. |
| A failed, partial (an error part-way through pagination, the page ceiling, the daily limit) or malformed refresh | No. Nothing is recorded. |
| **Load full playlist** off (the six-item limit) | No. Every page is still fetched and the refresh lists every item; only the six-item player data is trimmed. Items beyond six are removed only when they leave the playlist. |
| An expired cache or missing backup | No. |
| An enrichment write (`media_api_widget_update_stored_data()`) | No. |
| An item without a usable identity in the refresh or the stored data | No, for that run. |
| Some posts failed to save | No, until a clean run. |
| A newer refresh arrived during the run | Not in that run; the next run decides. |
| The first run after storage is turned on | No. Removal starts with the next complete refresh. |

Only posts the plugin generated for that playlist can be removed; a post of another type, another playlist, or one made by hand is never touched. The status panel explains why nothing was removed.

### Turning storage off and retention

- **Unchecking** the setting stops synchronization at once, including queued and in-progress batches, and **keeps the posts**: they stay listed, published and visible to loops. Hide them by setting them to Draft, or remove them with **Delete stored posts**. Turning storage back on starts with a fresh initial population.
- **Removing a playlist** from the settings does the same. Its posts and its **Media API - {playlist_name}** view remain while posts exist.
- **Deactivating the plugin** leaves the posts in the database, unregistered and invisible; reactivating restores them. Delete them first with **Delete stored posts** if they should go.

#### Performance and limitations

- A full playlist creates one post per item; imports of hundreds of items take several minutes of background batches.
- Media items have no front-end single page, archive or search results.
- Images are remote URLs: no Media Library entries, featured images, or generated image sizes.
- Synchronization depends on WP-Cron.
- Only YouTube and podcast playlists can store posts. Embed-only podcasts (`embed` platform) have no items to store.
- Synchronized fields cannot be edited in WordPress; the next synchronization would overwrite them anyway.

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
- The [carousel layout](#carousel-layout) added in 6.1.0 is opt-in: `multiplegridlayout` defaults to `grid`, and without `multiplegridlayout="carousel"` every grid — static, searchable and AJAX — renders byte-identically to 6.0.0, with the same grid keys and stored settings. Nothing changes for single cards, text output, the podcast player, or the search bar. Changes visible to existing installs:
  - New shortcode attributes `multiplegridlayout`, `carouselstep`, `carouselarrowleftimgurl`, `carouselarrowrightimgurl`, `carouselarrowsize`, `carouselarrowiconsize`, `carouselarrowcolor`, `carouselarrowbackground` and `carouselarrowoffset`. A stored field with one of these names acts as its site-wide default, as for every attribute, so check for existing fields with those names before upgrading.
  - A searchable carousel stores a `carousel` entry with its search settings and includes it in its grid key; grids store and hash exactly as before.
  - The Elementor output modes are relabelled *Grid or carousel* and *Searchable grid or carousel with pagination*, the **Grid** section is now **Grid & Carousel** (with **Minimum column width** renamed **Minimum item width**), and a **Carousel Navigation** section is added. Stored values and existing widgets are unchanged.
  - The front-end script adds `window.mawCarousel`; asset URLs carry `?ver=6.1.0`, so caches pick up the new CSS and JavaScript.
- [Stored playlist posts](#stored-playlist-posts-loop-grid--page-builders), added in 6.1.0, are opt-in per playlist: until a playlist turns on **Store playlist items as WordPress posts**, no post is created, no synchronization is scheduled, no menu entry appears, and Elementor's Loop Grid does not list the post type. Changes visible to existing installs:
  - The `maw_media_item` post type and the `maw_playlist` and `maw_media_type` taxonomies are registered on every site. They have no front-end URLs, are excluded from search, and stay out of menus until used.
  - The first entry of the **Media API** menu is labelled **Playlist Settings**; it is the same page **Media API** always opened. When posts are stored, **All Media Items** and the per-playlist views are listed in this menu rather than a separate one.
  - Podcast feeds are parsed with CDATA merged into text, and attributes are dropped from each episode's `guid`, `title`, `description`, `link` and `pubDate` before storage. Episodes whose GUID carried `isPermaLink`, or whose text was in CDATA, previously stored those values as empty or as attribute arrays; they now store the text. An empty `<description>` is filled from `itunes:summary` or `content:encoded`. Podcast cards may therefore show descriptions and pass GUIDs (`itemId`) where they were empty before.
  - The podcast **Season/episode regex** and **Sort mode** are new settings, off until checked; with them off, stored podcast data is unchanged.
  - The `/podcast/player` page accepts `startepisode`. The front-end script handles `#maw-media-…` links and, for those links only, plays a video missing from the page's playlist data on its own.
  - New options are created only for playlists that store posts (`maw_post_index_status_*`, `maw_post_index_manifest_*`, `maw_post_index_job_*`, `maw_post_index_lock_*`, `maw_post_index_has_posts`), and the `maw_sync_post_index` WP-Cron event only for them. The one exception is `maw_media_post_index_schema`, a small version marker written once on every site the first time wp-admin loads.
- The internal `MediaContent::backupDir()` and `BackupInventory` path methods still exist and still return the same paths in 5.0.0; they now delegate to a shared `Support\BackupFiles` class so the storage service, the fetch pipeline, and the Stats page cannot disagree about where a backup lives.
