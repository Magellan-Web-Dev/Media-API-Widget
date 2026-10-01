<?php
namespace MediaApiWidget\Integrations\Elementor;

if (!defined('ABSPATH')) { exit; }

/**
 * Single definition of the Elementor widgets' settings.
 *
 * Both the classic and the atomic widget build their controls from this
 * schema, and {@see SettingsAdapter} evaluates the very same visibility rules
 * on the server. That shared source is what guarantees a value hidden in the
 * editor can never reach the renderer, whichever editor saved it.
 *
 * Two kinds of setting are described:
 *
 * - Structural controls choose the output mode, the media source, and the
 *   integration-only options (item selection style, search-bar linking).
 * - Attribute specs map one-to-one onto an existing shortcode attribute. Each
 *   one is stored as a *source* — `inherit`, a literal, `empty`, or
 *   `field:{name}` — so the widget can express every state a shortcode can:
 *   attribute omitted (global default applies), attribute set, attribute set to
 *   an empty string, or attribute set to a `{{field_name}}` reference.
 *   Free-form kinds (text, color, number, image) add a separate `{key}_value`
 *   control shown only while the source is `value`.
 *
 * Visibility rules ("when") are a flat list of `[setting, operator, value]`
 * leaves that must all hold. They are deliberately kept to a conjunction of
 * simple terms: that is the one shape both the classic `conditions` engine and
 * the atomic dependency manager can express *and hide* natively.
 */
final class WidgetSchema
{
    public const MODE_ITEM              = 'item';
    public const MODE_GRID              = 'grid';
    public const MODE_SEARCH_GRID       = 'search_grid';
    public const MODE_SEARCH_BAR        = 'search_bar';
    public const MODE_TITLE             = 'title';
    public const MODE_DESCRIPTION       = 'description';
    public const MODE_DESCRIPTION_TITLE = 'description_title';
    public const MODE_PODCAST_PLAYER    = 'podcast_player';
    public const MODE_FIELD             = 'field';

    /** Modes that pick one item from the playlist. */
    public const SELECT_MODES = [self::MODE_ITEM, self::MODE_TITLE, self::MODE_DESCRIPTION, self::MODE_DESCRIPTION_TITLE];

    /** Modes that output clickable media cards. */
    public const CARD_MODES = [self::MODE_ITEM, self::MODE_GRID, self::MODE_SEARCH_GRID];

    /** Modes that output a title/description `<p>` only. */
    public const TEXT_MODES = [self::MODE_TITLE, self::MODE_DESCRIPTION, self::MODE_DESCRIPTION_TITLE];

    /** Modes that output a grid of cards. */
    public const GRID_MODES = [self::MODE_GRID, self::MODE_SEARCH_GRID];

    /** Modes rendered by [media-api-widget-render]. */
    public const RENDER_MODES = [
        self::MODE_ITEM, self::MODE_GRID, self::MODE_SEARCH_GRID,
        self::MODE_TITLE, self::MODE_DESCRIPTION, self::MODE_DESCRIPTION_TITLE,
    ];

    public const SOURCE_INHERIT = 'inherit';
    public const SOURCE_VALUE   = 'value';
    public const SOURCE_EMPTY   = 'empty';
    public const FIELD_PREFIX   = 'field:';

    /** Podcast platforms whose player ignores the custom player styling attributes. */
    public const NON_CUSTOM_PODCAST_PLATFORMS = ['omny', 'soundcloud', 'buzzsprout', 'other', 'embed'];

    /** Kinds stored as a source select plus a separate value control. */
    private const FREE_FORM_KINDS = ['text', 'color', 'number', 'image'];

    /** @var array<string,array<string,mixed>>|null Per-request cache of the attribute specs. */
    private static ?array $attributeSpecs = null;

    /**
     * Returns the output modes in display order.
     *
     * @return array<string,string> Mode key => label.
     */
    public static function outputModes(): array
    {
        return [
            self::MODE_ITEM              => __('Media card', 'media-api-widget'),
            self::MODE_GRID              => __('Grid', 'media-api-widget'),
            self::MODE_SEARCH_GRID       => __('Searchable grid with pagination', 'media-api-widget'),
            self::MODE_SEARCH_BAR        => __('Search bar only', 'media-api-widget'),
            self::MODE_TITLE             => __('Title text', 'media-api-widget'),
            self::MODE_DESCRIPTION       => __('Description text', 'media-api-widget'),
            self::MODE_DESCRIPTION_TITLE => __('Description text (title fallback)', 'media-api-widget'),
            self::MODE_PODCAST_PLAYER    => __('Embedded podcast player', 'media-api-widget'),
            self::MODE_FIELD             => __('Stored field value', 'media-api-widget'),
        ];
    }

    /**
     * Returns the control sections in display order.
     *
     * @return array<string,string> Section id => label.
     */
    public static function sections(): array
    {
        return [
            'content'    => __('Output & Source', 'media-api-widget'),
            'selection'  => __('Item Selection', 'media-api-widget'),
            'card'       => __('Card Display', 'media-api-widget'),
            'text'       => __('Text Output', 'media-api-widget'),
            'lightbox'   => __('Lightbox', 'media-api-widget'),
            'grid'       => __('Grid', 'media-api-widget'),
            'search'     => __('Search & Pagination', 'media-api-widget'),
            'search_bar' => __('Search Bar', 'media-api-widget'),
            'podcast'    => __('Podcast Player Styling', 'media-api-widget'),
            'player'     => __('Embedded Player', 'media-api-widget'),
            'styling'    => __('Plugin Styling', 'media-api-widget'),
        ];
    }

    /**
     * Returns the structural (non-attribute) controls.
     *
     * Options that depend on site configuration — playlists and stored fields —
     * are read at call time so the editor always reflects the current admin
     * settings. Defaults are constants: Elementor omits default values when it
     * saves, so a default derived from configuration would silently change what
     * an existing widget renders when the configuration changes.
     *
     * @return array<string,array<string,mixed>> Control key => spec.
     */
    public static function structuralControls(): array
    {
        $notField = ['output_mode', 'ne', self::MODE_FIELD];

        return [
            'output_mode' => [
                'type'    => 'select',
                'section' => 'content',
                'label'   => __('Output', 'media-api-widget'),
                'options' => self::outputModes(),
                'default' => self::MODE_ITEM,
                'when'    => [],
            ],
            'media_platform' => [
                'type'    => 'select',
                'section' => 'content',
                'label'   => __('Media type', 'media-api-widget'),
                'options' => [
                    'youtube' => __('YouTube', 'media-api-widget'),
                    'podcast' => __('Podcast', 'media-api-widget'),
                ],
                'default' => 'youtube',
                'when'    => [$notField],
                'description' => __('The embedded podcast player requires a podcast source.', 'media-api-widget'),
            ],
            'youtube_playlist' => [
                'type'    => 'select',
                'section' => 'content',
                'label'   => __('YouTube playlist', 'media-api-widget'),
                'options' => ['' => __('— Select —', 'media-api-widget')] + MediaSources::optionsFor('youtube'),
                'default' => '',
                'when'    => [$notField, ['media_platform', 'eq', 'youtube']],
            ],
            'podcast_playlist' => [
                'type'    => 'select',
                'section' => 'content',
                'label'   => __('Podcast', 'media-api-widget'),
                'options' => ['' => __('— Select —', 'media-api-widget')] + MediaSources::optionsFor('podcast'),
                'default' => '',
                'when'    => [$notField, ['media_platform', 'eq', 'podcast']],
            ],
            'field_name' => [
                'type'    => 'select',
                'section' => 'content',
                'label'   => __('Stored field', 'media-api-widget'),
                'options' => ['' => __('— Select —', 'media-api-widget')] + self::fieldNameOptions(),
                'default' => '',
                'when'    => [['output_mode', 'eq', self::MODE_FIELD]],
            ],
            'item_select_by' => [
                'type'    => 'select',
                'section' => 'selection',
                'label'   => __('Select item by', 'media-api-widget'),
                'options' => [
                    'position' => __('Position', 'media-api-widget'),
                    'episode'  => __('Episode number (YouTube)', 'media-api-widget'),
                    'title'    => __('Title keyword', 'media-api-widget'),
                    'advanced' => __('Advanced (all selectors)', 'media-api-widget'),
                ],
                'default' => 'position',
                'when'    => [['output_mode', 'in', self::SELECT_MODES]],
                'description' => __('Advanced exposes episode number, title keyword and position together. As with the shortcode, a position overrides the other two.', 'media-api-widget'),
            ],
            'grid_link' => [
                'type'    => 'select',
                'section' => 'search',
                'label'   => __('Search bar link', 'media-api-widget'),
                'options' => [
                    'isolated'   => __('This widget only', 'media-api-widget'),
                    'connection' => __('Connection ID', 'media-api-widget'),
                    'legacy'     => __('Shared playlist link (shortcode compatible)', 'media-api-widget'),
                ],
                'default' => 'isolated',
                'when'    => [['output_mode', 'eq', self::MODE_SEARCH_GRID]],
                'description' => __('Shared playlist link behaves like the shortcode: it links to [media-api-widget-grid-search] bars for the same playlist, and paginates together with other shared grids for that playlist.', 'media-api-widget'),
            ],
            'grid_connection' => [
                'type'    => 'text',
                'section' => 'search',
                'label'   => __('Connection ID', 'media-api-widget'),
                'default' => '',
                'placeholder' => 'episodes',
                'when'    => [['output_mode', 'eq', self::MODE_SEARCH_GRID], ['grid_link', 'eq', 'connection']],
                'description' => __('Use the same ID on a "Search bar only" widget for this playlist.', 'media-api-widget'),
            ],
            'grid_show_search' => [
                'type'    => 'select',
                'section' => 'search',
                'label'   => __('Show search bar above grid', 'media-api-widget'),
                'options' => [
                    'yes' => __('Yes', 'media-api-widget'),
                    'no'  => __('No', 'media-api-widget'),
                ],
                'default' => 'yes',
                'when'    => [['output_mode', 'eq', self::MODE_SEARCH_GRID]],
            ],
            'bar_link' => [
                'type'    => 'select',
                'section' => 'search_bar',
                'label'   => __('Linked grid', 'media-api-widget'),
                'options' => [
                    'connection' => __('Connection ID', 'media-api-widget'),
                    'legacy'     => __('Shared playlist link (shortcode compatible)', 'media-api-widget'),
                ],
                'default' => 'connection',
                'when'    => [['output_mode', 'eq', self::MODE_SEARCH_BAR]],
            ],
            'bar_connection' => [
                'type'    => 'text',
                'section' => 'search_bar',
                'label'   => __('Connection ID', 'media-api-widget'),
                'default' => '',
                'placeholder' => 'episodes',
                'when'    => [['output_mode', 'eq', self::MODE_SEARCH_BAR], ['bar_link', 'eq', 'connection']],
                'description' => __('Must match the Connection ID of a searchable grid widget for the same playlist.', 'media-api-widget'),
            ],
        ];
    }

    /**
     * Returns the attribute specs, keyed by setting key.
     *
     * Spec fields:
     * - attr    — the shortcode attribute the setting fills.
     * - kind    — bool | enum | text | color | number | image.
     * - target  — 'main' (default) or 'bar' for the search bar rendered above a
     *             searchable grid.
     * - default — default source; free-form kinds may default to 'value' with
     *             `default_value`.
     * - fields  — whether stored field references are offered (the search bar
     *             renderer does not resolve them, so its specs opt out).
     * - empty   — whether an explicit empty string is offered.
     *
     * Several setting keys can feed the same attribute (for example
     * `orderdescending`), but their visibility rules are mutually exclusive, so
     * at most one of them is ever active for a given output mode.
     *
     * @return array<string,array<string,mixed>> Setting key => spec.
     */
    public static function attributeSpecs(): array
    {
        if (self::$attributeSpecs !== null) {
            return self::$attributeSpecs;
        }

        $select  = ['output_mode', 'in', self::SELECT_MODES];
        $cards   = ['output_mode', 'in', self::CARD_MODES];
        $grids   = ['output_mode', 'in', self::GRID_MODES];
        $youtube = ['media_platform', 'eq', 'youtube'];
        $podcast = ['media_platform', 'eq', 'podcast'];

        $lightboxStyling = [$cards, $youtube, ['showlightbox', 'ne', 'false'], ['lightboxshowplaylist', 'ne', 'false']];
        $cardPodcastPlayer = [$cards, $podcast, ['podcast_platform', 'nin', self::NON_CUSTOM_PODCAST_PLATFORMS]];
        $embeddedPlayer = [['output_mode', 'eq', self::MODE_PODCAST_PLAYER], $podcast];

        $specs = [
            // Item selection. Exactly one position/episode/keyword setting is
            // active per mode; "advanced" exposes the shortcode's full precedence.
            'position' => self::spec('orderdescending', 'number', 'selection', __('Position', 'media-api-widget'), [$select, ['item_select_by', 'eq', 'position']], [
                'default' => self::SOURCE_VALUE, 'default_value' => '1',
                'description' => __('1 is the first item in the playlist order.', 'media-api-widget'),
            ]),
            'episode' => self::spec('episodenumber', 'number', 'selection', __('Episode number', 'media-api-widget'), [$select, ['item_select_by', 'eq', 'episode'], $youtube], [
                'default' => self::SOURCE_VALUE,
            ]),
            'title_keyword' => self::spec('nameselect', 'text', 'selection', __('Title keyword', 'media-api-widget'), [$select, ['item_select_by', 'eq', 'title']], [
                'default' => self::SOURCE_VALUE,
                'description' => __('Selects the first item whose title contains this text.', 'media-api-widget'),
            ]),
            'adv_episodenumber' => self::spec('episodenumber', 'number', 'selection', __('Episode number', 'media-api-widget'), [$select, ['item_select_by', 'eq', 'advanced'], $youtube]),
            'adv_nameselect' => self::spec('nameselect', 'text', 'selection', __('Title keyword', 'media-api-widget'), [$select, ['item_select_by', 'eq', 'advanced']]),
            'adv_orderdescending' => self::spec('orderdescending', 'number', 'selection', __('Position', 'media-api-widget'), [$select, ['item_select_by', 'eq', 'advanced']]),

            // Card display.
            'showplaybutton' => self::spec('showplaybutton', 'bool', 'card', __('Play button', 'media-api-widget'), [$cards], ['hint' => __('Yes', 'media-api-widget')]),
            'playbuttoniconimgurl' => self::spec('playbuttoniconimgurl', 'image', 'card', __('Play button image', 'media-api-widget'), [$cards, ['showplaybutton', 'ne', 'false']]),
            'playbuttonstyling' => self::spec('playbuttonstyling', 'text', 'card', __('Play button inline CSS', 'media-api-widget'), [$cards, ['showplaybutton', 'ne', 'false']], [
                'placeholder' => 'width: 35%; height: 35%; opacity: 0.3;',
            ]),
            'showtextoverlay' => self::spec('showtextoverlay', 'bool', 'card', __('Text overlay', 'media-api-widget'), [$cards], ['hint' => __('Yes', 'media-api-widget')]),
            'instructionmessage' => self::spec('instructionmessage', 'text', 'card', __('Instruction message', 'media-api-widget'), [$cards, ['showtextoverlay', 'ne', 'false']], [
                'description' => __('Empty uses "Click Here To Watch" / "Click Here To Listen".', 'media-api-widget'),
            ]),
            'fontfamily' => self::spec('fontfamily', 'text', 'card', __('Font family', 'media-api-widget'), [$cards]),
            'thumbnail' => self::spec('thumbnail', 'image', 'card', __('Podcast thumbnail', 'media-api-widget'), [$cards, $podcast]),
            'showplaybar' => self::spec('showplaybar', 'bool', 'card', __('Audio play bar', 'media-api-widget'), [$cards, $podcast], ['hint' => __('No', 'media-api-widget')]),
            'playbarcolor' => self::spec('playbarcolor', 'color', 'card', __('Play bar color', 'media-api-widget'), [$cards, $podcast, ['showplaybar', 'ne', 'false']]),
            'nostyling' => self::spec('nostyling', 'bool', 'styling', __('Disable plugin styling classes', 'media-api-widget'), [['output_mode', 'in', self::RENDER_MODES]], [
                'hint' => __('No', 'media-api-widget'),
                'description' => __('Yes keeps only the maw- hook classes, for fully custom CSS.', 'media-api-widget'),
            ]),

            // Text output.
            'mediadescriptiontextcolor' => self::spec('mediadescriptiontextcolor', 'color', 'text', __('Text color', 'media-api-widget'), [['output_mode', 'in', self::TEXT_MODES]]),

            // Lightbox.
            'showlightbox' => self::spec('showlightbox', 'bool', 'lightbox', __('Open lightbox on click', 'media-api-widget'), [$cards], [
                'hint' => __('Yes', 'media-api-widget'),
                'description' => __('No still dispatches the mediaApiWidgetItemClick event.', 'media-api-widget'),
            ]),
            'lightboxshowplaylist' => self::spec('lightboxshowplaylist', 'bool', 'lightbox', __('Lightbox playlist', 'media-api-widget'), [$cards, ['showlightbox', 'ne', 'false']], [
                'hint' => __('No', 'media-api-widget'),
                'description' => __('YouTube: show the playlist panel. Podcast: open the player lightbox when the card is clicked.', 'media-api-widget'),
            ]),
            'lightboxshowlogoimgurl' => self::spec('lightboxshowlogoimgurl', 'image', 'lightbox', __('Playlist logo', 'media-api-widget'), $lightboxStyling),
            'logo' => self::spec('logo', 'image', 'lightbox', __('Logo (alias, takes precedence)', 'media-api-widget'), $lightboxStyling),
            'lightboxfont' => self::spec('lightboxfont', 'text', 'lightbox', __('Lightbox font', 'media-api-widget'), $lightboxStyling),
            'lightboxthemecolor' => self::spec('lightboxthemecolor', 'color', 'lightbox', __('Playlist border color', 'media-api-widget'), $lightboxStyling),

            // Grid.
            'multiplegridshowall' => self::spec('multiplegridshowall', 'bool', 'grid', __('Show every item (disable filters)', 'media-api-widget'), [$grids], ['hint' => __('No', 'media-api-widget')]),
            'multiplegridsearch' => self::spec('multiplegridsearch', 'text', 'grid', __('Title filter', 'media-api-widget'), [$grids, ['output_mode', 'eq', self::MODE_GRID], ['multiplegridshowall', 'ne', 'true']]),
            'multiplegridlimititems' => self::spec('multiplegridlimititems', 'number', 'grid', __('Limit items', 'media-api-widget'), [$grids, ['output_mode', 'eq', self::MODE_GRID], ['multiplegridshowall', 'ne', 'true']]),
            'multiplegridepisoderange' => self::spec('multiplegridepisoderange', 'text', 'grid', __('Episode range', 'media-api-widget'), [$grids, $youtube, ['multiplegridshowall', 'ne', 'true']], [
                'placeholder' => '1-10',
                'description' => __('Overrides the title filter and limit, as with the shortcode.', 'media-api-widget'),
            ]),
            'multiplegridgap' => self::spec('multiplegridgap', 'text', 'grid', __('Gap', 'media-api-widget'), [$grids], ['placeholder' => '48px']),
            'multiplegridminsize' => self::spec('multiplegridminsize', 'text', 'grid', __('Minimum column width', 'media-api-widget'), [$grids], ['placeholder' => '400px']),
            'multiplegridtext' => self::spec('multiplegridtext', 'enum', 'grid', __('Text below items', 'media-api-widget'), [$grids, $youtube], [
                'options' => [
                    'title'                       => __('Title', 'media-api-widget'),
                    'description'                 => __('Description', 'media-api-widget'),
                    'both'                        => __('Title and description', 'media-api-widget'),
                    'numberedtitle'               => __('Numbered title', 'media-api-widget'),
                    'numberedtitleanddescription' => __('Numbered title and description', 'media-api-widget'),
                ],
            ]),
            'grid_track' => self::spec('orderdescending', 'number', 'grid', __('Player start track', 'media-api-widget'), [$grids, $podcast], [
                'description' => __('Shortcode orderdescending: for SoundCloud and Custom podcasts it sets the start track used by every card.', 'media-api-widget'),
            ]),

            // Search & pagination.
            'multiplegridperpage' => self::spec('multiplegridperpage', 'number', 'search', __('Items per page', 'media-api-widget'), [['output_mode', 'eq', self::MODE_SEARCH_GRID]], ['placeholder' => '12']),
            'multiplegridmaxpages' => self::spec('multiplegridmaxpages', 'number', 'search', __('Maximum pages', 'media-api-widget'), [['output_mode', 'eq', self::MODE_SEARCH_GRID]]),
            'multiplegridmaxpagedisplay' => self::spec('multiplegridmaxpagedisplay', 'number', 'search', __('Page numbers shown at once', 'media-api-widget'), [['output_mode', 'eq', self::MODE_SEARCH_GRID]]),
            'noresults' => self::spec('noresults', 'text', 'search', __('No results message', 'media-api-widget'), [['output_mode', 'eq', self::MODE_SEARCH_GRID]]),

            // Search bar: the standalone bar, and the bar rendered above a
            // searchable grid. The bar renderer resolves no field references.
            'bar_placeholder' => self::spec('placeholder', 'text', 'search_bar', __('Placeholder', 'media-api-widget'), [['output_mode', 'eq', self::MODE_SEARCH_BAR]], ['fields' => false, 'placeholder' => 'Search...']),
            'bar_searchbyenabled' => self::spec('searchbyenabled', 'bool', 'search_bar', __('Search-by dropdown', 'media-api-widget'), [['output_mode', 'eq', self::MODE_SEARCH_BAR]], ['fields' => false, 'hint' => __('Yes', 'media-api-widget')]),
            'bar_clearsearchbutton' => self::spec('clearsearchbutton', 'bool', 'search_bar', __('Clear button', 'media-api-widget'), [['output_mode', 'eq', self::MODE_SEARCH_BAR]], ['fields' => false, 'hint' => __('Yes', 'media-api-widget')]),
            'gridbar_placeholder' => self::spec('placeholder', 'text', 'search', __('Search placeholder', 'media-api-widget'), [['output_mode', 'eq', self::MODE_SEARCH_GRID], ['grid_show_search', 'eq', 'yes']], ['fields' => false, 'placeholder' => 'Search...', 'target' => 'bar']),
            'gridbar_searchbyenabled' => self::spec('searchbyenabled', 'bool', 'search', __('Search-by dropdown', 'media-api-widget'), [['output_mode', 'eq', self::MODE_SEARCH_GRID], ['grid_show_search', 'eq', 'yes']], ['fields' => false, 'hint' => __('Yes', 'media-api-widget'), 'target' => 'bar']),
            'gridbar_clearsearchbutton' => self::spec('clearsearchbutton', 'bool', 'search', __('Clear button', 'media-api-widget'), [['output_mode', 'eq', self::MODE_SEARCH_GRID], ['grid_show_search', 'eq', 'yes']], ['fields' => false, 'hint' => __('Yes', 'media-api-widget'), 'target' => 'bar']),

            // Podcast player styling carried on cards (built-in Custom RSS player).
            'podcast_platform' => self::spec('podcast_platform', 'enum', 'podcast', __('Podcast platform override', 'media-api-widget'), [['output_mode', 'in', self::RENDER_MODES], $podcast], [
                'empty'   => false,
                'options' => [
                    'custom'     => 'custom',
                    'omny'       => 'omny',
                    'soundcloud' => 'soundcloud',
                    'buzzsprout' => 'buzzsprout',
                    'other'      => 'other',
                    'embed'      => 'embed',
                ],
                'description' => __('Default uses the platform configured for the podcast.', 'media-api-widget'),
            ]),
            'podcastplayermode' => self::spec('podcastplayermode', 'text', 'podcast', __('Player background', 'media-api-widget'), $cardPodcastPlayer, [
                'placeholder' => 'dark',
                'description' => __('dark, light, or a hex color. Applies to the built-in player (Custom RSS platform).', 'media-api-widget'),
            ]),
            'podcastplayertextcolor' => self::spec('podcastplayertextcolor', 'color', 'podcast', __('Player text color', 'media-api-widget'), $cardPodcastPlayer),
            'podcastplayerbuttoncolor' => self::spec('podcastplayerbuttoncolor', 'color', 'podcast', __('Player play icon color', 'media-api-widget'), $cardPodcastPlayer),
            'podcastplayercolor' => self::spec('podcastplayercolor', 'color', 'podcast', __('Player accent color', 'media-api-widget'), $cardPodcastPlayer),
            'podcastprogressplayerbarcolor' => self::spec('podcastprogressplayerbarcolor', 'color', 'podcast', __('Player progress bar color', 'media-api-widget'), $cardPodcastPlayer),
            'podcastplayerhighlightcolor' => self::spec('podcastplayerhighlightcolor', 'color', 'podcast', __('Player selected color', 'media-api-widget'), $cardPodcastPlayer),
            'podcastplayerfont' => self::spec('podcastplayerfont', 'text', 'podcast', __('Player font', 'media-api-widget'), $cardPodcastPlayer),
            'podcastplayerscrollcolor' => self::spec('podcastplayerscrollcolor', 'color', 'podcast', __('Player scrollbar color', 'media-api-widget'), $cardPodcastPlayer),
            'showepisodedateaftertitle' => self::spec('showepisodedateaftertitle', 'bool', 'podcast', __('Show date after episode title', 'media-api-widget'), $cardPodcastPlayer),

            // Embedded player. The player renderer replaces empty styling values
            // with the podcast_player_* stored fields, so "empty" is not offered.
            'player_track' => self::spec('orderdescending', 'number', 'player', __('Starting episode', 'media-api-widget'), $embeddedPlayer, [
                'hint' => '1',
                'description' => __('1 is the most recent episode.', 'media-api-widget'),
            ]),
            'player_mode' => self::spec('podcastplayermode', 'text', 'player', __('Background', 'media-api-widget'), $embeddedPlayer, ['empty' => false, 'placeholder' => 'dark', 'description' => __('dark, light, or a hex color. Default uses podcast_player_background_color.', 'media-api-widget')]),
            'player_textcolor' => self::spec('podcastplayertextcolor', 'color', 'player', __('Text color', 'media-api-widget'), $embeddedPlayer, ['empty' => false]),
            'player_buttoncolor' => self::spec('podcastplayerbuttoncolor', 'color', 'player', __('Play icon color', 'media-api-widget'), $embeddedPlayer, ['empty' => false]),
            'player_color' => self::spec('podcastplayercolor', 'color', 'player', __('Accent color', 'media-api-widget'), $embeddedPlayer, ['empty' => false]),
            'player_progresscolor' => self::spec('podcastprogressplayerbarcolor', 'color', 'player', __('Progress bar color', 'media-api-widget'), $embeddedPlayer, ['empty' => false]),
            'player_highlightcolor' => self::spec('podcastplayerhighlightcolor', 'color', 'player', __('Selected episode color', 'media-api-widget'), $embeddedPlayer, ['empty' => false]),
            'player_font' => self::spec('podcastplayerfont', 'text', 'player', __('Font', 'media-api-widget'), $embeddedPlayer, ['empty' => false]),
            'player_scrollcolor' => self::spec('podcastplayerscrollcolor', 'color', 'player', __('Scrollbar color', 'media-api-widget'), $embeddedPlayer, ['empty' => false]),
            'player_showdate' => self::spec('showepisodedateaftertitle', 'bool', 'player', __('Show date after episode title', 'media-api-widget'), $embeddedPlayer, ['hint' => __('No', 'media-api-widget')]),
        ];

        self::$attributeSpecs = $specs;

        return $specs;
    }

    /**
     * Returns the options for an attribute spec's source select.
     *
     * Single-select kinds (bool, enum) store the literal directly. Free-form
     * kinds store `value` and keep the literal in `{key}_value`. Stored fields
     * are appended as `field:{name}`.
     *
     * @param array<string,mixed> $spec Attribute spec.
     * @return array<string,string> Option value => label.
     */
    public static function sourceOptions(array $spec): array
    {
        $default = __('Default', 'media-api-widget');
        if (!empty($spec['hint'])) {
            $default = sprintf(__('Default (%s)', 'media-api-widget'), (string) $spec['hint']);
        }

        $options = [self::SOURCE_INHERIT => $default];

        if ($spec['kind'] === 'bool') {
            $options['true']  = __('Yes', 'media-api-widget');
            $options['false'] = __('No', 'media-api-widget');
        } elseif ($spec['kind'] === 'enum') {
            $options += (array) $spec['options'];
        } else {
            $options[self::SOURCE_VALUE] = __('Custom value', 'media-api-widget');
        }

        if ($spec['empty'] && $spec['kind'] !== 'bool') {
            $options[self::SOURCE_EMPTY] = __('Empty', 'media-api-widget');
        }

        if ($spec['fields']) {
            foreach (MediaSources::fieldNames() as $field) {
                $options[self::FIELD_PREFIX . $field] = sprintf(__('Stored field: %s', 'media-api-widget'), $field);
            }
        }

        return $options;
    }

    /**
     * Returns whether a spec stores its literal in a separate value control.
     *
     * @param array<string,mixed> $spec Attribute spec.
     * @return bool True for text, color, number, and image kinds.
     */
    public static function hasValueControl(array $spec): bool
    {
        return in_array($spec['kind'], self::FREE_FORM_KINDS, true);
    }

    /**
     * Returns the setting key of a spec's value control.
     *
     * @param string $key Spec setting key.
     * @return string Value control key.
     */
    public static function valueKey(string $key): string
    {
        return $key . '_value';
    }

    /**
     * Returns the visibility rules of a spec's value control.
     *
     * @param string              $key  Spec setting key.
     * @param array<string,mixed> $spec Attribute spec.
     * @return array<int,array{0:string,1:string,2:mixed}> Rule leaves.
     */
    public static function valueWhen(string $key, array $spec): array
    {
        return array_merge((array) $spec['when'], [[$key, 'eq', self::SOURCE_VALUE]]);
    }

    /**
     * Returns the default of every setting the widgets store.
     *
     * @return array<string,mixed> Setting key => default.
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::structuralControls() as $key => $control) {
            $defaults[$key] = $control['default'];
        }

        foreach (self::attributeSpecs() as $key => $spec) {
            $defaults[$key] = $spec['default'];
            if (self::hasValueControl($spec)) {
                $defaults[self::valueKey($key)] = $spec['default_value'];
            }
        }

        return $defaults;
    }

    /**
     * Evaluates a list of rule leaves against settings. All leaves must hold.
     *
     * Mirrors the classic conditions engine and the atomic dependency manager
     * for the four operators the schema uses, comparing as strings so a saved
     * classic value and a resolved atomic value behave the same.
     *
     * @param array<int,array{0:string,1:string,2:mixed}> $when     Rule leaves.
     * @param array<string,mixed>                         $settings Settings with defaults applied.
     * @return bool True when every leaf holds.
     */
    public static function isVisible(array $when, array $settings): bool
    {
        foreach ($when as [$key, $operator, $expected]) {
            $actual = self::scalar($settings[$key] ?? null);

            switch ($operator) {
                case 'eq':
                    $ok = $actual === (string) $expected;
                    break;
                case 'ne':
                    $ok = $actual !== (string) $expected;
                    break;
                case 'in':
                    $ok = in_array($actual, array_map('strval', (array) $expected), true);
                    break;
                case 'nin':
                    $ok = !in_array($actual, array_map('strval', (array) $expected), true);
                    break;
                default:
                    $ok = false;
            }

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalizes a setting value to the string used for comparisons.
     *
     * @param mixed $value Raw setting value.
     * @return string Comparable string ('' for null/arrays).
     */
    public static function scalar($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Returns `field_name => field_name` options for the field-output selector.
     *
     * @return array<string,string> Select options.
     */
    private static function fieldNameOptions(): array
    {
        $options = [];

        foreach (MediaSources::fieldNames() as $field) {
            $options[$field] = $field;
        }

        return $options;
    }

    /**
     * Builds one attribute spec with its defaults filled in.
     *
     * @param string                                      $attr    Shortcode attribute.
     * @param string                                      $kind    Value kind.
     * @param string                                      $section Section id.
     * @param string                                      $label   Control label.
     * @param array<int,array{0:string,1:string,2:mixed}> $when    Visibility rules.
     * @param array<string,mixed>                         $extra   Overrides.
     * @return array<string,mixed> Attribute spec.
     */
    private static function spec(string $attr, string $kind, string $section, string $label, array $when, array $extra = []): array
    {
        return array_merge([
            'attr'          => $attr,
            'kind'          => $kind,
            'section'       => $section,
            'label'         => $label,
            'when'          => $when,
            'target'        => 'main',
            'default'       => self::SOURCE_INHERIT,
            'default_value' => '',
            'fields'        => true,
            'empty'         => true,
            'options'       => [],
            'placeholder'   => '',
            'description'   => '',
            'hint'          => '',
        ], $extra);
    }
}
