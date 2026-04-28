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
	                return normalized === "title" || normalized === "description" ? normalized : "";
	            }

	            function render_multiple_grid_text(item, mode) {
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
                        lightboxshowplaylist: false
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
	                const { showPlayButton, playButtonIconImgUrl, playButtonStyling, showTextOverlay, instructionMessage, fontFamily, lightboxshowplaylist, showPlaybar, playbarColor } = settings;
	                const safeTitleAttr = escape_attr(title ?? "");
	                const safeOverlayTitle = format_overlay_title(item);
	                const safePlaylistTitle = escape_html(media_title_text(item));
	                const safeInstructionMessage = escape_html(instructionMessage ?? "");
	                const multipleGridTextHtml = !playlistItem ? render_multiple_grid_text(item, settings.multipleGridText) : "";
	                const mediaItemHtml = `
	                    <a ${settings.fontFamily ? `style=${fontFamily}` : ""} class="media_item${showTextOverlay ? `-text-overlay-enabled` : ``}" data-itemclickablemediatype="${mediaType}" data-itemclickable="true" data-itemclickableplaylist="${name}_${type}" 
	                        data-id="${id}" ${lightboxshowplaylist ? `data-lightboxshowplaylist="true"` : ""} ${item.trackSelect !== null ? `data-trackselect=${Number(item.trackSelect)}` : ""}
	                        ${customPodcastPlayer  ? podcastPlayerData : ""}
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
                            playbarColor
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
                            settings.podcastplayermode = itemData.podcastplayermode ? itemData.podcastplayermode.slice(1) : "dark";
                            settings.podcastplayerbuttoncolor = itemData.podcastplayerbuttoncolor ? itemData.podcastplayerbuttoncolor.slice(1) : "";
                            settings.podcastplayercolor = itemData.podcastplayercolor ? itemData.podcastplayercolor.slice(1) : "";
                            settings.podcastprogressplayerbarcolor = itemData.podcastprogressplayerbarcolor ? itemData.podcastprogressplayerbarcolor.slice(1) : "";
                            settings.podcastplayerhighlightcolor = itemData.podcastplayerhighlightcolor ? itemData.podcastplayerhighlightcolor.slice(1) : "";
                            settings.podcastplayerfont = itemData.podcastplayerfont ? itemData.podcastplayerfont : "";
                            settings.podcastplayerscrollcolor = itemData.podcastplayerscrollcolor ? itemData.podcastplayerscrollcolor.slice(1) : "";
                            settings.podcastplayertextcolor = itemData.podcastplayertextcolor ? itemData.podcastplayertextcolor.slice(1) : "";
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
                            startingLightboxActive.querySelector(`iframe`).src = lightboxFrameVideoBaseUrl + setVideoId(0);
	                            startingLightboxActive.querySelector(`h1`).textContent = setThumbnailText(setData(0));
                        } else window.open(lightboxFrameVideoBaseUrl + setVideoId(0))
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

                    const lightboxPlaylistItems = lightboxPlaylistContent.querySelectorAll(`[data-itemclickableplaylist="${media_name}_${media_type}"]`);

                    lightboxPlaylistItems.forEach(itemClicked => 
                        itemClicked.addEventListener(`click`, () => {
                        currentVideoIndex = playListSorted.findIndex(video => video.id === itemClicked.dataset.id);
                        toggleLightboxPlayerOrPlaylist();
                        initLightboxFrames();
                        })
                    );

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

                            embedPlayerUrl = `https://omny.fm/shows/${showName}/playlists/podcast/embed?selectedClip=${itemClicked.dataset.id}`;
                        }

                        if (podcast_platform === "soundcloud") {
                            const showEpisodeUrl = media_data.channel.item[0].link;
                            const spliced = showEpisodeUrl.split("/");
                            const urlOutput = `${spliced[0]}/${spliced[1]}/${spliced[2]}/${spliced[3]}`;
                            embedPlayerUrl = `https://w.soundcloud.com/player/?url=${urlOutput}&start_track=${itemClicked.dataset.trackselect}`;
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
                            let rssUrl = media_data.channel?.rssUrl;
                            if (!rssUrl) return;
                            const mode = itemClicked.dataset.podcastplayermode;
                            const playButtonColor = itemClicked.dataset.podcastplayerbuttoncolor;
                            const color = itemClicked.dataset.podcastplayercolor;
                            const progressBarColor = itemClicked.dataset.podcastprogressplayerbarcolor;
                            const highlightColor = itemClicked.dataset.podcastplayerhighlightcolor;
                            const font = itemClicked.dataset.podcastplayerfont;
                            const scrollbarColor = itemClicked.dataset.podcastplayerscrollcolor;
                            const textColor = itemClicked.dataset.podcastplayertextcolor;
                            const showEpisodeDateAfterTitle = itemClicked.dataset.showepisodedateaftertitle === "true" ? "adddatetotitle=true" : "";

                            embedPlayerUrl = `${window.location.origin}/podcast/player?url=${rssUrl}&track=${itemClicked.dataset.trackselect}&mode=${mode}&buttoncolor=${playButtonColor}&color1=${color}&progressbarcolor=${progressBarColor}&highlightcolor=${highlightColor}&font=${font}&scrollcolor=${scrollbarColor}&textcolor=${textColor}&${showEpisodeDateAfterTitle}`;
                        }

                        // If Window Resolution Is Less Than 872px, Open New Window In Apple Podcasts

                        if (window.innerWidth < 872) {
                            window.open(embedPlayerUrl);
                            return
                        }
                        
                        const media_audio_lightbox_html = `
                            <div class="lightbox-content-container">
                                <div class="lightbox-box-frame">
                                    <iframe class="lightbox-podcast-embed-player" src="${embedPlayerUrl}"></iframe>
                                </div>
                            </div>
                            <div class="lightbox-close-button" data-hideonidle="true" data-lightboxclosebutton="true">X</div>
                        `;

                        // Init Lightbox

                        const lightbox = media_lightbox_div;

                        lightboxToggled = true;
                        showPlaylist = true;
                        lightbox.classList.add(`show-lightbox`);
                        document.querySelector(`html`).classList.add(`hide-scroll`);

                        lightbox.innerHTML = media_audio_lightbox_html;

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

                        if (node.dataset.lightboxshowplaylist && node.dataset.lightboxshowplaylist === "true" && node.dataset.itemclickablemediatype === "audio") {
                            audio_lightbox_activate_handler(node);
                        }
                    });
                }
