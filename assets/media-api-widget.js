// Play button Icon

            function media_item_play_button_icon(color = "#fff") {
                return `
                    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" id="Layer_1" data-name="Layer 1" viewBox="0 0 145.2 145.2"><defs>
                        <style>
                            .cls-1 { fill: none; }      
                            .cls-2 { clip-path: url(#clip-path); }      
                            .cls-3 { opacity: 1; }      
                            .cls-4 { clip-path: url(#clip-path-3); }         
                        </style>
                        <clipPath id="clip-path" transform="translate(-264.41 -245.59)">
                            <rect class="cls-1" x="264.41" y="245.59" width="145.2" height="145.2"></rect>
                        </clipPath>
                        <clipPath id="clip-path-3" transform="translate(-264.41 -245.59)">
                            <rect class="cls-1" x="255.41" y="238.59" width="163.2" height="153.2"></rect>
                        </clipPath></defs>
                        <g class="cls-2">
                        <g class="cls-2">
                        <g class="cls-3">
                        <g class="cls-4">
                        <path style="fill: ${color}" class="cls-5" d="M378.93,318.19,311,357.4V279Zm30.68,0a72.6,72.6,0,1,0-72.6,72.6,72.6,72.6,0,0,0,72.6-72.6" transform="translate(-264.41 -245.59)"></path>
                        </g></g></g></g>
                    </svg>`;
            }

            // Audio Playbar

            function audio_play_bar(color = "#fff") {
                return `
                    <svg xmlns="http://www.w3.org/2000/svg" id="audio_play_bar" class="audio-play-bar" data-name="Layer 1" viewBox="0 0 453 45">
                        <defs>
                            <style>        
                                .pb-2 {        
                                    fill: #231f20;      
                                }      
                                .pb-3 {        
                                    fill: none;        
                                    stroke: #231f20;        
                                    stroke-width: 3px;      
                                }    
                            </style>
                        </defs>
                        <rect style="fill: ${color}" width="453" height="45"></rect>
                        <polygon class="pb-2" points="42.99 22 17.01 7 17.01 37 42.99 22"></polygon>
                        <line class="pb-3" x1="52" y1="9" x2="52" y2="36"></line><line class="pb-3" x1="58" y1="9" x2="58" y2="36"></line><line class="pb-3" x1="64" y1="9" x2="64" y2="36"></line><line class="pb-3" x1="70" y1="9" x2="70" y2="36"></line><line class="pb-3" x1="76" y1="9" x2="76" y2="36"></line><line class="pb-3" x1="82" y1="9" x2="82" y2="36"></line><line class="pb-3" x1="88" y1="9" x2="88" y2="36"></line><line class="pb-3" x1="94" y1="9" x2="94" y2="36"></line><line class="pb-3" x1="100" y1="9" x2="100" y2="36"></line><line class="pb-3" x1="106" y1="9" x2="106" y2="36"></line><line class="pb-3" x1="112" y1="9" x2="112" y2="36"></line><line class="pb-3" x1="118" y1="9" x2="118" y2="36"></line><line class="pb-3" x1="124" y1="9" x2="124" y2="36"></line><line class="pb-3" x1="130" y1="9" x2="130" y2="36"></line><line class="pb-3" x1="136" y1="9" x2="136" y2="36"></line><line class="pb-3" x1="142" y1="9" x2="142" y2="36"></line><line class="pb-3" x1="148" y1="9" x2="148" y2="36"></line><line class="pb-3" x1="154" y1="9" x2="154" y2="36"></line><line class="pb-3" x1="160" y1="9" x2="160" y2="36"></line><line class="pb-3" x1="166" y1="9" x2="166" y2="36"></line><line class="pb-3" x1="172" y1="9" x2="172" y2="36"></line><line class="pb-3" x1="178" y1="9" x2="178" y2="36"></line><line class="pb-3" x1="184" y1="9" x2="184" y2="36"></line><line class="pb-3" x1="190" y1="9" x2="190" y2="36"></line><line class="pb-3" x1="196" y1="9" x2="196" y2="36"></line><line class="pb-3" x1="202" y1="9" x2="202" y2="36"></line><line class="pb-3" x1="208" y1="9" x2="208" y2="36"></line><line class="pb-3" x1="214" y1="9" x2="214" y2="36"></line><line class="pb-3" x1="220" y1="9" x2="220" y2="36"></line><line class="pb-3" x1="226" y1="9" x2="226" y2="36"></line><line class="pb-3" x1="232" y1="9" x2="232" y2="36"></line><line class="pb-3" x1="238" y1="9" x2="238" y2="36"></line><line class="pb-3" x1="244" y1="9" x2="244" y2="36"></line><line class="pb-3" x1="250" y1="9" x2="250" y2="36"></line><line class="pb-3" x1="256" y1="9" x2="256" y2="36"></line><line class="pb-3" x1="262" y1="9" x2="262" y2="36"></line><line class="pb-3" x1="268" y1="9" x2="268" y2="36"></line><line class="pb-3" x1="274" y1="9" x2="274" y2="36"></line><line class="pb-3" x1="280" y1="9" x2="280" y2="36"></line><line class="pb-3" x1="286" y1="9" x2="286" y2="36"></line><line class="pb-3" x1="292" y1="9" x2="292" y2="36"></line><line class="pb-3" x1="298" y1="9" x2="298" y2="36"></line><line class="pb-3" x1="304" y1="9" x2="304" y2="36"></line><line class="pb-3" x1="310" y1="9" x2="310" y2="36"></line><line class="pb-3" x1="316" y1="9" x2="316" y2="36"></line><line class="pb-3" x1="322" y1="9" x2="322" y2="36"></line><line class="pb-3" x1="328" y1="9" x2="328" y2="36"></line><line class="pb-3" x1="334" y1="9" x2="334" y2="36"></line><line class="pb-3" x1="340" y1="9" x2="340" y2="36"></line><line class="pb-3" x1="346" y1="9" x2="346" y2="36"></line><line class="pb-3" x1="352" y1="9" x2="352" y2="36"></line><line class="pb-3" x1="358" y1="9" x2="358" y2="36"></line><line class="pb-3" x1="364" y1="9" x2="364" y2="36"></line><line class="pb-3" x1="370" y1="9" x2="370" y2="36"></line><line class="pb-3" x1="376" y1="9" x2="376" y2="36"></line><line class="pb-3" x1="382" y1="9" x2="382" y2="36"></line><line class="pb-3" x1="388" y1="9" x2="388" y2="36"></line><line class="pb-3" x1="394" y1="9" x2="394" y2="36"></line><line class="pb-3" x1="400" y1="9" x2="400" y2="36"></line><line class="pb-3" x1="406" y1="9" x2="406" y2="36"></line><line class="pb-3" x1="412" y1="9" x2="412" y2="36"></line><line class="pb-3" x1="418" y1="9" x2="418" y2="36"></line><line class="pb-3" x1="424" y1="9" x2="424" y2="36"></line><line class="pb-3" x1="430" y1="9" x2="430" y2="36"></line><line class="pb-3" x1="436" y1="9" x2="436" y2="36"></line>
                        </svg>
	                `
	            }

	            function escape_html(value) {
	                return String(value ?? "")
	                    .replace(/&/g, "&amp;")
	                    .replace(/</g, "&lt;")
	                    .replace(/>/g, "&gt;")
	                    .replace(/"/g, "&quot;")
	                    .replace(/'/g, "&#39;");
	            }

	            function escape_attr(value) {
	                return escape_html(value);
	            }

	            function format_overlay_title(item) {
	                if (item.episode !== -1) {
	                    return `Episode ${item.episode}`;
	                }

	                const words = String(item.title ?? "").split(" ");
	                if (words.length > 3) {
	                    const first = words.filter((l, i) => i < 3).join(" ");
	                    const rest = words.filter((l, i) => i >= 3).join(" ");
	                    return `${escape_html(first)}<br><span class="sub-text">${escape_html(rest)}</span>`;
	                }

	                return escape_html(item.title ?? "");
	            }

	            function media_title_text(item) {
	                return item.episode !== -1 ? `Episode ${item.episode}` : String(item.title ?? "");
	            }

	            function sanitize_multiple_grid_text(value) {
	                const normalized = String(value ?? "").trim().toLowerCase();
	                return ["title", "description", "both", "numberedtitle", "numberedtitleanddescription"].includes(normalized) ? normalized : "";
	            }

	            // Build the episode/season-aware title text for a numbered grid item.
	            // When the item has no episode number (episode === -1, i.e. the
	            // playlist's sort mode is "Normal" or no number was detected), the
	            // plain video title is used. When an episode number is present and a
	            // valid season was parsed via the season/episode regex, returns
	            // "Season {s}, Episode {e}"; otherwise returns "Episode {e}".
	            function numbered_title_text(item) {
	                const episode = Number(item?.episode);
	                if (!Number.isFinite(episode) || episode === -1) {
	                    return String(item?.title ?? "").trim();
	                }
	                const season = Number(item?.season);
	                if (Number.isFinite(season) && season !== -1) {
	                    return `Season ${season} Episode ${episode}`;
	                }
	                return `Episode ${episode}`;
	            }

	            function render_multiple_grid_text(item, mode) {
	                if (mode === "both") {
	                    const title = String(item?.title ?? "").trim();
	                    const description = String(item?.description ?? "").trim();
	                    if (!title && !description) return "";
	                    let html = "";
	                    if (title) html += `<div class="media-item-multiple-grid-text media-item-multiple-grid-text-title">${escape_html(title)}</div>`;
	                    if (description) html += `<div class="media-item-multiple-grid-text media-item-multiple-grid-text-description">${escape_html(description)}</div>`;
	                    return html;
	                }

	                if (mode === "numberedtitle" || mode === "numberedtitleanddescription") {
	                    const title = numbered_title_text(item);
	                    const description = String(item?.description ?? "").trim();
	                    let html = "";
	                    if (title) html += `<div class="media-item-multiple-grid-text media-item-multiple-grid-text-title">${escape_html(title)}</div>`;
	                    if (mode === "numberedtitleanddescription" && description) html += `<div class="media-item-multiple-grid-text media-item-multiple-grid-text-description">${escape_html(description)}</div>`;
	                    return html;
	                }

	                if (mode !== "title" && mode !== "description") {
	                    return "";
	                }

	                const text = String(item?.[mode] ?? "").trim();
	                if (!text) {
	                    return "";
	                }

	                return `<div class="media-item-multiple-grid-text media-item-multiple-grid-text-${mode}">${escape_html(text)}</div>`;
	            }

	            // Render Media Item

	            function render_media_item(item, settings, playlistItem, info) {

                // Set Styling For Lightbox Playlist Items
                
                if (playlistItem) {
                    settings = {
                        showPlayButton: true,
                        playButtonIconImgUrl: null,
                        playButtonStyling: "width: 50%; height: 50%; opacity: 0.3;",
                        showTextOverlay: false,
                        instructionMessage: null,
                        fontFamily: null,
                        lightboxshowplaylist: false,
                        showLightbox: true
                    }
                }

                // Checks If Podcast And Is The Custom Player

                const customPodcastPlayer = settings.podcastplayermode ? true : false;

                let podcastPlayerData = customPodcastPlayer ? `data-podcastplayermode="${settings.podcastplayermode}" data-podcastplayerbuttoncolor="${settings.podcastplayerbuttoncolor}"
                    data-podcastplayercolor="${settings.podcastplayercolor}" data-podcastprogressplayerbarcolor="${settings.podcastprogressplayerbarcolor}" data-podcastplayerhighlightcolor="${settings.podcastplayerhighlightcolor}"
                    data-podcastplayerfont="${settings.podcastplayerfont}" data-podcastplayerscrollcolor="${settings.podcastplayerscrollcolor}"
                    data-podcastplayertextcolor="${settings.podcastplayertextcolor}" data-showepisodedateaftertitle="${settings.showepisodedateaftertitle}"
                    `: false;

	                const { name, type } = info;
	                const mediaType = type === "youtube" || type === "vimeo" ? "video" : "audio";
	                const { title, thumbnail, publishedDate, id, description } = item;
	                const { showPlayButton, playButtonIconImgUrl, playButtonStyling, showTextOverlay, instructionMessage, fontFamily, lightboxshowplaylist, showPlaybar, playbarColor, showLightbox } = settings;
	                const safeTitleAttr = escape_attr(title ?? "");
	                const safeOverlayTitle = format_overlay_title(item);
	                const safePlaylistTitle = escape_html(media_title_text(item));
	                const safeInstructionMessage = escape_html(instructionMessage ?? "");
	                const multipleGridTextHtml = !playlistItem ? render_multiple_grid_text(item, settings.multipleGridText) : "";
	                const mediaItemHtml = `
	                    <a ${settings.fontFamily ? `style=${fontFamily}` : ""} class="media_item${showTextOverlay ? `-text-overlay-enabled` : ``}" data-itemclickablemediatype="${mediaType}" data-itemclickable="true" data-itemclickableplaylist="${name}_${type}" 
	                        data-id="${id}" ${lightboxshowplaylist ? `data-lightboxshowplaylist="true"` : ""} ${item.trackSelect !== null ? `data-trackselect=${Number(item.trackSelect)}` : ""}
	                        ${customPodcastPlayer  ? podcastPlayerData : ""}
	                        ${showLightbox === false ? `data-showlightbox="false"` : ""}
	                    >
	                        <div class="media-item-thumbnail-text-wrapper">
	                            <img class="media-item-thumbnail" src="${thumbnail.url}" width="${thumbnail.width ? thumbnail.width : 1280}" height="${thumbnail.height ? thumbnail.height : 720}" alt="${safeTitleAttr}">
	                            ${showPlayButton ? 
	                                `
	                                    <div class="media-item-play-button" style="${playButtonStyling}">
                                        ${showPlayButton ? 
                                            `${playButtonIconImgUrl ? `<img src="${playButtonIconImgUrl}">` :
                                                media_item_play_button_icon()
                                            }` : ``
                                        }
                                    </div>
                                ` 
                            : ``}
	                            ${showTextOverlay ? 
	                                `
	                                    <div class="media-item-text-overlay" ${mediaType === "audio" ? `style="padding-bottom: max(10vw, 48px);"` : "" }>
	                                        <h3>${safeOverlayTitle}</h3>
	                                        <p>${safeInstructionMessage}</p>
	                                    </div>
	                                ` 
	                            : ``}
	                        </div>
	                        ${ playlistItem ? `<h3 class="playlist-episode-text">${safePlaylistTitle}</h3>` : ""} 
	                        ${mediaType === "audio" && showPlaybar ? audio_play_bar(playbarColor) : ""}
	                    </a>
	                `;

	                if (multipleGridTextHtml) {
	                    return `
	                        <!-- media item -->
	                        <div class="media-item-multiple-grid-entry">
	                            ${mediaItemHtml}
	                            ${multipleGridTextHtml}
	                        </div>
	                    `;
	                }

	                return `
	                    <!-- media item -->
	                    ${mediaItemHtml}
	                `;
            }

            // Initialize Loading Of Media Items And Lightbox

            function initialize_media(media_items, media_data, media_name, media_type, podcast_platform) {

                // Variables For Lightbox

                let showThemeColor;
                let showLogoImgUrl;
                let lightboxFont;

                if (media_items.length > 0) {
                    media_items.forEach(item => {
                        
                        if (!media_data || media_data.length === 0) {
                            if (media_type === "youtube" || media_type === "vimeo") {
                                item.innerHTML = "<h2>Error loading video.</h2>";
                            }
                            if (media_type === "podcast") {
                                item.innerHTML = "<h2>Error loading podcast.</h2>";
                            }
                            return;
                        }

                        const itemData = item.dataset;

                        // Check That Item Corresponds To Correct Playlist.  If Not, Return To Exit

                        if (itemData.playlistname !== media_name && itemData.mediaplatform !== media_type) {
                            return
                        } 

                        // CSS Styling

                        const showPlayButton = itemData.showplaybutton === "true" ? true : itemData.showplaybutton === "false" ? false : true;

                        const playButtonIconImgUrl = itemData.playButtonIconImgUrl ? itemData.playButtonIconImgUrl : null;

                        const playButtonStyling = itemData.playbuttonstyling ? itemData.playbuttonstyling :  "width: 35%; height: 35%; opacity: 0.3;"

                        const showTextOverlay = itemData.showtextoverlay === "true" ? true : itemData.showtextoverlay === "false" ? false : true;
                        
                        const fontFamily = itemData.fontfamily ? `"font-family: ${itemData.fontfamily}"` : `"font-family: Roboto;"`;

                        showThemeColor = itemData.lightboxthemecolor ? itemData.lightboxthemecolor : showThemeColor;

                        showLogoImgUrl = itemData.lightboxshowlogoimgurl ? itemData.lightboxshowlogoimgurl : showLogoImgUrl;

                        lightboxFont = itemData.lightboxfont ? itemData.lightboxfont : lightboxFont;
                        
                        const showPlaybar = itemData.showplaybar === "true" ? true : false;
                        
                        const playbarColor = itemData.playbarcolor ? itemData.playbarcolor : "#fff";
                        
                        // Text Overlay Messages

                        const instructionMessage = itemData.instructionMessage ? itemData.instructionMessage : media_type === "youtube" ? "Click Here To Watch" : media_type === "podcast" ? "Click Here To Listen" : "";

                        const lightboxshowplaylist = itemData.lightboxshowplaylist && itemData.lightboxshowplaylist === "true" ? true : false;
                        const showLightbox = itemData.showlightbox !== "false";

                        // Thumbnail

                        const thumbnailimg = itemData.thumbnail ? itemData.thumbnail : null;

                        // Data Parsing

                        let renderData;

                        if (podcast_platform !== "embed") {
                            if (media_type === "podcast") {
                                if (!media_data.channel.item.length) {
                                    media_data.channel.item = [media_data.channel.item];
                                }
                            }
                            renderData = media_type === "youtube" ? media_data 
                            : media_type === "podcast" ? media_data.channel.item.map(item => ({...item, thumbnail: { url: thumbnailimg }, publishedDate: item.pubDate, id: item.guid, episode: -1 })) 
                            : [];  
                        } else {
                            renderData = [{ thumbnail: { url: thumbnailimg} , publishedDate: "unknown", id: "unknown", episode: -1, title: `Embedded Podcast` }];
                        }

                        if (podcast_platform === "soundcloud") {
                            renderData = renderData.map(i => ({...i, trackSelect: Number(itemData.orderdescending) - 1}));
                        }

                        if (podcast_platform === "custom") {
                            renderData = renderData.map(i => ({...i, trackSelect: Number(itemData.orderdescending)}));
                        }

                        const settings = {
                            showPlayButton,
                            playButtonIconImgUrl,
                            playButtonStyling,
                            showTextOverlay,
                            instructionMessage,
                            fontFamily,
                            lightboxshowplaylist,
                            showPlaybar,
                            playbarColor,
                            showLightbox
                        };

                        const info = {
                            name: media_name,
                            type: media_type
                        };

                        // Select Item

                        const nameSelect = itemData.nameselect;
                        const episodeNumber = itemData.episodenumber;
                        let index;

                        if (episodeNumber && media_type === "youtube") {
                            index = renderData.findIndex(item => item.episode === Number(episodeNumber));
                        }
                        if (nameSelect) {
                            index = renderData.findIndex(item => item.title.toLowerCase().includes(nameSelect.toLowerCase()));
                        }
                        if (itemData.orderdescending) {
                            index = Number(itemData.orderdescending) - 1;
                        }

                        // If Podcast Is embed or Soundcloud, Index Will Automatically Be Set To 0

                        if (podcast_platform === "embed") {
                            index = 0;
                        }

                        // If Podcast Is Custom, Add Fields In Settings

                        if (podcast_platform === "custom") {
                            // Drop a leading "#" only when there is one: "dark", "light"
                            // and bare hex values must reach the player intact.
                            const playerColor = value => String(value || "").trim().replace(/^#+/, "");
                            settings.podcastplayermode = playerColor(itemData.podcastplayermode) || "dark";
                            settings.podcastplayerbuttoncolor = playerColor(itemData.podcastplayerbuttoncolor);
                            settings.podcastplayercolor = playerColor(itemData.podcastplayercolor);
                            settings.podcastprogressplayerbarcolor = playerColor(itemData.podcastprogressplayerbarcolor);
                            settings.podcastplayerhighlightcolor = playerColor(itemData.podcastplayerhighlightcolor);
                            settings.podcastplayerfont = itemData.podcastplayerfont ? itemData.podcastplayerfont : "";
                            settings.podcastplayerscrollcolor = playerColor(itemData.podcastplayerscrollcolor);
                            settings.podcastplayertextcolor = playerColor(itemData.podcastplayertextcolor);
                            settings.showepisodedateaftertitle = itemData.showepisodedateaftertitle ? itemData.showepisodedateaftertitle : "";
                        }

                        let trackSelect = null;

                        // If Track Is Soundcloud, trackselect will be set from itemData.orderdescending

                        if (podcast_platform === "soundcloud") {
                            trackSelect = Number(itemData.orderdescending) - 1;
                        }

                        if (podcast_platform === "custom") {
                            trackSelect = Number(itemData.orderdescending)
                        }

                        //  Output Text For Media Description Or Text Only Text And Exit

                        if (itemData.mediatitle === "true" || itemData.mediadescription === "true") {

                            if (renderData[index]) {
                                let descriptionOrTitle;

                                if (itemData.mediadescription === "true") {
                                    descriptionOrTitle = renderData[index].description;
                                }

                                if (itemData.mediatitle === "true" && !descriptionOrTitle) {
                                    descriptionOrTitle = renderData[index].title;
                                }

	                                if (descriptionOrTitle && typeof descriptionOrTitle !== "object") {
	                                    item.outerHTML = `<p style="color: ${itemData.mediadescriptiontextcolor}" class="media-description-text">${escape_html(descriptionOrTitle)}</p>`;
	                                } else {
	                                    console.warn(`No description or title for "${descriptionOrTitle}" under order selection of "${index + 1}".  Description or title text will auto populate once the video or podcast has it.`);
	                                    const parentContainer = item.parentNode;
                                    if (parentContainer.classList.toString().includes("elementor-widget-container")) {
                                        parentContainer.outerHTML = "";
                                    } else {
                                        item.outerHTML = "";
                                    }
                                }
                                return;
                            }
                        } else {

                            // Checks If multiplegrid parameter was provided and filters accordingly prior to rendering

                            if (itemData.multiplegrid === "true" && media_data.length > 1) {
                                let renderGridData = [...renderData];
                                const multipleGridText = media_type === "youtube"
                                    ? sanitize_multiple_grid_text(itemData.multiplegridtext || itemData.mutiplegridtext)
                                    : "";

                                // If multiplegridshowall is not set to true, then playlist will be filtered accordingly.  

                                if (!itemData.multiplegridshowall || itemData.multiplegridshowall !== "true") {
                                    if (itemData.multiplegridsearch) {
                                        renderGridData = renderGridData.filter(item => item.title.toLowerCase().includes(itemData.multiplegridsearch.toLowerCase()));   
                                    }
                                    if (Number(itemData.multiplegridlimititems)) {
                                        renderGridData = renderGridData.filter((item, index) => index < Number(itemData.multiplegridlimititems));
                                    }

                                    // Will override search and limit items if parameter is present for episode range

                                    if (itemData.multiplegridepisoderange && itemData.multiplegridepisoderange !== "" && itemData.multiplegridepisoderange.includes("-") && itemData.multiplegridepisoderange !== "-" && /[0-9]/.test(itemData.multiplegridepisoderange)) {
                                        const splitter = itemData.multiplegridepisoderange.split("-");
                                        renderGridData = [...media_data];
                                        renderGridData = renderGridData.filter(item => item.episode >= Number(splitter[0]) && item.episode <= Number(splitter[1]));
                                    }
                                }
                                const gapBetweenItems = itemData.multiplegridgap ? itemData.multiplegridgap : "48px";
                                const minSize = itemData.multiplegridminsize ? itemData.multiplegridminsize : "400px";
                                if (renderGridData.length > 0) {
                                    item.outerHTML = 
                                        `<div class="media_items_multiple_grid_layout" style="gap: ${gapBetweenItems}; grid-template-columns: repeat(auto-fill, minmax(min(100%, ${minSize}), 1fr));">
                                            ${renderGridData.map(row => render_media_item(row, {...settings, multipleGridText}, false, info)).join("")}
                                        </div>`
                                } else item.outerHTML = `<h3 class="media-api-widget-err-msg">No ${media_type} items found in playlist based upon search parameters provided.</h3>`
                                return
                            }

                            // Render Single Item

                            if (renderData[index]) {
                                item.outerHTML = render_media_item(renderData[index], settings, false, info);
                                return;
                            } else item.outerHTML = `<h3 class="media-api-widget-err-msg">No ${media_type} item found in playlist based upon search parameters provided.</h3>` 
                        }
                    });
                }

                // Generate Lightbox HTML Root Tag In Body

                const media_lightbox_div = document.createElement("div");
                media_lightbox_div.dataset.lightboxmediacontenttype = media_type === "youtube" || media_type === "vimeo" ? "video" : "audio";
                media_lightbox_div.dataset.lightboxid = `${media_name}_${media_type}`;
                document.body.appendChild(media_lightbox_div);
                const lightbox = document.querySelector(`[data-lightboxid = "${media_name}"]`);

                // Video Lightbox HTML

                function media_video_lightbox_html() {
                    return `
                        <div class="lightbox-content-container" style="font-family: ${lightboxFont};">
                            <fieldset style="border: 8px ${showThemeColor} solid;" class="lightbox-playlist-container" data-lightboxplaylistcontainer="true">
                            <legend>
                                ${showLogoImgUrl ? `<img class="playlist-logo" src="${showLogoImgUrl}">` : `<h1 class="playlist-heading">Videos</h1>`}
                            </legend>
                            <div class="grid-layout" data-lightboxplaylistcontent="true"></div>
                            </fieldset>
                            <div class="lightbox-player-container" data-lightboxplayer="true">
                            <svg version="1.0" xmlns="http://www.w3.org/2000/svg" class="arrow-left" data-hideonidle="true" data-lightboxarrowdirection="left" data-lightboxarrow="true"
                                width="100.000000pt" height="100.000000pt" viewBox="0 0 100.000000 100.000000"
                                preserveAspectRatio="xMidYMid meet">
                                <g data-hideonidle="true" transform="translate(0.000000,100.000000) scale(0.100000,-0.100000)"
                                fill="#ffffff" stroke="none">
                                <path data-hideonidle="true" d="M415 720 l-220 -220 223 -222 222 -223 72 73 73 72 -148 148 -147 147 145 145 c80 80 145 149 145 155 0 0 -140 145 -140 145 0 0 -104 -99 -225 -220z"/>
                                </g>
                            </svg>
                            <div class="lightbox-box-frame">
                                <div class="lightbox-frames" data-lightboxframes="true">
                                <div data-frameposition="-1" class="frame-transition-left video-thumbnail-wrapper" data-lightboxframe="true">
                                    <iframe width="560" height="315" title="${media_type} video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen>
                                    </iframe>
                                    <div class="hover-text-container">
                                    <h1></h1>
                                    </div>
                                </div>
                                <div data-frameposition="0" class="video-thumbnail-wrapper" data-lightboxframe="true">
                                    <iframe width="560" height="315" title="${media_type} video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen>
                                    </iframe>
                                    <div class="hover-text-container">
                                    <h1></h1>
                                    </div>
                                </div>
                                <div data-frameposition="1" class="frame-transition-right video-thumbnail-wrapper" data-lightboxframe="true">
                                    <iframe width="560" height="315" title="${media_type} video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen>
                                    </iframe>
                                    <div class="hover-text-container">
                                    <h1></h1>
                                    </div>
                                </div>
                                </div>
                                <div class="fast-forward-overlay element-invisible" data-fastforwardoverlay="true">
                                <h1></h1>
                                </div>
                            </div>
                            <svg version="1.0" xmlns="http://www.w3.org/2000/svg" class="arrow-right" data-hideonidle="true" data-lightboxarrowdirection="right" data-lightboxarrow="true"
                                width="100.000000pt" height="100.000000pt" viewBox="0 0 100.000000 100.000000"
                                preserveAspectRatio="xMidYMid meet">
                                <g data-hideonidle="true" transform="translate(0.000000,100.000000) scale(0.100000,-0.100000)"
                                fill="#ffffff" stroke="none">
                                <path data-hideonidle="true" d="M415 720 l-220 -220 223 -222 222 -223 72 73 73 72 -148 148 -147 147 145 145 c80 80 145 149 145 155 0 0 -140 145 -140 145 0 0 -104 -99 -225 -220z"/>
                                </g>
                            </svg>
                            </div>
                            <div class="lightbox-close-button" data-hideonidle="true" data-lightboxclosebutton="true">X</div>
                            <svg class="lightbox-playlist-button" data-lightboxplaylistbutton="true" data-hideonidle="true" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" id="Layer_1" x="0px" y="0px" viewBox="0 0 122.88 101.66" style="enable-background:new 0 0 122.88 101.66" xml:space="preserve">
                            <g>
                                <path xmlns="http://www.w3.org/2000/svg" class="st0" fill="#ffffff" d="M0,0h97.6v16.12H0V0L0,0z M122.88,77.46l-38-24.21v48.41L122.88,77.46L122.88,77.46z M0,61.46h73.62v16.12H0 V61.46L0,61.46z M0,30.77h97.6v16.12H0V30.77L0,30.77z"/>
                            </g>
                            </svg>
                        </div>
                    `;
                }

                // Initialize Show Playlist Variable

                let showPlaylist = false;

                // Activate Video Lightbox On Video Item Click

                let media_lightbox_activated = false;

                function video_lightbox_activate_handler(itemClicked, lightboxshowplaylist, lightboxStyling = null) {

                    // Checks That Type Is Either Youtube Or Vimeo.  If Not, Return To Exit

                    if (media_type !== "youtube" && media_type !== "vimeo") {
                        return
                    }

                    const lightbox = media_lightbox_div;
                    lightbox.innerHTML = "";
                    lightbox.innerHTML = media_video_lightbox_html();
                    let startingLightboxActive = lightbox.querySelector(`[data-frameposition="0"]`);
                    let lightboxStartingLeft = lightbox.querySelector(`[data-frameposition="-1"]`);
                    let lightboxStartingRight = lightbox.querySelector(`[data-frameposition="1"]`);
                    const lightboxFramesContainer = lightbox.querySelector(`[data-lightboxframes="true"]`);
                    const lightboxFrames = lightbox.querySelectorAll(`[data-lightboxframe="true"]`);
                    const lightboxFastForwardOverlay = lightbox.querySelector(`[data-fastforwardoverlay="true"]`);
                    const lightboxFastForwardText = lightboxFastForwardOverlay.querySelector(`h1`);
                    const lightboxArrowLeft = lightbox.querySelector(`[data-lightboxarrowdirection="left"]`);
                    const lightboxArrowRight = lightbox.querySelector(`[data-lightboxarrowdirection="right"]`);
                    const lightboxCloseButton = lightbox.querySelector(`[data-lightboxclosebutton="true"]`);
                    const lightboxPlayerContainer = lightbox.querySelector(`[data-lightboxplayer="true"]`);
                    const lightboxPlaylistContainer = lightbox.querySelector(`[data-lightboxplaylistcontainer="true"]`);
                    const lightboxPlaylistContent = lightbox.querySelector(`[data-lightboxplaylistcontent="true"]`);
                    const lightboxPlaylistButton = lightbox.querySelector(`[data-lightboxplaylistbutton="true"]`);

                    if (lightboxStyling) {
                        if (lightboxStyling.image) {
                            lightbox.querySelector(`.lightbox-content-container fieldset legend`).innerHTML  = `<img class="playlist-logo" src="${lightboxStyling.image}">`;
                        }
                        if (lightboxStyling.themeColor) {
                            lightbox.querySelector(`.lightbox-content-container fieldset`).style.border = `min(8px, 1.5vw) ${lightboxStyling.themeColor} solid`;
                        }
                        if (lightboxStyling.font) {
                            lightbox.querySelector(`.lightbox-content-container`).style.fontFamily = `"${lightboxStyling.font}"`;
                        }
                    }

                    // Playlist Button

                    const playlistButton = lightboxshowplaylist ? media_item_play_button_icon() : null;

                    // `${media_type}` Playlist Selected

                    let playListSorted = lightboxshowplaylist ? media_data : media_data.filter(item => item.id === itemClicked.dataset.id);

                    // Removes Non Episode Numbered Items From Playlist If Sort Mode Is Set To "number_in_title"

                    if (media_type === "youtube" && lightboxshowplaylist && !playListSorted.every(item => item.episode === -1)) {
                        playListSorted = playListSorted.filter(item => item.episode && item.episode !== -1);
                    }

                    // A stored-post link (Elementor "Media API Click Action") can name a
                    // video this page's player data does not hold, such as one beyond a
                    // six-item playlist. Play that video on its own rather than another.

                    if (itemClicked && itemClicked.dataset.mawlink === "true" && !playListSorted.some(item => item.id === itemClicked.dataset.id)) {
                        playListSorted = [{
                            id: itemClicked.dataset.id,
                            title: itemClicked.dataset.mawtitle || "",
                            episode: -1,
                            season: -1,
                            description: "",
                            publishedDate: "",
                            thumbnail: { url: "" }
                        }];
                    }
                    
                    // Elements To Hide When User Idle In Lightbox After 5 Seconds

                    const elementsToHideWhenIdle = lightbox.querySelectorAll(`[data-hideonidle="true"]`);

                    let mouseOverElement = false;
                    let lastMouseOverTime = new Date().getTime();
                    const idleDelayTime = 5000;

                    // Checks If Lightbox Arrow Was Clicked

                    let lightboxArrowClicked = false;

                    // Lightbox Base Url Origin

                    const lightboxFrameVideoBaseUrl = `https://www.youtube.com/embed/`;

                    // Sets Media Query Break Point Of When Video Containers Should Stack In One Column.  Calculation Based Upon minimumWidthOfEachVideo Number.  Default Value Is: minimumWidthOfEachGridVideoItem * 2

                    const mediaQueryMobileBreakpoint = (400 * 2) + (48 * 1.5);

                    // Select HTML tag to Hide Scroll Bar

                    const html = document.querySelector(`html`);

                    // Set Thumbnail Episode Number Or Title Text

	                    function setThumbnailText(item) {
	                        return media_title_text(item);
	                    }

                    // Monitors Mouse Movements And Clicks In Lightbox

                    function lightboxMouseMoveHandler(e, elementsToHideWhenIdle) {
                        mouseOverElement = false;
                        lastMouseOverTime = new Date().getTime();
                        if (e && e.path) {
                        e.path.forEach(p => {
                            if (p.dataset && p.dataset.hideonidle) {
                            mouseOverElement = true;
                            }
                        })
                        }
                        if (elementsToHideWhenIdle && elementsToHideWhenIdle[0] !== null && !mouseOverElement) {
                        elementsToHideWhenIdle.forEach(element => { 
                            if (!element.dataset.lightboxplaylistbutton) {
                            element.classList.remove(`element-invisible`);
                            }
                            if (element.dataset.lightboxplaylistbutton && showPlaylist && !element.classList.toString().includes(`element-invisible`)) {
                            element.classList.add(`element-invisible`);
                            }
                            if (!showPlaylist && element.dataset.lightboxplaylistbutton && element.classList.toString().includes(`element-invisible`)) {
                            element.classList.remove(`element-invisible`);
                            }
                        });
                        setTimeout(() => {
                            if (!mouseOverElement && (new Date().getTime() - lastMouseOverTime) >= idleDelayTime && !showPlaylist) {
                                elementsToHideWhenIdle.forEach(element => element.classList.add(`element-invisible`));
                            }
                        }, idleDelayTime)
                        }
                        if (!playlistButton) {
                            lightboxPlaylistButton.classList.add(`element-invisible`);
                        }
                    }

                    // Sets Data

                    function setData(index) {
                        return playListSorted[currentVideoIndex + index]
                    }

                    // Sets Video Id

                    function setVideoId(index) {
                        return playListSorted[currentVideoIndex + index].id
                    }

                    // Calls Upon lightboxMouseMoveHandler Function

                    lightboxMouseMoveHandler();

                    lightbox.addEventListener(`mousemove`, e => lightboxMouseMoveHandler(e, elementsToHideWhenIdle));
                    lightbox.addEventListener(`click`, e => lightboxMouseMoveHandler(e, elementsToHideWhenIdle));

                    // Video Index And Base Url For `${media_type}` Embed Iframe.  Will Be Set To 0 If Returned Value Is -1

                    let currentVideoIndex;

                    if (itemClicked) {
                        currentVideoIndex = playListSorted.findIndex(video => video.id === itemClicked.dataset.id);
                    } 

                    if (!currentVideoIndex || currentVideoIndex === -1) {
                        currentVideoIndex = 0;
                    }

                    // Check For Starting Position To See If Arrows Should Be Shown Based Upon Starting Video Index Position And Set Corresponding Iframe Urls

                    function initLightboxFrames() {
                        startingLightboxActive = lightbox.querySelector(`[data-frameposition="0"]`);
                        lightboxStartingLeft = lightbox.querySelector(`[data-frameposition="-1"]`);
                        lightboxStartingRight = lightbox.querySelector(`[data-frameposition="1"]`);
                        
                        lightboxFrames.forEach(frame => {
                            frame.classList.remove(`frame-transitioning-left`);
                            frame.classList.remove(`frame-transitioning-right`);
                        });

                        if (!playListSorted[currentVideoIndex + 1]) {
                            lightboxArrowRight.classList.add(`disable-arrow`);
                        } else { 
                            lightboxArrowRight.classList.remove(`disable-arrow`);
                            lightboxStartingRight.classList.add(`frame-transitioning-right`);
                            // Preload adjacent carousel videos without playing them.
                            lightboxStartingRight.querySelector(`iframe`).src = lightboxFrameVideoBaseUrl + setVideoId(1);
	                            lightboxStartingRight.querySelector(`h1`).textContent = setThumbnailText(setData(1));
                        }
                        if (!playListSorted[currentVideoIndex - 1]) {
                            lightboxArrowLeft.classList.add(`disable-arrow`);
                        } else { 
                            lightboxArrowLeft.classList.remove(`disable-arrow`);
                            lightboxStartingRight.classList.add(`frame-transitioning-left`);
                            lightboxStartingLeft.querySelector(`iframe`).src = lightboxFrameVideoBaseUrl + setVideoId(-1);
	                            lightboxStartingLeft.querySelector(`h1`).textContent = setThumbnailText(setData(-1));
                        }

                        // Check If Video Index Has Video And Check For Browser Window Width And Set For Lightbox Or Full Screen Iframe On Mobile, Tablet

                        if (playListSorted[currentVideoIndex]) {
                        if (window.innerWidth > mediaQueryMobileBreakpoint || showPlaylist) {
                            startingLightboxActive.querySelector(`iframe`).src = lightboxFrameVideoBaseUrl + setVideoId(0) + `?autoplay=1`;
	                            startingLightboxActive.querySelector(`h1`).textContent = setThumbnailText(setData(0));
                        } else window.open(lightboxFrameVideoBaseUrl + setVideoId(0) + `?autoplay=1`);
                        }
                        if (window.innerWidth > mediaQueryMobileBreakpoint || showPlaylist) {
                            lightbox.classList.add(`show-lightbox`);
                            html.classList.add(`hide-scroll`);
                        } else {
                            lightbox_clear();
                        }      
                    }

                    initLightboxFrames();

                    // Render Grid Items In Playlist Container

                    // Grid Layout Handling

                    function gridItemsProcessing(outputList, playlist) {

                        const sortItemsBy = "number-descending";

                        // Removes Non Episode Numbered Items From Playlist

                        if (media_type === "youtube" && !outputList.every(item => item.episode === - 1)) {
                            outputList = outputList.filter(item => item.episode && item.episode !== -1);
                        }

                        const info = {
                            name: media_name,
                            type: media_type
                        };

                        return outputList.map(item => {
                            if (!item.id) {
                                console.error(`Playlist Item Failed To Load Due To Insufficient Data`);
                                failedItemTally++
                            } else if (!item.title) {
                                console.error(`Video Playlist Item ${item.id} Did Not Have A Title And Could Not Be Loaded.`)
                            } else if (item.title.toLowerCase() === `deleted video` || item.title.toLowerCase() === `private video`) {
                                console.error(`Video Playlist Item ${item.id} Could Not Be Loaded As Its Status Is: ${item.title}`);
                            } else if (sortItemsBy === `number-ascending` || sortItemsBy === `number-descending` && item.episode.toString() === `NaN`) {
                                console.error(`Video Playlist Item ${item.id} Entitled "${item.title}" Could Not Be Loaded As Number(s) Were Detected In Its Title Name But Could Not Generate An Episode Number.  This Can Occur If The Video Title Has Two Or More Numbers In It.  It Must Have Only One Number In Its Title Name That Pertains To Its Episode Number When Sorting Items In The "${sortItemsBy}" Mode.  If This Is The Case, Please Change The Title Name Accordingly.`)
                            } else return render_media_item(item, null, playlist, info);
                        }).join(``)
                    }

                    lightboxPlaylistContent.innerHTML = gridItemsProcessing(playListSorted, true);

                    // Event Listeners On Playlist Items Clicked

                    lightboxPlaylistContent.addEventListener(`click`, e => {
                        const clickedItem = e.target.closest(`[data-itemclickableplaylist="${media_name}_${media_type}"]`);
                        if (clickedItem) {
                            currentVideoIndex = playListSorted.findIndex(video => video.id === clickedItem.dataset.id);
                            toggleLightboxPlayerOrPlaylist();
                            initLightboxFrames();
                        }
                    });

                    // Records When Last Time Carousel Was Advanced To Avoid User Click/Auto Interval Conflict

                    let lastTimeAdvanced = new Date().getTime();

                    // Event Listener On Exit Button Click To Exit LightBox

                    lightboxCloseButton.addEventListener(`click`, lightbox_clear);

                    // Set lightboxArrowClicked To True For 500 Milliseconds Then Changed Back To False

                    function setLightboxArrowToggled(fastForwarded) {
                        lightboxArrowClicked = true;
                        setTimeout(() => {
                            lightboxArrowClicked = false;
                        }, 250)
                    }

                    // Mouse Hold Speed Through Carousel Frames Event Handling.  Variables For Checking For Mouse Down And Running Repeat Interval

                    let mouseDownInterval;
                    let mouseDown = false;

                    // Lightbox Fast Forward Speed

                    const video_lightbox_fast_forward_speed = 150;

                    // Fast Forward Handling

                    function toggleFastForward(enabled, item) {

                        if (enabled) {
                            lightboxFastForwardOverlay.classList.remove(`element-invisible`);
                            lightboxFramesContainer.classList.add(`fast-forward-transitioning`);
	                            lightboxFastForwardText.textContent = setThumbnailText(item);
	                        } else {
	                            lightboxFastForwardText.textContent = ``;
	                            lightboxFastForwardOverlay.classList.add(`element-invisible`);
	                            lightboxFramesContainer.classList.remove(`fast-forward-transitioning`);
	                        }
                    }

                    // Left Arrow Click/Mousedown And Auto Transitioning

                    function advanceLightboxLeft(fastForward) {
                        // Unload the outgoing player before the preloaded left
                        // frame becomes the sole autoplaying frame.
                        const activeIframe = lightbox.querySelector(`[data-frameposition="0"] iframe`);
                        if (activeIframe) {
                            activeIframe.src = ``;
                        }
                        currentVideoIndex -= 1;

                        if (playListSorted[currentVideoIndex - 1]) {
                        lightboxArrowLeft.classList.remove(`disable-arrow`);
                        } else { 
                        clearFastForwarding();
                        lightboxArrowLeft.classList.add(`disable-arrow`);
                        }

                        if (playListSorted[currentVideoIndex + 1]) {
                        lightboxArrowRight.classList.remove(`disable-arrow`)
                        } else lightboxArrowRight.classList.add(`disable-arrow`)

                        if (fastForward && mouseDown && playListSorted[currentVideoIndex]) {
                        lightboxArrowFastForwarded = true;
                        toggleFastForward(true, playListSorted[currentVideoIndex]);
                        } else {
                        toggleFastForward();
                        }
                        
                        setLightboxArrowToggled();

                        lightboxFrames.forEach(frame => frameTransitionHandler(frame, `left`, `lightbox`, currentVideoIndex));
                    }

                    // Right Arrow Click/Mousedown And Auto Transitioning

                    function advanceLightboxRight(fastForward) {
                        // Hidden iframes keep playing audio, so stop the outgoing
                        // frame before promoting the next one.
                        const activeIframe = lightbox.querySelector(`[data-frameposition="0"] iframe`);
                        if (activeIframe) {
                            activeIframe.src = ``;
                        }
                        currentVideoIndex += 1;

                        if (playListSorted[currentVideoIndex + 1]) {
                        lightboxArrowRight.classList.remove(`disable-arrow`);
                        } else { 
                        clearFastForwarding();
                        lightboxArrowRight.classList.add(`disable-arrow`);
                        }

                        if (playListSorted[currentVideoIndex - 1]) {
                        lightboxArrowLeft.classList.remove(`disable-arrow`);
                        } else lightboxArrowLeft.classList.add(`disable-arrow`);

                        if (fastForward && mouseDown && playListSorted[currentVideoIndex]) {
                        lightboxArrowFastForwarded = true;
                        toggleFastForward(true, playListSorted[currentVideoIndex]);
                        } else { 
                        toggleFastForward();
                        }
                        
                        setLightboxArrowToggled();

                        lightboxFrames.forEach(frame => frameTransitionHandler(frame, `right`, `lightbox`, currentVideoIndex));
                    }

                    // Left Arrow Click Event Handler

                    lightboxArrowLeft.addEventListener(`click`, () => {
                        if (!mouseDown) {
                        lastTimeAdvanced = new Date().getTime();
                        advanceLightboxLeft()
                        } else {
                        clearInterval(mouseDownInterval);
                        mouseDown = false;
                        }
                    });

                    // Right Arrow Click Event Handler

                    lightboxArrowRight.addEventListener(`click`, () => {
                        if (!mouseDown) {
                        lastTimeAdvanced = new Date().getTime();
                        advanceLightboxRight()
                        } else {
                        clearInterval(mouseDownInterval);
                        mouseDown = false;
                        }
                    });

                    // Left Arrow Mouse Down Event Handler

                    lightboxArrowLeft.addEventListener(`mousedown`, () => {
                        mouseDown = true;
                        setTimeout(() => {
                        if (mouseDown && playListSorted[currentVideoIndex - 1]) {
                            mouseDownInterval = setInterval(() => {
                            if (mouseDown) {
                                advanceLightboxLeft(true), video_lightbox_fast_forward_speed;
                            }
                            }, video_lightbox_fast_forward_speed);
                            lightboxArrowLeft.classList.add(`lightbox-arrow-hold-transitioning`);
                        }
                        }, 500)
                    });

                    // Right Arrow Mouse Down Event Handler

                    lightboxArrowRight.addEventListener(`mousedown`, () => {
                        mouseDown = true;
                        setTimeout(() => {
                        if (mouseDown && playListSorted[currentVideoIndex + 1]) {
                            mouseDownInterval = setInterval(() => {
                            if (mouseDown) {
                                advanceLightboxRight(true), video_lightbox_fast_forward_speed;
                            }
                            }, video_lightbox_fast_forward_speed);
                            lightboxArrowRight.classList.add(`lightbox-arrow-hold-transitioning`);
                        }
                        }, 500)
                    });

                    // Arrow Mouse Up Event Handling

                    function clearFastForwarding() {
                        mouseDown = false;
                        clearInterval(mouseDownInterval);
                        mouseDownInterval = ``;
                        lightboxArrowLeft.classList.remove(`lightbox-arrow-hold-transitioning`);
                        lightboxArrowRight.classList.remove(`lightbox-arrow-hold-transitioning`);
                        toggleFastForward();
                        setTimeout(() => { 
                        mouseDown = false;
                        clearInterval(mouseDownInterval);
                        lightboxArrowLeft.classList.remove(`lightbox-arrow-hold-transitioning`);
                        lightboxArrowRight.classList.remove(`lightbox-arrow-hold-transitioning`);
                        clearInterval(mouseDownInterval);
                        }, 500);
                    }

                    window.addEventListener(`mouseup`, clearFastForwarding);

                    // Playlist Button Event Listener

                    function toggleLightboxPlayerOrPlaylist(initialized) {
                        if (!initialized) {
                            showPlaylist = !showPlaylist;
                        }
                        if (showPlaylist) {
                            lightboxFrames.forEach(frame => {
                                frame.querySelector(`iframe`).src = ``;
                                frame.querySelector(`h1`).textContent = ``;
                            });
                            lightboxPlaylistButton.classList.add(`element-invisible`);
                            lightboxPlayerContainer.classList.add(`lightbox-container-off`);
                            lightboxPlaylistContainer.classList.remove(`lightbox-container-off`);
                        } else {
                            if (playlistButton) {
                                lightboxPlaylistButton.classList.remove(`element-invisible`);
                            } else lightboxPlaylistButton.classList.add(`element-invisible`);
                            lightboxPlayerContainer.classList.remove(`lightbox-container-off`);
                            lightboxPlaylistContainer.classList.add(`lightbox-container-off`);
                        }
                    }

                    toggleLightboxPlayerOrPlaylist(true);

                    lightboxPlaylistButton.addEventListener(`click`, () => toggleLightboxPlayerOrPlaylist());
                }

                // Lightbox and Carousel Arrow Click Event Handlers

                function frameTransitionHandler(frame, direction, type, currentVideoIndex, transitionType, fastForward) {
                
                    // Video Playlist Selected

                    const playListSorted = media_data;

                    // Lightbox Base Url Origin

                    const lightboxFrameVideoBaseUrl = "https://www.youtube.com/embed/";

                    // Frame Transition Time

                    const frameTransition = 500;

                    // Lightbox Fast Forward Speed

                    const video_lightbox_fast_forward_speed = 150;

                    // Set Thumbnail Episode Number Or Title Text

	                    function setThumbnailText(item) {
	                        return media_title_text(item);
	                    }
                    
                    const currentFrameData = playListSorted[currentVideoIndex];
                    const nextFrameData = playListSorted[currentVideoIndex + 1];
                    const previousFrameData = playListSorted[currentVideoIndex - 1];

                    const iframe = frame.querySelector(`iframe`);
                    const iframeText = frame.querySelector(`h1`);
                    // Arrow navigation loads the newly active video paused. Only
                    // the item that originally opened the lightbox autoplays.
                    const currentIframeUrl = currentFrameData ? lightboxFrameVideoBaseUrl + currentFrameData.id : null;
                    const nextIframeUrl = nextFrameData ? lightboxFrameVideoBaseUrl + nextFrameData.id : null;
                    const previousIframeUrl = previousFrameData ? lightboxFrameVideoBaseUrl + previousFrameData.id : null;
                    
                    const transitionSpeed = 500;

                    if (type === `carousel` && transitionType !== `carouselAutomated`) {
                        frame.classList.add(`carousel-fast-transition`);
                        setTimeout(() => frame.classList.remove(`carousel-fast-transition`) ,transitionSpeed)
                    }

                    if (fastForward) {
                        frame.classList.remove(`frame-transitioning-show-text`);
                    }

                    switch(frame.dataset.frameposition) {
                        case `-1`:
                        if (direction === `left`) {
                            if (type === `lightbox` && currentFrameData && 
                            iframe.src !== currentIframeUrl ) {
                                iframe.src = currentIframeUrl
	                                iframeText.textContent = setThumbnailText(currentFrameData);
                            }
                            if (type === `carousel` && currentFrameData) {
                            setCarouselThumbnail(frame, currentFrameData)
                            }
                            setTimeout(() => {
                            frame.classList.remove(`frame-transitioning-show-text`);
                            }, transitionSpeed)
                            frame.classList.remove(`frame-transition-left`);
                            frame.dataset.frameposition = 0;
                        }
                        if (direction === `right`) {
                            frame.classList.remove(`frame-transition-left`);
                            frame.classList.add(`frame-transition-right`);
                            frame.dataset.frameposition = 1;
                            setTimeout(() => { 
                            if (type === `lightbox` && nextFrameData) {
                                iframe.src = nextIframeUrl; 
	                                iframeText.textContent = setThumbnailText(nextFrameData);
                            }
                            if (type === `carousel` && nextFrameData) {
                                setCarouselThumbnail(frame, nextFrameData)
                            }
                            if (nextFrameData && !fastForward) {
                                frame.classList.add(`frame-transitioning-show-text`);
                            }
                            }, transitionSpeed)
                        }
                        break;
                        case `0`:
                        if (direction === `left`) {
                            frame.classList.add(`frame-transition-right`);
                            frame.dataset.frameposition = 1;
                            setTimeout(() => { 
                            if (type === `lightbox` && nextFrameData) {
                                iframe.src = nextIframeUrl;
	                                iframeText.textContent = setThumbnailText(nextFrameData);
                            }
                            if (type === `carousel` && nextFrameData) {
                                setCarouselThumbnail(frame, nextFrameData)
                            }
                            if (nextFrameData && !fastForward) {
                                frame.classList.add(`frame-transitioning-show-text`);
                            }
                            }, transitionSpeed)
                        }
                        if (direction === `right`) { 
                            frame.classList.add(`frame-transition-left`);
                            frame.dataset.frameposition = -1;
                            setTimeout(() => { 
                            if (type === `lightbox` && previousFrameData) {
                                iframe.src = previousIframeUrl;
	                                iframeText.textContent = setThumbnailText(previousFrameData);
                            }
                            if (type === `carousel` && previousFrameData) {
                                setCarouselThumbnail(frame, previousFrameData)
                            }
                            if (previousFrameData && !fastForward) {
                                frame.classList.add(`frame-transitioning-show-text`);
                            }
                            }, transitionSpeed)
                        }
                        break;
                        case `1`:
                        if (direction === `left`) {
                            frame.classList.remove(`frame-transition-right`);
                            frame.classList.add(`frame-transition-left`);
                            frame.dataset.frameposition = -1;
                            setTimeout(() => { 
                            if (type === `lightbox` && previousFrameData) {
                                iframe.src = previousIframeUrl;
	                                iframeText.textContent = setThumbnailText(previousFrameData);
                            }
                            if (type === `carousel` && previousFrameData) {
                                setCarouselThumbnail(frame, previousFrameData)
                            }
                            if (previousFrameData && !fastForward) {
                                frame.classList.add(`frame-transitioning-show-text`);
                            }
                            }, transitionSpeed) 
                        }
                        if (direction === `right`) { 
                            if (type === `lightbox` && currentFrameData && 
                            iframe.src !== currentIframeUrl) {
                                iframe.src = currentIframeUrl;
                                iframeText.innerText = setThumbnailText(currentFrameData);
                            }
                            if (type === `carousel` && currentFrameData) {
                            setCarouselThumbnail(frame, currentFrameData)
                            }
                            if (currentFrameData && !fastForward) {
                            frame.classList.add(`frame-transitioning-show-text`);
                                setTimeout(() => {
                                    frame.classList.remove(`frame-transitioning-show-text`);
                                }, transitionSpeed)
                            }
                            frame.classList.remove(`frame-transition-right`);
                            frame.dataset.frameposition = 0;
                        }
                        break;
                        }
                    }

                    // Clear Lightbox

                    function lightbox_clear() {
                        const lightbox = media_lightbox_div;
                        lightbox.innerHTML = ``;
                        lightboxToggled = false;
                        showPlaylist = false;
                        lightbox.classList.remove(`show-lightbox`);
                        document.querySelector(`html`).classList.remove(`hide-scroll`);
                    }

                    // Monitor Click Of Video Items Rendered

                    window.addEventListener("click", e => {
                        const target = e.target instanceof Element ? e.target : null;
                        const node = target ? target.closest('a[data-itemclickable="true"]') : null;
                        if (!node || node.dataset.itemclickablemediatype !== "video") {
                            return;
                        }
                        if (node.closest('[data-lightboxplaylistcontent="true"]')) {
                            return;
                        }
                        if (node.dataset.itemclickableplaylist !== `${media_name}_${media_type}`) {
                            return;
                        }

                        const videoEmbedBaseUrl = media_type === "vimeo" ? "https://player.vimeo.com/video/" : "https://www.youtube.com/embed/";
                        const embedUrl = `${videoEmbedBaseUrl}${node.dataset.id}`;

                        document.dispatchEvent(new CustomEvent("mediaApiWidgetItemClick", {
                            bubbles: true,
                            detail: {
                                playlistName: media_name,
                                mediaType: media_type,
                                embedUrl,
                                itemId: node.dataset.id,
                                showLightbox: node.dataset.showlightbox !== "false",
                                element: node
                            }
                        }));

                        if (node.dataset.showlightbox === "false") {
                            return;
                        }

                        const lightboxImage = node.dataset.lightboxshowlogoimgurl ? node.dataset.lightboxshowlogoimgurl : null;
                        const lightboxThemeColor = node.dataset.lightboxthemecolor ? node.dataset.lightboxthemecolor : null;
                        const lightboxFont = node.dataset.lightboxfont ? node.dataset.lightboxfont : null;
                        const lightBoxStyling = {
                            image : lightboxImage,
                            themeColor : lightboxThemeColor,
                            font : lightboxFont
                        };

                        if (node.dataset.lightboxshowplaylist && node.dataset.lightboxshowplaylist === "true") {
                            video_lightbox_activate_handler(node, true, lightBoxStyling);
                        } else video_lightbox_activate_handler(node, false);
                    });
                    // Clear Lightbox If Window Is Resized Below 872

                    window.addEventListener(`resize`, () => {
                        if (!showPlaylist && window.innerWidth < 872) {
                            lightbox_clear();
                        }
                    });

                    // Builds the /podcast/player URL for a custom RSS feed, URL-encoding
                    // every query value so feed/shortcode-derived data cannot break out
                    // of the query string (or, downstream, an attribute).

                    function maw_build_custom_player_url(rssUrl, itemClicked) {
                        const ds = itemClicked.dataset;
                        const params = new URLSearchParams();
                        params.set("url", rssUrl);
                        params.set("track", ds.trackselect || "");
                        params.set("mode", ds.podcastplayermode || "");
                        params.set("buttoncolor", ds.podcastplayerbuttoncolor || "");
                        params.set("color1", ds.podcastplayercolor || "");
                        params.set("progressbarcolor", ds.podcastprogressplayerbarcolor || "");
                        params.set("highlightcolor", ds.podcastplayerhighlightcolor || "");
                        params.set("font", ds.podcastplayerfont || "");
                        params.set("scrollcolor", ds.podcastplayerscrollcolor || "");
                        params.set("textcolor", ds.podcastplayertextcolor || "");
                        params.set("autoplay", "1");
                        if (ds.singleepisode) {
                            params.set("singleepisode", ds.singleepisode);
                        } else if (ds.startepisode) {
                            params.set("startepisode", ds.startepisode);
                        }
                        if (ds.showepisodedateaftertitle === "true") {
                            params.set("adddatetotitle", "true");
                        }
                        return `${window.location.origin}/podcast/player?${params.toString()}`;
                    }

                    // Computes the podcast embed URL for a clicked audio item (shared by event dispatch and lightbox handler)

                    function compute_audio_embed_url(itemClicked) {
                        if (podcast_platform === "omny") {
                            const { channel } = media_data;
                            const showName = channel.item[0].link.split("/")[4];
                            return `https://omny.fm/shows/${showName}/playlists/podcast/embed?selectedClip=${itemClicked.dataset.id}&autoplay=1`;
                        }
                        if (podcast_platform === "soundcloud") {
                            const showEpisodeUrl = media_data.channel.item[0].link;
                            const spliced = showEpisodeUrl.split("/");
                            const urlOutput = `${spliced[0]}/${spliced[1]}/${spliced[2]}/${spliced[3]}`;
                            return `https://w.soundcloud.com/player/?url=${urlOutput}&start_track=${itemClicked.dataset.trackselect}&auto_play=true`;
                        }
                        if (podcast_platform === "buzzsprout") {
                            const { channel } = media_data;
                            const playlistId = channel.rssUrl.split("/")[3].split(".")[0];
                            const episodeId = itemClicked.dataset.id.split("-")[1];
                            return `https://www.buzzsprout.com/${playlistId}/${episodeId}`;
                        }
                        if (podcast_platform === "embed") {
                            return typeof media_data === "string" ? media_data : null;
                        }
                        if (podcast_platform === "custom") {
                            const rssUrl = media_data.channel?.rssUrl;
                            if (!rssUrl) return null;
                            return maw_build_custom_player_url(rssUrl, itemClicked);
                        }
                        return null;
                    }

                    // Podcast Audio Lightbox Activate Handler

                    function audio_lightbox_activate_handler(itemClicked) {

                        // Set Ifram Url Based Upon Podcast Player Type

                        let embedPlayerUrl;

                        if (podcast_platform === "omny") {

                            // Get Podcast Data, All Episodes, And Episode Clicked On
                        
                            const { channel } = media_data;

                            const podcastInfo = { 
                                copyright: channel.copyright, 
                                title: channel.title, 
                                description: channel.description,
                                playlistId: channel.playlistId,
                                collectionViewUrl: channel.collectionViewUrl 
                            };

                            const showName = channel.item[0].link.split("/")[4];

                            embedPlayerUrl = `https://omny.fm/shows/${showName}/playlists/podcast/embed?selectedClip=${itemClicked.dataset.id}&autoplay=1`;
                        }

                        if (podcast_platform === "soundcloud") {
                            const showEpisodeUrl = media_data.channel.item[0].link;
                            const spliced = showEpisodeUrl.split("/");
                            const urlOutput = `${spliced[0]}/${spliced[1]}/${spliced[2]}/${spliced[3]}`;
                            embedPlayerUrl = `https://w.soundcloud.com/player/?url=${urlOutput}&start_track=${itemClicked.dataset.trackselect}&auto_play=true`;
                        }

                        if (podcast_platform === "buzzsprout") {
                            const { channel } = media_data;
                            const playlistId = channel.rssUrl.split("/")[3].split(".")[0];
                            const episodeId = itemClicked.dataset.id.split("-")[1];
                            embedPlayerUrl = `https://www.buzzsprout.com/${playlistId}/${episodeId}`;
                        }

                        if (podcast_platform === "embed") {
                            embedPlayerUrl = media_data;
                        }

                        if (podcast_platform === "custom") {
                            const rssUrl = media_data.channel?.rssUrl;
                            if (!rssUrl) return;
                            embedPlayerUrl = maw_build_custom_player_url(rssUrl, itemClicked);
                        }

                        // If Window Resolution Is Less Than 872px, Open New Window In Apple Podcasts

                        if (window.innerWidth < 872) {
                            window.open(embedPlayerUrl);
                            return
                        }
                        
                        // Init Lightbox

                        const lightbox = media_lightbox_div;

                        lightboxToggled = true;
                        showPlaylist = true;
                        lightbox.classList.add(`show-lightbox`);
                        document.querySelector(`html`).classList.add(`hide-scroll`);

                        // Build the lightbox via DOM nodes so the embed URL is assigned as
                        // an iframe.src property and is never parsed as HTML — even if a
                        // feed or shortcode attribute contains markup.

                        lightbox.replaceChildren();

                        const contentContainer = document.createElement("div");
                        contentContainer.className = "lightbox-content-container";

                        const boxFrame = document.createElement("div");
                        boxFrame.className = "lightbox-box-frame";

                        const embedIframe = document.createElement("iframe");
                        embedIframe.className = "lightbox-podcast-embed-player";
                        embedIframe.setAttribute("allow", "autoplay");
                        embedIframe.src = embedPlayerUrl;

                        boxFrame.appendChild(embedIframe);
                        contentContainer.appendChild(boxFrame);

                        const closeButtonEl = document.createElement("div");
                        closeButtonEl.className = "lightbox-close-button";
                        closeButtonEl.dataset.hideonidle = "true";
                        closeButtonEl.dataset.lightboxclosebutton = "true";
                        closeButtonEl.textContent = "X";

                        lightbox.appendChild(contentContainer);
                        lightbox.appendChild(closeButtonEl);

                        // Monitor Click Of Close Button

                        lightbox.querySelector(`[data-lightboxclosebutton="true"]`).addEventListener("click", () => {
                            lightbox_clear();
                        });

                        // Monitor Mouse Movement To Hide Close Button If Idle For More Than 5 Seconds

                        const closeButton = document.querySelector(`[data-lightboxclosebutton="true"]`);

                        let lastTimeMouseMoved;

                        function audioLightboxMouseMoveHandler(e) {
                            lastTimeMouseMoved = new Date().getTime();
                            closeButton.classList.remove("element-invisible");
                            setTimeout(() => {
                                if (new Date().getTime() - lastTimeMouseMoved >= 5000 && !e.target.dataset.hideonidle) {
                                    closeButton.classList.add("element-invisible");
                                }
                            }, 5000)
                        }

                        lightbox.addEventListener("mousemove", audioLightboxMouseMoveHandler);
                        lightbox.addEventListener("click", audioLightboxMouseMoveHandler);
                    }

                    // Monitor Click Of Podcast Audio Items Rendered

                    window.addEventListener("click", e => {
                        const target = e.target instanceof Element ? e.target : null;
                        const node = target ? target.closest('a[data-itemclickable="true"]') : null;
                        if (!node) {
                            return;
                        }

                        if (
                            node.dataset.itemclickableplaylist === `${media_name}_${media_type}` &&
                            node.dataset.itemclickablemediatype === "audio"
                        ) {
                            document.dispatchEvent(new CustomEvent("mediaApiWidgetItemClick", {
                                bubbles: true,
                                detail: {
                                    playlistName: media_name,
                                    mediaType: media_type,
                                    embedUrl: compute_audio_embed_url(node),
                                    itemId: node.dataset.id,
                                    showLightbox: node.dataset.showlightbox !== "false",
                                    element: node
                                }
                            }));

                            if (node.dataset.lightboxshowplaylist === "true" && node.dataset.showlightbox !== "false") {
                                audio_lightbox_activate_handler(node);
                            }
                        }
                    });
                }

// -- Media Card Carousel --
//
// Enhances server-rendered [data-maw-carousel] markup. The viewport is a native
// horizontal scroll container with scroll snapping, so touch swiping, trackpad
// and Shift+wheel scrolling, and focus scrolling are the browser's own. This
// module adds container-based card sizing, the arrow buttons, mouse
// click-and-drag, keyboard keys, and suppression of the click that ends a drag
// so a drag can never open a lightbox. Arrow, keyboard and drag-release moves
// are animated by the script over --maw-carousel-transition-duration, since
// native smooth scrolling runs at a fixed, browser-chosen speed. Cards are
// never cloned or re-rendered, and playlist/lightbox initialization is never
// touched: card clicks keep going to the existing delegated handlers.

(function () {
    "use strict";

    var ROOT_SELECTOR  = "[data-maw-carousel]";
    var DRAG_THRESHOLD = 6;    // px of mouse travel before a press becomes a drag
    var SETTLE_DELAY   = 140;  // ms without scroll events that counts as settled
    var DEFAULT_MIN    = 400;  // px, if the minimum item width cannot be resolved
    var DEFAULT_SPEED  = 250;  // ms, the transition duration when none resolves (shortcode default 0.25s)

    if (typeof WeakMap !== "function") {
        // Without it the markup still scrolls natively; arrows stay hidden.
        return;
    }

    /** @type {WeakMap<Element, Carousel>} */
    var registry = new WeakMap();
    var uid      = 0;

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
    }

    // Cubic ease-in-out, t in [0, 1].
    function ease(t) {
        return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    }

    function childWith(parent, attribute, value) {
        for (var i = 0; i < parent.children.length; i++) {
            var child = parent.children[i];
            if (child.hasAttribute(attribute) && (value === undefined || child.getAttribute(attribute) === value)) {
                return child;
            }
        }
        return null;
    }

    function setDisabled(button, disabled) {
        if (disabled) {
            button.setAttribute("aria-disabled", "true");
        } else {
            button.removeAttribute("aria-disabled");
        }
    }

    function Carousel(el) {
        this.el       = el;
        this.viewport = childWith(el, "data-maw-carousel-viewport");
        this.track    = this.viewport ? childWith(this.viewport, "data-maw-carousel-track") : null;
        this.left     = childWith(el, "data-maw-carousel-arrow", "left");
        this.right    = childWith(el, "data-maw-carousel-arrow", "right");
        this.step     = el.getAttribute("data-maw-carousel-step") === "group" ? "group" : "item";

        this.index    = 0;        // first fully visible item
        this.visible  = 1;        // cards that fit side by side
        this.maxIndex = 0;        // last valid first-visible item
        this.pitch    = 0;        // card width + gap, px
        this.mode     = "static"; // static (all fit) | gutter | overlay
        this.rtl      = false;

        this.programmatic       = false;
        this.drag               = null;
        this.suppressClickUntil = 0;
        this.settleTimer        = null;
        this.scrollFrame        = 0;
        this.layoutFrame        = 0;
        this.animFrame          = 0;      // non-zero while a move is animating
        this.layoutKey          = "";
        this.listeners          = [];
        this.observer           = null;
        this.probe              = null;
        this.generatedId        = false;
        this.destroyed          = false;
        this.hasScrollEnd       = false;
        this.labels             = null;
    }

    Carousel.prototype.on = function (target, type, handler, options) {
        target.addEventListener(type, handler, options);
        this.listeners.push([target, type, handler, options]);
    };

    Carousel.prototype.mount = function () {
        var self = this;

        if (!this.viewport || !this.track || !this.left || !this.right) {
            return false;
        }

        if (!this.viewport.id) {
            uid += 1;
            this.viewport.id = "maw-carousel-" + uid;
            this.generatedId = true;
        }

        this.labels = { left: this.left.getAttribute("aria-label"), right: this.right.getAttribute("aria-label") };
        this.left.setAttribute("aria-controls", this.viewport.id);
        this.right.setAttribute("aria-controls", this.viewport.id);
        this.hasScrollEnd = "onscrollend" in this.viewport;

        this.on(this.left, "click", function (e) { self.onArrow(e, "left"); });
        this.on(this.right, "click", function (e) { self.onArrow(e, "right"); });
        this.on(this.viewport, "scroll", function () { self.onScroll(); }, { passive: true });
        if (this.hasScrollEnd) {
            this.on(this.viewport, "scrollend", function () { self.settle(); });
        }
        this.on(this.viewport, "pointerdown", function (e) { self.onPointerDown(e); });
        this.on(this.viewport, "pointermove", function (e) { self.onPointerMove(e); });
        this.on(this.viewport, "pointerup", function (e) { self.endDrag(e); });
        this.on(this.viewport, "pointercancel", function (e) { self.endDrag(e); });
        this.on(this.viewport, "lostpointercapture", function (e) { self.endDrag(e); });
        // A sideways wheel or trackpad scroll takes over from an animated move.
        this.on(this.viewport, "wheel", function (e) {
            if (e.shiftKey || Math.abs(e.deltaX) > Math.abs(e.deltaY)) {
                self.interruptAnimation();
            }
        }, { passive: true });
        this.on(this.viewport, "dragstart", function (e) {
            if (self.mode !== "static") {
                e.preventDefault();
            }
        });
        // Capture phase: runs before the delegated card handlers on window.
        this.on(this.viewport, "click", function (e) { self.onClickCapture(e); }, true);
        this.on(this.viewport, "keydown", function (e) { self.onKeydown(e); });
        this.on(this.viewport, "focusin", function (e) { self.onFocusIn(e); });

        if (typeof ResizeObserver === "function") {
            this.observer = new ResizeObserver(function () { self.scheduleLayout(); });
            this.observer.observe(this.el);
        } else {
            this.on(window, "resize", function () { self.scheduleLayout(); });
            this.on(window, "orientationchange", function () { self.scheduleLayout(); });
        }

        this.layout(true);

        return true;
    };

    Carousel.prototype.destroy = function () {
        if (this.destroyed) {
            return;
        }
        this.destroyed = true;

        this.listeners.forEach(function (entry) {
            entry[0].removeEventListener(entry[1], entry[2], entry[3]);
        });
        this.listeners = [];

        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }

        clearTimeout(this.settleTimer);
        if (this.scrollFrame) { cancelAnimationFrame(this.scrollFrame); }
        if (this.layoutFrame) { cancelAnimationFrame(this.layoutFrame); }
        this.cancelAnimation();

        if (this.probe && this.probe.parentNode) {
            this.probe.parentNode.removeChild(this.probe);
        }

        // Return the markup to its server-rendered state so the same element
        // can be initialized again cleanly.
        this.el.style.removeProperty("--maw-carousel-item-width");
        this.el.style.removeProperty("--maw-carousel-gutter");
        this.el.removeAttribute("data-maw-carousel-mode");
        this.viewport.removeAttribute("data-maw-dragging");
        this.viewport.removeAttribute("data-maw-animating");
        this.viewport.setAttribute("tabindex", "0");
        if (this.generatedId) {
            this.viewport.removeAttribute("id");
        }
        [this.left, this.right].forEach(function (button) {
            button.hidden = true;
            button.removeAttribute("aria-controls");
            button.removeAttribute("aria-disabled");
        });
        if (this.labels) {
            this.left.setAttribute("aria-label", this.labels.left);
            this.right.setAttribute("aria-label", this.labels.right);
        }

        if (registry.get(this.el) === this) {
            registry.delete(this.el);
        }
    };

    Carousel.prototype.slides = function () {
        var out = [];
        for (var i = 0; i < this.track.children.length; i++) {
            if (this.track.children[i].hasAttribute("data-maw-carousel-slide")) {
                out.push(this.track.children[i]);
            }
        }
        return out;
    };

    // A hidden element whose computed style resolves the carousel's CSS
    // variables: lengths through its margin and padding, the transition
    // duration through transition-duration (transition-property is none, so
    // nothing on it ever animates).
    Carousel.prototype.probeElement = function () {
        if (!this.probe || this.probe.parentNode !== this.el) {
            var probe = this.el.ownerDocument.createElement("span");
            probe.setAttribute("aria-hidden", "true");
            probe.style.cssText =
                "position:absolute;top:0;left:0;width:0;height:0;overflow:hidden;visibility:hidden;pointer-events:none;border:0;" +
                "margin:0 var(--maw-carousel-gap, 48px) 0 var(--maw-carousel-min, 400px);" +
                "padding:0 var(--maw-carousel-arrow-offset, 12px) 0 var(--maw-carousel-arrow-size, 48px);" +
                "transition-property:none;transition-duration:var(--maw-carousel-transition-duration, " + DEFAULT_SPEED + "ms);";
            this.el.appendChild(probe);
            this.probe = probe;
        }
        return this.probe;
    };

    // Resolves the configured lengths (px, rem, em, %, vw, calc(), …) to px in
    // one style read. Margin and padding percentages resolve against the
    // carousel's width, so a percentage means a share of the carousel.
    Carousel.prototype.measure = function () {
        var style = window.getComputedStyle(this.probeElement());

        return {
            min:    parseFloat(style.marginLeft) || 0,
            gap:    parseFloat(style.marginRight) || 0,
            arrow:  parseFloat(style.paddingLeft) || 0,
            offset: parseFloat(style.paddingRight) || 0
        };
    };

    // The transition duration in ms. The browser resolves it, so var()
    // references to the site's own variables and calc()/min()/max()/clamp()
    // work; it is read on every move, so a variable that changes later (a
    // media query, a theme switch) applies to the next move. An undefined
    // variable falls back to the default.
    Carousel.prototype.transitionDuration = function () {
        var value = window.getComputedStyle(this.probeElement()).transitionDuration || "";
        var match = /^\s*(\d*\.?\d+(?:e[+-]?\d+)?)(m?s)\b/i.exec(value);
        if (!match) {
            return DEFAULT_SPEED;
        }
        var ms = parseFloat(match[1]) * (match[2].toLowerCase() === "ms" ? 1 : 1000);
        return isFinite(ms) && ms >= 0 ? ms : DEFAULT_SPEED;
    };

    Carousel.prototype.scheduleLayout = function () {
        var self = this;
        if (this.destroyed || this.layoutFrame) {
            return;
        }
        this.layoutFrame = requestAnimationFrame(function () {
            self.layoutFrame = 0;
            self.layout(false);
        });
    };

    // Sizes the cards from the carousel's own width (not the window's):
    // visible = max(1, floor((W + G) / (M + G))), clamped to the item count,
    // with W split evenly between the visible cards and their gaps. When cards
    // overflow, arrows take gutters beside the cards as long as at least two
    // cards still fit; otherwise they overlay the cards so a single card can
    // use the full width (shrinking below the minimum if it must).
    Carousel.prototype.layout = function (force) {
        if (this.destroyed || !this.el.isConnected) {
            return;
        }

        var style  = window.getComputedStyle(this.el);
        var width  = this.el.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);
        var slides = this.slides();
        var count  = slides.length;

        if (!(width > 0)) {
            // Hidden (display: none, collapsed tab, …): lay out once visible.
            return;
        }

        var sizes = this.measure();
        var key   = [width, count, sizes.min, sizes.gap, sizes.arrow, sizes.offset].join("|");
        if (!force && key === this.layoutKey) {
            return; // Height-only change (images loading, text wrapping).
        }
        this.layoutKey = key;

        var min = sizes.min > 0 ? sizes.min : DEFAULT_MIN;
        var gap = sizes.gap > 0 ? sizes.gap : 0;
        var fit = function (available) {
            return Math.max(1, Math.floor((available + gap) / (min + gap)));
        };

        var mode      = "static";
        var available = width;
        var gutter    = sizes.arrow + sizes.offset;
        var visible   = Math.min(fit(width), Math.max(count, 1));

        if (count > fit(width)) {
            var narrowed = width - 2 * gutter;
            if (narrowed > 0 && fit(narrowed) >= 2) {
                mode      = "gutter";
                available = narrowed;
                visible   = Math.min(fit(narrowed), count);
            } else {
                mode    = "overlay";
                visible = Math.min(fit(width), count);
            }
        }

        var itemWidth = (available - (visible - 1) * gap) / visible;
        if (!(itemWidth > 0)) {
            visible   = 1;
            itemWidth = available;
        }

        this.el.style.setProperty("--maw-carousel-item-width", itemWidth + "px");
        this.el.style.setProperty("--maw-carousel-gutter", (mode === "gutter" ? gutter : 0) + "px");
        this.el.setAttribute("data-maw-carousel-mode", mode);

        this.mode     = mode;
        this.visible  = visible;
        this.pitch    = itemWidth + gap;
        this.maxIndex = Math.max(0, count - visible);
        this.rtl      = window.getComputedStyle(this.viewport).direction === "rtl";

        // The left/right buttons are physical; in right-to-left content the
        // left one moves toward the end, so their labels swap.
        this.left.setAttribute("aria-label", this.rtl ? this.labels.right : this.labels.left);
        this.right.setAttribute("aria-label", this.rtl ? this.labels.left : this.labels.right);

        var nav = mode !== "static";
        this.left.hidden  = !nav;
        this.right.hidden = !nav;

        // A scrollable viewport is a keyboard stop (arrow keys scroll it);
        // one whose cards all fit is not.
        if (nav) {
            this.viewport.setAttribute("tabindex", "0");
        } else if (this.viewport.ownerDocument.activeElement !== this.viewport) {
            this.viewport.removeAttribute("tabindex");
        }

        // Keep the current first item where possible, clamped to the new range.
        this.index = clamp(this.index, 0, this.maxIndex);
        this.scrollToIndex(this.index, false);
        this.update();
    };

    Carousel.prototype.position = function () {
        var x = this.viewport.scrollLeft;
        return this.rtl ? -x : x;
    };

    Carousel.prototype.maxScroll = function () {
        return Math.max(0, this.viewport.scrollWidth - this.viewport.clientWidth);
    };

    // The last position is the scroll end itself, so the final card always
    // sits flush with the viewport edge and no blank space follows it.
    Carousel.prototype.offsetFor = function (index) {
        var max = this.maxScroll();
        return index >= this.maxIndex ? max : Math.min(index * this.pitch, max);
    };

    // Fractional item position: the logical target while an arrow-driven
    // scroll is animating, the real scroll position otherwise.
    Carousel.prototype.fraction = function () {
        if (this.programmatic) {
            return this.index;
        }
        var pos = this.position();
        if (this.maxIndex === 0 || pos <= 1) {
            return 0;
        }
        if (pos >= this.maxScroll() - 1) {
            return this.maxIndex;
        }
        return this.pitch > 0 ? clamp(pos / this.pitch, 0, this.maxIndex) : 0;
    };

    Carousel.prototype.scrollToIndex = function (index, smooth) {
        var offset   = this.offsetFor(index);
        var left     = this.rtl ? -offset : offset;
        var duration = smooth && !reducedMotion() ? this.transitionDuration() : 0;

        this.programmatic = true;
        clearTimeout(this.settleTimer);

        // A move started mid-animation continues from where the previous one
        // got to. Snapping stays off in between, so nothing jumps.
        this.cancelAnimation();

        if (duration > 0 && Math.abs(left - this.viewport.scrollLeft) > 1) {
            this.animateTo(left, duration);
            return;
        }

        this.viewport.removeAttribute("data-maw-animating");
        if (typeof this.viewport.scrollTo === "function") {
            this.viewport.scrollTo({ left: left, behavior: "auto" });
        } else {
            this.viewport.scrollLeft = left;
        }

        this.armSettle(SETTLE_DELAY);
    };

    // Scrolls the viewport to `left` (a physical scrollLeft) over `duration`
    // ms. Native smooth scrolling cannot be timed, so the script animates.
    // Snapping is off while it runs (data-maw-animating), otherwise each
    // intermediate frame would snap back to a card; the move ends on a card.
    Carousel.prototype.animateTo = function (left, duration) {
        var self     = this;
        var viewport = this.viewport;
        var from     = viewport.scrollLeft;
        var start    = performance.now();

        viewport.setAttribute("data-maw-animating", "");

        var frame = function (now) {
            var t = clamp((now - start) / duration, 0, 1);
            viewport.scrollLeft = t < 1 ? from + (left - from) * ease(t) : left;

            if (t < 1) {
                self.animFrame = requestAnimationFrame(frame);
                return;
            }

            self.animFrame = 0;
            viewport.removeAttribute("data-maw-animating");
            self.settle();
        };

        this.animFrame = requestAnimationFrame(frame);
    };

    // Stops a running animation where it is, leaving snapping off for the
    // caller to restore. Returns whether one was running.
    Carousel.prototype.cancelAnimation = function () {
        if (!this.animFrame) {
            return false;
        }
        cancelAnimationFrame(this.animFrame);
        this.animFrame = 0;
        return true;
    };

    // The visitor takes over mid-animation (touch, sideways wheel): native
    // scrolling and snapping carry on from where the animation got to.
    Carousel.prototype.interruptAnimation = function () {
        if (!this.cancelAnimation()) {
            return;
        }
        this.viewport.removeAttribute("data-maw-animating");
        this.programmatic = false;
        this.armSettle(SETTLE_DELAY);
    };

    Carousel.prototype.armSettle = function (delay) {
        var self = this;
        clearTimeout(this.settleTimer);
        this.settleTimer = setTimeout(function () { self.settle(); }, delay);
    };

    Carousel.prototype.onScroll = function () {
        var self = this;

        if (!this.programmatic && !this.scrollFrame) {
            this.scrollFrame = requestAnimationFrame(function () {
                self.scrollFrame = 0;
                if (!self.programmatic) {
                    self.index = Math.round(self.fraction());
                    self.update();
                }
            });
        }

        // Without scrollend, a short pause in scroll events marks the end.
        if (!this.hasScrollEnd) {
            this.armSettle(SETTLE_DELAY);
        }
    };

    Carousel.prototype.settle = function () {
        clearTimeout(this.settleTimer);
        this.settleTimer = null;

        // Every animation frame is a scroll, so scrollend can fire mid-move;
        // the animation settles itself when it ends.
        if (this.destroyed || this.animFrame || (this.drag && this.drag.moved)) {
            return;
        }

        this.programmatic = false;
        if (this.viewport.hasAttribute("data-maw-dragging")) {
            this.viewport.removeAttribute("data-maw-dragging");
        }
        this.index = Math.round(this.fraction());
        this.update();
    };

    Carousel.prototype.update = function () {
        var atStart;
        var atEnd;

        if (this.mode === "static") {
            atStart = atEnd = true;
        } else if (this.programmatic) {
            atStart = this.index <= 0;
            atEnd   = this.index >= this.maxIndex;
        } else {
            var pos = this.position();
            atStart = pos <= 1;
            atEnd   = pos >= this.maxScroll() - 1;
        }

        setDisabled(this.left, this.rtl ? atEnd : atStart);
        setDisabled(this.right, this.rtl ? atStart : atEnd);
    };

    Carousel.prototype.goTo = function (index) {
        var target = clamp(index, 0, this.maxIndex);
        if (target === this.index && (this.animFrame || Math.abs(this.position() - this.offsetFor(target)) <= 1)) {
            return; // Already there, or already on its way.
        }
        this.index = target;
        this.scrollToIndex(target, true);
        this.update();
    };

    // direction: 1 toward the end, -1 toward the start. From a position
    // between two cards, the first step lands on the neighbouring card.
    Carousel.prototype.go = function (direction, step) {
        if (this.mode === "static") {
            return;
        }
        var f = this.fraction();
        this.goTo(direction > 0 ? Math.floor(f + 0.02) + step : Math.ceil(f - 0.02) - step);
    };

    Carousel.prototype.arrowStep = function () {
        return this.step === "group" ? this.visible : 1;
    };

    Carousel.prototype.onArrow = function (event, side) {
        event.preventDefault();
        if (this.mode === "static" || event.currentTarget.getAttribute("aria-disabled") === "true") {
            return;
        }
        var towardEnd = side === "right" ? !this.rtl : this.rtl;
        this.go(towardEnd ? 1 : -1, this.arrowStep());
    };

    Carousel.prototype.onKeydown = function (event) {
        if (event.target !== this.viewport || this.mode === "static" || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        switch (event.key) {
            case "ArrowLeft":
                this.go(this.rtl ? 1 : -1, 1);
                break;
            case "ArrowRight":
                this.go(this.rtl ? -1 : 1, 1);
                break;
            case "PageUp":
                this.go(-1, this.visible);
                break;
            case "PageDown":
                this.go(1, this.visible);
                break;
            case "Home":
                this.goTo(0);
                break;
            case "End":
                this.goTo(this.maxIndex);
                break;
            default:
                return;
        }

        event.preventDefault();
    };

    // Focus moving onto (or into) a card that is partly or fully offscreen
    // brings that whole card into view at a valid carousel position. focusin
    // fires before the browser's own scroll-into-view, so the check waits a
    // frame and works from where the card actually ended up.
    Carousel.prototype.onFocusIn = function (event) {
        var self   = this;
        var target = event.target;
        if (target === this.viewport || this.mode === "static" || !target || typeof target.closest !== "function") {
            return;
        }

        var slide = target.closest("[data-maw-carousel-slide]");
        if (!slide || slide.parentNode !== this.track) {
            return;
        }

        requestAnimationFrame(function () {
            if (self.destroyed || self.drag) {
                return;
            }

            var box  = slide.getBoundingClientRect();
            var view = self.viewport.getBoundingClientRect();
            var i    = self.slides().indexOf(slide);

            if (box.left < view.left - 1) {
                self.goTo(i);
            } else if (box.right > view.right + 1) {
                self.goTo(i - self.visible + 1);
            } else {
                self.index = Math.round(self.fraction());
                self.update();
            }
        });
    };

    // Mouse click-and-drag scrolling. Touch and pen use native scrolling.
    Carousel.prototype.onPointerDown = function (event) {
        if (event.pointerType !== "mouse") {
            // Touch and pen scroll natively, taking over from an animated move.
            this.interruptAnimation();
            return;
        }
        if (event.button !== 0 || this.mode === "static") {
            return;
        }

        this.drag = {
            id:     event.pointerId,
            x:      event.clientX,
            dx:     0,
            scroll: this.viewport.scrollLeft,
            start:  Math.round(this.fraction()),
            moved:  false
        };
    };

    Carousel.prototype.onPointerMove = function (event) {
        var drag = this.drag;
        if (!drag || event.pointerId !== drag.id) {
            return;
        }

        if (!event.buttons) {
            // The button was released where no pointerup reached us.
            this.endDrag(event);
            return;
        }

        var dx = event.clientX - drag.x;

        if (!drag.moved) {
            if (Math.abs(dx) < DRAG_THRESHOLD) {
                return;
            }
            drag.moved = true;
            this.programmatic = false;
            clearTimeout(this.settleTimer);
            try {
                this.viewport.setPointerCapture(event.pointerId);
            } catch (err) {
                // Capture is an enhancement; dragging works without it.
            }
            this.viewport.setAttribute("data-maw-dragging", "");
            // An animated move may still be running (or have moved the row
            // since the press): the drag continues from where the row is now,
            // and counts from the card nearest it rather than the move's
            // target. Read before the animation hands snapping back, so
            // nothing snaps.
            drag.scroll = this.viewport.scrollLeft;
            drag.start  = Math.round(this.fraction());
            if (this.cancelAnimation()) {
                this.viewport.removeAttribute("data-maw-animating");
            }
            var selection = window.getSelection ? window.getSelection() : null;
            if (selection && selection.removeAllRanges) {
                selection.removeAllRanges();
            }
        }

        drag.dx = dx;
        this.viewport.scrollLeft = drag.scroll - dx;
        event.preventDefault();
    };

    Carousel.prototype.endDrag = function (event) {
        var drag = this.drag;
        if (!drag || (event && event.pointerId !== drag.id)) {
            return;
        }
        this.drag = null;

        if (!drag.moved) {
            return;
        }

        try {
            if (this.viewport.hasPointerCapture && this.viewport.hasPointerCapture(drag.id)) {
                this.viewport.releasePointerCapture(drag.id);
            }
        } catch (err) {
            // Already released.
        }

        // The click that ends a drag is swallowed (see onClickCapture).
        this.suppressClickUntil = Date.now() + 400;

        // Snap to the nearest card; a deliberate drag that would round back to
        // where it started moves one card in the drag direction instead.
        var towardEnd = this.rtl ? drag.dx > 0 : drag.dx < 0;
        var target    = Math.round(this.fraction());
        if (target === drag.start && Math.abs(drag.dx) > Math.min(this.pitch * 0.15, 48)) {
            target = drag.start + (towardEnd ? 1 : -1);
        }

        this.index = -1; // Force goTo to scroll even if the target is unchanged.
        this.goTo(target);
    };

    Carousel.prototype.onClickCapture = function (event) {
        if (Date.now() < this.suppressClickUntil) {
            this.suppressClickUntil = 0;
            event.preventDefault();
            event.stopPropagation();
            if (event.stopImmediatePropagation) {
                event.stopImmediatePropagation();
            }
        }
    };

    function carouselsIn(root) {
        var list = Array.prototype.slice.call(root.querySelectorAll(ROOT_SELECTOR));
        if (typeof root.matches === "function" && root.matches(ROOT_SELECTOR)) {
            list.unshift(root);
        }
        return list;
    }

    function rootOrDocument(root) {
        return root && typeof root.querySelectorAll === "function" ? root : document;
    }

    // Initializes every carousel inside root that is not initialized yet.
    // With a signal, the carousels created by this call are destroyed (their
    // listeners and observers released) when it aborts.
    function initCarouselsIn(root, signal) {
        if (signal && signal.aborted) {
            return;
        }

        var created = [];
        carouselsIn(rootOrDocument(root)).forEach(function (el) {
            if (registry.has(el)) {
                return;
            }
            var carousel = new Carousel(el);
            if (carousel.mount()) {
                registry.set(el, carousel);
                created.push(carousel);
            }
        });

        if (signal && created.length) {
            signal.addEventListener("abort", function () {
                created.forEach(function (carousel) { carousel.destroy(); });
            }, { once: true });
        }
    }

    // Destroys every initialized carousel inside root (and root itself).
    function destroyCarouselsIn(root) {
        carouselsIn(rootOrDocument(root)).forEach(function (el) {
            var carousel = registry.get(el);
            if (carousel) {
                carousel.destroy();
            }
        });
    }

    // Public API, used by grid search (AJAX replacement) and the Elementor
    // integration. refresh() re-measures carousels whose container changed in
    // a way no resize reports.
    window.mawCarousel = {
        init: initCarouselsIn,
        destroy: destroyCarouselsIn,
        refresh: function (root) {
            carouselsIn(rootOrDocument(root)).forEach(function (el) {
                var carousel = registry.get(el);
                if (carousel) {
                    carousel.layout(true);
                }
            });
        }
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () { initCarouselsIn(document); });
    } else {
        initCarouselsIn(document);
    }
})();

// -- Grid Search & Pagination --

(function () {
    "use strict";

    // Wrappers that already have listeners. The page-load pass binds every
    // grid once, exactly as before; later calls (from the Elementor
    // integration, for grids rendered after load) skip anything bound.
    var boundWrappers = typeof WeakSet === "function" ? new WeakSet() : null;

    // Binds the grids inside root. When a signal is given, every listener is
    // registered with it, so aborting releases that grid and allows it to be
    // bound again. Without a signal the listeners are added exactly as before.
    function initMawGridSearchIn(root, signal) {
        if (signal && signal.aborted) {
            return;
        }

        var listenerOptions = signal ? { signal: signal } : undefined;

        root.querySelectorAll(".maw-grid-search-wrapper").forEach(function (wrapper) {
            if (boundWrappers && boundWrappers.has(wrapper)) {
                return;
            }

            var gridId       = wrapper.dataset.mawGridId;
            var playlistName = wrapper.dataset.mawPlaylist;
            var mediaType    = wrapper.dataset.mawMediatype;
            var perPage      = parseInt(wrapper.dataset.mawPerpage, 10) || 12;
            var gridKey      = wrapper.dataset.mawGridKey;

            if (!playlistName || !mediaType || !gridKey) {
                return;
            }

            if (boundWrappers) {
                boundWrappers.add(wrapper);
            }

            var searchBar    = document.querySelector(".maw-grid-search-bar[data-maw-for=\"" + gridId + "\"]");
            var gridItemsEl  = wrapper.querySelector(".maw-grid-items");
            var paginationEl = wrapper.querySelector(".maw-grid-pagination");

            var pageParam = wrapper.dataset.mawPageParam || ("maw_page_" + gridId);
            var baseUrl   = window.location.origin + window.location.pathname;

            function readPageFromUrl() {
                var p = parseInt(new URLSearchParams(window.location.search).get(pageParam), 10);
                return p && p > 0 ? p : 1;
            }

            var currentPage     = readPageFromUrl();
            var currentSearch   = "";
            var currentSearchBy = "any";
            var debounceTimer   = null;

            function syncUrl() {
                var params = new URLSearchParams(window.location.search);
                if (currentPage > 1) {
                    params.set(pageParam, currentPage);
                } else {
                    params.delete(pageParam);
                }
                var qs = params.toString();
                window.history.pushState({ mawPage: currentPage }, "", baseUrl + (qs ? "?" + qs : ""));
            }

            function doRequest(page, updateUrl) {
                currentPage = page || 1;
                wrapper.classList.add("loading");

                var formData = new FormData();
                formData.append("action",        "maw_grid_search");
                formData.append("nonce",         mawGridSearchData.nonce);
                formData.append("playlist_name", playlistName);
                formData.append("media_platform",mediaType);
                formData.append("grid_key",      gridKey);
                formData.append("search_term",   currentSearch);
                formData.append("search_by",     currentSearchBy);
                formData.append("page",          currentPage);
                formData.append("per_page",      perPage);
                formData.append("base_url",      baseUrl);

                fetch(mawGridSearchData.ajaxUrl, { method: "POST", body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            if (gridItemsEl) {
                                // A carousel in the replaced results is released
                                // first; the new result page's carousel starts at
                                // its first card. Carousel arrows move within the
                                // loaded page; the pagination loads other pages.
                                // A response arriving after this binding was
                                // released (Elementor re-bound the grid) still
                                // gets a working carousel, owned by no signal.
                                var carousels = window.mawCarousel;
                                if (carousels) carousels.destroy(gridItemsEl);
                                gridItemsEl.innerHTML = data.data.html;
                                if (carousels) carousels.init(gridItemsEl, signal && !signal.aborted ? signal : undefined);
                            }
                            if (paginationEl) paginationEl.innerHTML = data.data.pagination;
                            bindPaginationButtons();
                            // Only plain pagination is reflected in the URL; search
                            // stays AJAX-only so query variants aren't crawled.
                            if (updateUrl && currentSearch === "") {
                                syncUrl();
                            }
                        }
                        wrapper.classList.remove("loading");
                    })
                    .catch(function () {
                        wrapper.classList.remove("loading");
                    });
            }

            function bindPaginationButtons() {
                if (!paginationEl) { return; }
                paginationEl.querySelectorAll("a.maw-page-btn, button.maw-page-btn").forEach(function (ctrl) {
                    ctrl.addEventListener("click", function (e) {
                        if (ctrl.tagName === "A") { e.preventDefault(); }
                        if (ctrl.disabled || ctrl.getAttribute("aria-disabled") === "true") { return; }
                        var page = parseInt(ctrl.dataset.page, 10);
                        if (page && page !== currentPage) {
                            doRequest(page, true);
                        }
                    }, listenerOptions);
                });
            }

            bindPaginationButtons();

            window.addEventListener("popstate", function () {
                if (currentSearch !== "") { return; }
                var page = readPageFromUrl();
                if (page !== currentPage) {
                    doRequest(page, false);
                }
            }, listenerOptions);

            if (searchBar) {
                var input       = searchBar.querySelector(".maw-search-input");
                var select      = searchBar.querySelector(".maw-search-by");
                var clearButton = searchBar.querySelector(".maw-search-clear");

                if (input) {
                    input.addEventListener("input", function () {
                        clearTimeout(debounceTimer);
                        debounceTimer = setTimeout(function () {
                            currentSearch   = input.value;
                            currentSearchBy = select ? select.value : "any";
                            doRequest(1, false);
                        }, 400);
                    }, listenerOptions);
                }

                if (select) {
                    select.addEventListener("change", function () {
                        currentSearchBy = select.value;
                        if (currentSearch !== "") {
                            doRequest(1, false);
                        }
                    }, listenerOptions);
                }

                if (clearButton && input) {
                    clearButton.addEventListener("click", function () {
                        clearTimeout(debounceTimer);
                        input.value     = "";
                        currentSearch   = "";
                        currentSearchBy = select ? select.value : "any";
                        doRequest(1, false);
                        input.focus();
                    }, listenerOptions);
                }
            }

            if (signal) {
                signal.addEventListener("abort", function () {
                    clearTimeout(debounceTimer);
                    if (boundWrappers) {
                        boundWrappers.delete(wrapper);
                    }
                });
            }
        });
    }

    function initMawGridSearch() {
        initMawGridSearchIn(document);
    }

    // Lets the Elementor integration bind grids it renders after page load
    // (and release them with an AbortSignal when a widget re-renders or is
    // removed). Calling it never rebinds a grid that is already bound.
    window.mawGridSearch = {
        init: function (root, signal) {
            initMawGridSearchIn(root && typeof root.querySelectorAll === "function" ? root : document, signal);
        }
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initMawGridSearch);
    } else {
        initMawGridSearch();
    }
})();

// -- Media item click links --
//
// Turns a link built by the Elementor "Media API Click Action" dynamic tag
// (href "#maw-media-" + URL-safe base64 JSON of playlist, type, id, action…)
// into a click on that item's media card. The anchor is given the same data attributes a card
// carries, during the capture phase, so the playlist's own delegated handlers
// then dispatch mediaApiWidgetItemClick and open the lightbox / player exactly
// as they do for cards. Actions: "lightbox" (this item), "playlist" (with the
// playlist), "event" (dispatch only). The link itself never navigates.
//
// Podcast links also get the saved podcast player styling (the values a bare
// [media-api-podcast-player] uses, exposed by the plugin as
// window.mawPodcastPlayerStyle), so the custom RSS player opens styled — for
// every action, so the event's embedUrl is styled too. A styling data
// attribute the link already carries (e.g. added through Elementor's Custom
// Attributes) is kept. Other podcast platforms ignore these attributes.

(function () {
    "use strict";

    var PREFIX  = "#maw-media-";
    var ACTIONS = { lightbox: true, playlist: true, event: true };
    var PLAYER_STYLE_KEYS = [
        "podcastplayermode",
        "podcastplayertextcolor",
        "podcastplayerbuttoncolor",
        "podcastplayercolor",
        "podcastprogressplayerbarcolor",
        "podcastplayerhighlightcolor",
        "podcastplayerfont",
        "podcastplayerscrollcolor"
    ];

    function decode(payload) {
        try {
            var base64 = payload.replace(/-/g, "+").replace(/_/g, "/");
            var binary = atob(base64 + "===".slice((base64.length + 3) % 4));
            var bytes = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            var values = JSON.parse(new TextDecoder().decode(bytes));
            return values && typeof values === "object" ? values : null;
        } catch (err) {
            return null;
        }
    }

    function parse(anchor) {
        var href = anchor.getAttribute("href") || "";
        var at = href.indexOf(PREFIX);
        var values = at === -1 ? null : decode(href.slice(at + PREFIX.length));
        if (!values) {
            return null;
        }

        var text = function (key) { return typeof values[key] === "string" ? values[key] : ""; };
        var playlist = text("playlist");
        var type = text("type");
        var id = text("id");

        if (!playlist || !id || (type !== "youtube" && type !== "podcast")) {
            return null;
        }

        return {
            playlist: playlist,
            type: type,
            id: id,
            action: ACTIONS[text("action")] ? text("action") : "lightbox",
            post: text("post"),
            title: text("title"),
            color: text("color"),
            font: text("font"),
            logo: text("logo")
        };
    }

    function setData(el, key, value) {
        if (value) {
            el.dataset[key] = value;
        } else {
            delete el.dataset[key];
        }
    }

    // Fills each podcast player styling attribute the link does not already
    // carry from the saved styling. A blank saved field leaves the attribute
    // unset, so the player falls back to its own default.
    function applyPlayerStyle(anchor) {
        var saved = window.mawPodcastPlayerStyle;
        saved = saved && typeof saved === "object" ? saved : {};

        for (var i = 0; i < PLAYER_STYLE_KEYS.length; i++) {
            var key = PLAYER_STYLE_KEYS[i];
            if (!anchor.dataset[key]) {
                setData(anchor, key, typeof saved[key] === "string" ? saved[key] : "");
            }
        }
    }

    function hasPlayer(key) {
        var boxes = document.querySelectorAll("[data-lightboxid]");
        for (var i = 0; i < boxes.length; i++) {
            if (boxes[i].dataset.lightboxid === key) {
                return true;
            }
        }
        return false;
    }

    document.addEventListener("click", function (e) {
        var target = e.target instanceof Element ? e.target : null;
        var anchor = target ? target.closest('a[href*="#maw-media-"]') : null;
        var link = anchor ? parse(anchor) : null;

        if (!link) {
            return;
        }

        e.preventDefault();

        // In the Elementor editor a click selects the element; never play there.
        if (document.body && document.body.classList.contains("elementor-editor-active")) {
            return;
        }

        var audio = link.type === "podcast";
        var key = link.playlist + "_" + link.type;

        setData(anchor, "mawlink", "true");
        setData(anchor, "itemclickable", "true");
        setData(anchor, "itemclickableplaylist", key);
        setData(anchor, "itemclickablemediatype", audio ? "audio" : "video");
        setData(anchor, "id", link.id);
        setData(anchor, "postid", link.post);
        setData(anchor, "mawtitle", link.title);
        setData(anchor, "showlightbox", link.action === "event" ? "false" : "");
        // Video: adds the playlist panel. Podcast: it is what opens the player,
        // and the episode list is kept or hidden through the player URL.
        setData(anchor, "lightboxshowplaylist", (audio ? link.action !== "event" : link.action === "playlist") ? "true" : "");
        setData(anchor, "singleepisode", audio && link.action === "lightbox" ? link.id : "");
        setData(anchor, "startepisode", audio && link.action === "playlist" ? link.id : "");
        setData(anchor, "lightboxthemecolor", link.color);
        setData(anchor, "lightboxfont", link.font);
        setData(anchor, "lightboxshowlogoimgurl", link.logo);
        if (audio) {
            applyPlayerStyle(anchor);
        }

        // A playlist with no player on this page (for example one removed from
        // the settings while its posts were kept) still announces the click.
        if (!hasPlayer(key)) {
            document.dispatchEvent(new CustomEvent("mediaApiWidgetItemClick", {
                bubbles: true,
                detail: {
                    playlistName: link.playlist,
                    mediaType: link.type,
                    embedUrl: audio ? null : "https://www.youtube.com/embed/" + link.id,
                    itemId: link.id,
                    showLightbox: false,
                    element: anchor
                }
            }));
        }
    }, true);
})();
