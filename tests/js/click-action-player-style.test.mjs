// Media API Click Action → custom podcast player styling.
//
// Runs the real assets/media-api-widget.js against a minimal DOM stub and
// clicks "#maw-media-…" links the way a visitor would, then reads the
// /podcast/player URL the playlist's own handler builds (window.open below
// 872px, and the mediaApiWidgetItemClick event's embedUrl at any width).
//
// The PHP half — that window.mawPodcastPlayerStyle holds exactly the styling a
// bare [media-api-podcast-player] uses — is tests/cases/podcast-player-style.php.
//
// Run: node --test tests/js/
// No dependencies; needs Node 18+.

import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";

const SOURCE = readFileSync(new URL("../../assets/media-api-widget.js", import.meta.url), "utf8");

const FEED = "https://feeds.example.com/cast.xml";
const GUID = "e446bef0-75e8-46fc-9ddc-d965de132b75";

const SAVED = {
    podcastplayermode: "light",
    podcastplayertextcolor: "ABCDEF",
    podcastplayerbuttoncolor: "fff",
    podcastplayercolor: "123456",
    podcastprogressplayerbarcolor: "616161",
    podcastplayerhighlightcolor: "7a7a7a",
    podcastplayerfont: "Open Sans",
    podcastplayerscrollcolor: "" // a blank stored field
};

// Query parameter => the attribute it comes from (Shortcode / PlayerStyle::QUERY).
const QUERY = {
    mode: "podcastplayermode",
    buttoncolor: "podcastplayerbuttoncolor",
    color1: "podcastplayercolor",
    progressbarcolor: "podcastprogressplayerbarcolor",
    highlightcolor: "podcastplayerhighlightcolor",
    font: "podcastplayerfont",
    scrollcolor: "podcastplayerscrollcolor",
    textcolor: "podcastplayertextcolor"
};

class FakeElement {
    constructor(tag, attributes = {}) {
        this.tagName = tag.toUpperCase();
        this.attributes = { ...attributes };
        this.dataset = {};
        this.children = [];
        this.parentNode = null;
        this.style = {};
        const classes = new Set();
        this.classList = {
            add: (...c) => c.forEach(x => classes.add(x)),
            remove: (...c) => c.forEach(x => classes.delete(x)),
            contains: c => classes.has(c),
            toString: () => [...classes].join(" ")
        };
    }
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
    replaceChildren(...children) { this.children = children; }
    addEventListener() {}
    querySelector(selector) {
        for (const child of this.children) {
            if (matches(child, selector)) {
                return child;
            }
            const found = child instanceof FakeElement ? child.querySelector(selector) : null;
            if (found) {
                return found;
            }
        }
        return null;
    }
    closest(selector) {
        for (let el = this; el; el = el.parentNode) {
            if (matches(el, selector)) {
                return el;
            }
        }
        return null;
    }
}

// The only selectors the click paths use.
function matches(el, selector) {
    if (!(el instanceof FakeElement)) {
        return false;
    }
    switch (selector) {
        case 'a[href*="#maw-media-"]':
            return el.tagName === "A" && String(el.getAttribute("href") || "").includes("#maw-media-");
        case 'a[data-itemclickable="true"]':
            return el.tagName === "A" && el.dataset.itemclickable === "true";
        default: {
            const attribute = /^\[data-([a-z]+)="([^"]*)"\]$/.exec(selector);
            return attribute !== null && el.dataset[attribute[1]] === attribute[2];
        }
    }
}

/**
 * Loads the script into a fresh page and initializes the given playlists.
 */
const CAST = { channel: { title: "The Cast", rssUrl: FEED, item: [{ title: "One", guid: GUID, pubDate: "Mon, 05 Jan 2026 10:00:00 +0000" }] } };

function page({ style = SAVED, exposeStyle = true, editor = false, width = 500, playlists = [["cast", "podcast", "custom", CAST]] } = {}) {
    const listeners = { window: { capture: [], bubble: [] }, document: { capture: [], bubble: [] } };
    const add = bucket => (type, fn, options) => {
        if (type === "click") {
            bucket[options === true || (options && options.capture) ? "capture" : "bubble"].push(fn);
        }
    };

    const events = [];
    const opened = [];
    const body = new FakeElement("body");
    const html = new FakeElement("html");
    if (editor) {
        body.classList.add("elementor-editor-active");
    }

    const document = {
        readyState: "loading",
        body,
        addEventListener: add(listeners.document),
        createElement: tag => new FakeElement(tag),
        querySelectorAll: selector => (selector === "[data-lightboxid]" ? body.children.filter(c => c.dataset.lightboxid) : []),
        querySelector: selector => (selector === "html" ? html : body.querySelector(selector)),
        dispatchEvent: event => { events.push(event); return true; }
    };

    const window = {
        innerWidth: width,
        location: { origin: "https://example.test" },
        addEventListener: add(listeners.window),
        open: url => opened.push(url)
    };
    if (exposeStyle) {
        window.mawPodcastPlayerStyle = style;
    }

    class CustomEvent {
        constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
    }

    const context = vm.createContext({
        window, document, Element: FakeElement, CustomEvent, console, setTimeout, clearTimeout,
        atob, TextDecoder, URLSearchParams
    });
    vm.runInContext(SOURCE, context);

    for (const [name, type, platform, mediaData] of playlists) {
        context.initialize_media([], mediaData, name, type, platform);
    }

    const click = target => {
        const event = { type: "click", target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        for (const phase of [listeners.window.capture, listeners.document.capture, listeners.document.bubble, listeners.window.bubble]) {
            phase.forEach(fn => fn(event));
        }
        return event;
    };

    return { context, body, events, opened, click };
}

function clickLink(values, attributes = {}) {
    const json = JSON.stringify({ post: "12", title: "One", ...values });
    const href = "#maw-media-" + Buffer.from(json, "utf8").toString("base64").replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
    const anchor = new FakeElement("a", { href });
    Object.assign(anchor.dataset, attributes);
    // Clicks land on a child, as on a Button widget's inner span.
    const label = anchor.appendChild(new FakeElement("span"));
    return { anchor, label };
}

function query(url) {
    assert.ok(url, "a player URL was built");
    const parsed = new URL(url);
    assert.equal(parsed.origin + parsed.pathname, "https://example.test/podcast/player");
    return Object.fromEntries(parsed.searchParams);
}

function assertStyled(params, saved = SAVED) {
    for (const [param, attribute] of Object.entries(QUERY)) {
        assert.equal(params[param], saved[attribute] ?? "", `${param} comes from the saved ${attribute}`);
    }
}

test("this item only: the player opens on the episode, styled, without the list", () => {
    const p = page();
    const { anchor, label } = clickLink({ playlist: "cast", type: "podcast", id: GUID, action: "lightbox" });
    const event = p.click(label);

    assert.equal(event.defaultPrevented, true, "the link never navigates");
    assert.equal(p.opened.length, 1, "the player opens");
    const params = query(p.opened[0]);
    assertStyled(params);
    assert.equal(params.url, FEED);
    assert.equal(params.singleepisode, GUID);
    assert.equal(params.startepisode, undefined);
    assert.equal(params.autoplay, "1");
    assert.equal(params.font, "Open Sans", "the font keeps its space");
    assert.equal(params.mode, "light", "light mode is passed through");

    assert.equal(p.events.length, 1, "mediaApiWidgetItemClick fires once");
    const detail = p.events[0].detail;
    assert.equal(p.events[0].type, "mediaApiWidgetItemClick");
    assert.deepEqual(
        { playlistName: detail.playlistName, mediaType: detail.mediaType, itemId: detail.itemId, showLightbox: detail.showLightbox },
        { playlistName: "cast", mediaType: "podcast", itemId: GUID, showLightbox: true }
    );
    assert.equal(detail.element, anchor);
    assert.equal(detail.embedUrl, p.opened[0], "the event's embedUrl is the styled player URL");
    assert.equal(anchor.dataset.postid, "12");
});

test("with the playlist: starts on the episode and keeps the list, styled", () => {
    const p = page();
    const { label } = clickLink({ playlist: "cast", type: "podcast", id: GUID, action: "playlist" });
    p.click(label);

    const params = query(p.opened[0]);
    assertStyled(params);
    assert.equal(params.startepisode, GUID);
    assert.equal(params.singleepisode, undefined);
    assert.equal(params.autoplay, "1");
});

test("the in-page lightbox gets the same styled URL on wide screens", () => {
    const p = page({ width: 1400 });
    const { label } = clickLink({ playlist: "cast", type: "podcast", id: GUID, action: "lightbox" });
    p.click(label);

    assert.equal(p.opened.length, 0, "no new window on wide screens");
    const box = p.body.children.find(c => c.dataset.lightboxid === "cast_podcast");
    const iframe = box.children[0].children[0].children[0];
    assert.equal(iframe.tagName, "IFRAME");
    assertStyled(query(iframe.src));
    assert.equal(iframe.src, p.events[0].detail.embedUrl);
});

test("event only: no player opens, but the event carries the styled URL", () => {
    const p = page();
    const { label } = clickLink({ playlist: "cast", type: "podcast", id: GUID, action: "event" });
    p.click(label);

    assert.equal(p.opened.length, 0, "nothing opens");
    assert.equal(p.events.length, 1);
    assert.equal(p.events[0].detail.showLightbox, false);
    assertStyled(query(p.events[0].detail.embedUrl));
});

test("a styling data attribute already on the link is kept", () => {
    const p = page();
    const { label } = clickLink({ playlist: "cast", type: "podcast", id: GUID, action: "lightbox" }, { podcastplayercolor: "ff0000", showepisodedateaftertitle: "true" });
    p.click(label);
    p.click(label);

    assert.equal(p.opened.length, 2);
    for (const url of p.opened) {
        const params = query(url);
        assertStyled(params, { ...SAVED, podcastplayercolor: "ff0000" });
        assert.equal(params.adddatetotitle, "true", "the date option still applies");
    }
});

test("without exposed styling (e.g. a stale cached page) the link still works unstyled", () => {
    const p = page({ exposeStyle: false });
    const { label } = clickLink({ playlist: "cast", type: "podcast", id: GUID, action: "lightbox" });
    p.click(label);

    const params = query(p.opened[0]);
    assertStyled(params, {});
    assert.equal(params.singleepisode, GUID);
});

test("in the Elementor editor a click plays nothing and changes nothing", () => {
    const p = page({ editor: true });
    const { anchor, label } = clickLink({ playlist: "cast", type: "podcast", id: GUID, action: "lightbox" });
    const event = p.click(label);

    assert.equal(event.defaultPrevented, true);
    assert.equal(p.opened.length, 0);
    assert.equal(p.events.length, 0);
    assert.deepEqual({ ...anchor.dataset }, {});
});

test("YouTube links get no podcast styling", () => {
    const p = page();
    const { anchor, label } = clickLink({ playlist: "show", type: "youtube", id: "HZwLZkgFdko", action: "playlist" });
    p.click(label);

    assert.equal(p.events.length, 1, "a playlist with no player still announces the click");
    assert.equal(p.events[0].detail.embedUrl, "https://www.youtube.com/embed/HZwLZkgFdko");
    assert.deepEqual(Object.keys(anchor.dataset).filter(k => k.startsWith("podcastplayer")), []);
});

test("third-party podcast platforms keep their own embed", () => {
    const embed = "https://player.example.com/embed/abc";
    const p = page({ playlists: [["cast", "podcast", "embed", embed]] });
    const { label } = clickLink({ playlist: "cast", type: "podcast", id: GUID, action: "lightbox" });
    p.click(label);

    assert.deepEqual(p.opened, [embed], "the embed URL is used unchanged");
    assert.equal(p.events[0].detail.embedUrl, embed);
});

test("cards keep their own styling attributes and do not inherit", () => {
    const p = page();
    const card = new FakeElement("a");
    Object.assign(card.dataset, {
        itemclickable: "true", itemclickableplaylist: "cast_podcast", itemclickablemediatype: "audio",
        id: GUID, trackselect: "1", lightboxshowplaylist: "true", podcastplayercolor: "00ff00"
    });
    p.click(card);

    const params = query(p.opened[0]);
    assert.equal(params.color1, "00ff00");
    assert.equal(params.mode, "", "unchanged: a card without styling attributes sends none");
    assert.equal(params.track, "1");
});

test("client-rendered custom podcast items keep dark/light modes and bare hex colors", () => {
    const p = page({ playlists: [] });
    const item = new FakeElement("div");
    Object.assign(item.dataset, {
        playlistname: "cast", mediaplatform: "podcast", orderdescending: "1", thumbnail: "https://example.test/t.jpg",
        podcastplayermode: "light", podcastplayercolor: "c7c7c7", podcastplayertextcolor: "#ABCDEF", podcastplayerfont: "Open Sans"
    });
    p.context.initialize_media([item], { channel: { rssUrl: FEED, item: [{ title: "One", guid: GUID, description: "" }] } }, "cast", "podcast", "custom");

    const html = String(item.outerHTML);
    assert.match(html, /data-podcastplayermode="light"/, "not \"ight\"");
    assert.match(html, /data-podcastplayercolor="c7c7c7"/, "not \"7c7c7\"");
    assert.match(html, /data-podcastplayertextcolor="ABCDEF"/, "a leading # is still removed");
    assert.match(html, /data-podcastplayerfont="Open Sans"/);
});
