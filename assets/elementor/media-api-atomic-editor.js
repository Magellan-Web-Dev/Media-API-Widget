/**
 * Media API Widget — atomic editor view.
 *
 * Elementor renders atomic widgets in the editor by running their Twig template
 * in JavaScript. The Media API widget's content comes from the plugin's PHP
 * shortcode renderers, which JavaScript cannot run, so this view shows the
 * server render instead: Elementor's own `render_atomic_element` request,
 * which renders the element exactly as the front end does. This follows the
 * pattern Elementor Pro's collection loop uses for server-rendered content.
 *
 * The view extends Elementor's templated atomic view, so selection, the
 * `display: contents` wrapper, V4 styles, and the atomic render/destroy events
 * behave like any other atomic element. The element's initial HTML cache is
 * used for the first paint when it belongs to this element; later settings
 * changes request a fresh render (debounced, with stale responses discarded).
 *
 * When the editor APIs this relies on are missing, nothing is registered and
 * Elementor falls back to the client-side template, which shows a notice.
 */
(function () {
    "use strict";

    var config   = window.mawElementorAtomicEditor || {};
    var TYPE     = config.type || "maw-media-atomic";
    var DELAY_MS = 250;

    var v2       = window.elementorV2 || {};
    var canvas   = v2.editorCanvas;
    var adapters = v2.editorV1Adapters;

    if (!canvas || typeof canvas.registerElementType !== "function" || typeof canvas.createTemplatedElementView !== "function"
        || !adapters || !adapters.ajax || typeof adapters.ajax.load !== "function") {
        return;
    }

    function settingsHash(model) {
        var settings = model.get("settings");
        return JSON.stringify(settings && typeof settings.toJSON === "function" ? settings.toJSON() : settings || {});
    }

    function cachedHtmlFor(model) {
        if (typeof model.getHtmlCache !== "function") {
            return null;
        }

        var html = model.getHtmlCache();

        // A duplicated element inherits its source's cache, which carries the
        // source's element id; only use a cache rendered for this element.
        return typeof html === "string" && html.indexOf("data-id=\"" + model.get("id") + "\"") !== -1 ? html : null;
    }

    function requestRender(model, signal) {
        return new Promise(function (resolve) {
            var timer = setTimeout(function () {
                if (signal && signal.aborted) {
                    resolve(null);
                    return;
                }

                var request = {
                    action: "render_atomic_element",
                    unique_id: "render_atomic_element_" + model.get("id"),
                    data: { data: model.toJSON() }
                };

                if (typeof adapters.ajax.invalidateCache === "function") {
                    adapters.ajax.invalidateCache(request);
                }

                adapters.ajax.load(request).then(function (response) {
                    resolve(response && typeof response.render === "string" ? response.render : null);
                }, function () {
                    resolve(null);
                });
            }, DELAY_MS);

            if (signal) {
                signal.addEventListener("abort", function () {
                    clearTimeout(timer);
                    resolve(null);
                }, { once: true });
            }
        });
    }

    canvas.registerElementType(TYPE, function (options) {
        var BaseView = canvas.createTemplatedElementView(options);

        var MediaApiAtomicView = class extends BaseView {
            async _renderTemplate() {
                var signal = this._abortController ? this._abortController.signal : null;
                var hash   = settingsHash(this.model);

                if (this.isRendered && hash === this._mawRenderedHash) {
                    this._domUpdateWasSkipped = true;
                    return;
                }

                this._domUpdateWasSkipped = false;

                var html = this._mawRenderedHash ? null : cachedHtmlFor(this.model);
                if (html === null) {
                    html = await requestRender(this.model, signal);
                }

                if (signal && signal.aborted) {
                    return;
                }

                if (html === null) {
                    // Server render unavailable: let Elementor render the
                    // client-side template (wrapper and notice only).
                    this._mawRenderedHash = null;
                    return super._renderTemplate();
                }

                this.triggerMethod("before:render:template");
                this._mawRenderedHash = hash;
                this.$el.html(html);

                if (typeof this.model.setHtmlCache === "function") {
                    this.model.setHtmlCache(html);
                }

                this.bindUIElements();
                this.triggerMethod("render:template");
            }

            invalidateRenderCache() {
                super.invalidateRenderCache();
                this._mawRenderedHash = null;
            }
        };

        return class extends window.elementor.modules.elements.types.Widget {
            getType() {
                return options.type;
            }

            getView() {
                return MediaApiAtomicView;
            }
        };
    });
})();
