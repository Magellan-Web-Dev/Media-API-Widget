/**
 * Media API Widget — Elementor widget lifecycle.
 *
 * The widgets render through the plugin's existing shortcode renderers, so
 * their markup is the shortcode markup and the existing front-end code already
 * handles it:
 *
 * - Playback and lightboxes are delegated `window` click handlers registered
 *   once per playlist by the wp_head bootstrap. They work for cards inserted at
 *   any time, so this file never calls initialize_media() — calling it again
 *   would create a second lightbox and a second set of click handlers.
 * - Grid search and pagination bind once at DOM ready. Widgets rendered later
 *   (every widget in the editor, and any re-render) are bound here through
 *   window.mawGridSearch, with an AbortController this file owns, so the
 *   listeners are released when Elementor re-renders or removes the widget.
 *   Grids bound by the page-load pass are never touched.
 *
 * Classic widgets are tracked through Elementor's frontend handler lifecycle;
 * atomic widgets through elementorV2.frontendHandlers. Because an editor
 * re-render can replace a widget's root element without a destroy
 * notification, any tracked root that has left the document is released when
 * another widget mounts.
 */
(function () {
    "use strict";

    var CLASSIC_WIDGET = "maw-media-api";
    var ATOMIC_TYPE    = "maw-media-atomic";

    if (typeof Map !== "function" || typeof AbortController !== "function") {
        return;
    }

    /** @type {Map<Element, {controller: AbortController}>} */
    var instances = new Map();

    function gridSearchApi() {
        var api = window.mawGridSearch;
        return api && typeof api.init === "function" ? api : null;
    }

    function bindGrids(root, record) {
        var api = gridSearchApi();
        if (api) {
            api.init(root, record.controller.signal);
        }
    }

    function unmount(root) {
        var record = instances.get(root);
        if (!record) {
            return;
        }
        instances.delete(root);
        record.controller.abort();
    }

    // A grid resolves its search bar once, when it is bound. When a search bar
    // is (re)rendered, rebind the integration-owned grids it targets so they
    // listen to the new bar instead of a replaced one.
    function relinkSearchBars(root) {
        root.querySelectorAll(".maw-grid-search-bar[data-maw-for]").forEach(function (bar) {
            var target = bar.getAttribute("data-maw-for");

            instances.forEach(function (record, otherRoot) {
                if (otherRoot === root || !otherRoot.isConnected) {
                    return;
                }

                var targetsBar = Array.prototype.some.call(
                    otherRoot.querySelectorAll(".maw-grid-search-wrapper"),
                    function (wrapper) { return wrapper.getAttribute("data-maw-grid-id") === target; }
                );

                if (targetsBar) {
                    record.controller.abort();
                    record.controller = new AbortController();
                    bindGrids(otherRoot, record);
                }
            });
        });
    }

    function mount(root, kind, externalSignal) {
        if (!root || typeof root.querySelectorAll !== "function") {
            return;
        }

        // Search bars are looked up document-wide, so wait until the root is
        // in the document (atomic handlers can run just before insertion).
        if (!root.isConnected) {
            window.requestAnimationFrame(function () {
                if (root.isConnected && !(externalSignal && externalSignal.aborted)) {
                    mount(root, kind, externalSignal);
                }
            });
            return;
        }

        unmount(root);

        // A re-render that replaced a root never announced its destruction;
        // the replaced root is detached by now, so release it. Roots are not
        // matched by element id: a loop template repeats the same id once per
        // item, and every repetition must stay bound.
        instances.forEach(function (record, otherRoot) {
            if (!otherRoot.isConnected) {
                unmount(otherRoot);
            }
        });

        var record = { controller: new AbortController() };
        instances.set(root, record);

        if (externalSignal) {
            externalSignal.addEventListener("abort", function () {
                if (instances.get(root) === record) {
                    unmount(root);
                }
            }, { once: true });
        }

        bindGrids(root, record);
        relinkSearchBars(root);
    }

    // -- Classic widget ------------------------------------------------------

    var classicRegistered = false;

    function registerClassic() {
        var frontend = window.elementorFrontend;
        var modules  = window.elementorModules;

        if (classicRegistered || !frontend || !frontend.elementsHandler || !modules || !modules.frontend) {
            return;
        }
        classicRegistered = true;

        var Base = modules.frontend.handlers.Base;
        var Handler = Base.extend({
            onInit: function () {
                Base.prototype.onInit.apply(this, arguments);
                mount(this.$element[0], "classic");
            },
            onDestroy: function () {
                unmount(this.$element[0]);
                Base.prototype.onDestroy.apply(this, arguments);
            }
        });

        frontend.elementsHandler.attachHandler(CLASSIC_WIDGET, Handler);
    }

    if (window.elementorFrontend && window.elementorFrontend.elementsHandler) {
        // Loaded after Elementor initialized: attach for future renders and
        // mount the widgets that are already on the page.
        registerClassic();
        document.querySelectorAll(".elementor-widget-" + CLASSIC_WIDGET).forEach(function (element) {
            mount(element, "classic");
        });
    } else {
        window.addEventListener("elementor/frontend/init", registerClassic);
    }

    // -- Atomic widget -------------------------------------------------------

    var atomicRegistered = false;

    function registerAtomic() {
        var v2 = window.elementorV2;

        if (atomicRegistered || !v2 || !v2.frontendHandlers || typeof v2.frontendHandlers.register !== "function") {
            return;
        }
        atomicRegistered = true;

        v2.frontendHandlers.register({
            elementType: ATOMIC_TYPE,
            id: "maw-media-atomic-lifecycle",
            callback: function (context) {
                mount(context.element, "atomic", context.signal);

                return function () {
                    unmount(context.element);
                };
            }
        });

        // Atomic roots already on the page when this registered late; the
        // handlers package also re-runs this for them, which is harmless.
        document.querySelectorAll("[data-e-type=\"" + ATOMIC_TYPE + "\"]").forEach(function (element) {
            mount(element, "atomic");
        });
    }

    registerAtomic();
    if (!atomicRegistered) {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", registerAtomic);
        }
        window.addEventListener("load", registerAtomic);
    }
})();
