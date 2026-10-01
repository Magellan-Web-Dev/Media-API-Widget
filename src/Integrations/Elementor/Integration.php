<?php
namespace MediaApiWidget\Integrations\Elementor;

if (!defined('ABSPATH')) { exit; }

/**
 * Optional Elementor integration bootstrap.
 *
 * Only adds hooks. Every hook it uses is fired by Elementor itself, so with
 * Elementor absent or inactive nothing here ever runs and no Elementor class is
 * referenced. Neither widget class is loaded until Elementor asks for widgets:
 *
 * - "Media API — Classic" is registered whenever Elementor's Widget_Base exists.
 * - "Media API — Atomic" is registered only when the Atomic Widgets feature is
 *   active and every atomic class the widget uses is present. The integration
 *   never enables an Elementor feature or changes a setting itself.
 *
 * Elementor Pro is not required.
 */
final class Integration
{
    /** Elementor panel category for the classic widget. */
    public const CATEGORY = 'media-api-widget';

    /** Frontend lifecycle script handle. */
    public const FRONTEND_HANDLE = 'maw-elementor-frontend';

    /** Atomic element type, kept here so callers need not load the widget class. */
    public const ATOMIC_TYPE = 'maw-media-atomic';

    /** Atomic editor view script handle. */
    public const ATOMIC_EDITOR_HANDLE = 'maw-elementor-atomic-editor';

    /** Script handle of the plugin's existing front-end bundle. */
    private const PLUGIN_SCRIPT_HANDLE = 'maw-media-api-widget';

    /** Atomic classes and traits the atomic widget depends on. */
    private const ATOMIC_CLASSES = [
        'Elementor\\Modules\\AtomicWidgets\\Module',
        'Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Atomic_Widget_Base',
        'Elementor\\Modules\\AtomicWidgets\\Controls\\Section',
        'Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Select_Control',
        'Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Text_Control',
        'Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Number_Control',
        'Elementor\\Modules\\AtomicWidgets\\DynamicTags\\Dynamic_Prop_Type',
        'Elementor\\Modules\\AtomicWidgets\\Elements\\Loader\\Frontend_Assets_Loader',
        'Elementor\\Modules\\AtomicWidgets\\PropDependencies\\Manager',
        'Elementor\\Modules\\AtomicWidgets\\PropTypes\\Attributes_Prop_Type',
        'Elementor\\Modules\\AtomicWidgets\\PropTypes\\Classes_Prop_Type',
        'Elementor\\Modules\\AtomicWidgets\\PropTypes\\Primitives\\Number_Prop_Type',
        'Elementor\\Modules\\AtomicWidgets\\PropTypes\\Primitives\\String_Prop_Type',
    ];

    /** Atomic traits the atomic widget depends on. */
    private const ATOMIC_TRAITS = [
        'Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Has_Template',
    ];

    /**
     * Hooks the integration into Elementor's registration points.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('elementor/elements/categories_registered', [$this, 'registerCategory']);
        add_action('elementor/widgets/register', [$this, 'registerWidgets']);
        add_action('elementor/frontend/after_register_scripts', [$this, 'registerScripts']);
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueueEditorScripts']);
    }

    /**
     * Adds the "Media API" panel category.
     *
     * @param object $elementsManager Elementor elements manager.
     * @return void
     */
    public function registerCategory($elementsManager): void
    {
        if (!is_object($elementsManager) || !method_exists($elementsManager, 'add_category')) {
            return;
        }

        $elementsManager->add_category(self::CATEGORY, [
            'title' => __('Media API', 'media-api-widget'),
            'icon'  => 'eicon-youtube',
        ]);
    }

    /**
     * Registers the classic widget, and the atomic widget when available.
     *
     * @param object $widgetsManager Elementor widgets manager.
     * @return void
     */
    public function registerWidgets($widgetsManager): void
    {
        if (!is_object($widgetsManager) || !method_exists($widgetsManager, 'register') || !class_exists('Elementor\\Widget_Base')) {
            return;
        }

        $widgetsManager->register(new ClassicWidget());

        if (self::atomicAvailable()) {
            $widgetsManager->register(new AtomicWidget());
        }
    }

    /**
     * Returns whether the atomic widget can be registered.
     *
     * @return bool True when the Atomic Widgets feature is active and complete.
     */
    public static function atomicAvailable(): bool
    {
        foreach (self::ATOMIC_CLASSES as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }

        foreach (self::ATOMIC_TRAITS as $trait) {
            if (!trait_exists($trait)) {
                return false;
            }
        }

        try {
            return (bool) \Elementor\Modules\AtomicWidgets\Module::is_active();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Registers the lifecycle script so widgets can declare it as a dependency.
     *
     * It depends on the plugin's front-end bundle, which provides the grid
     * search binding it reuses. That bundle is enqueued on every front-end page
     * by {@see \MediaApiWidget\Frontend\Assets}; WordPress resolves the
     * dependency when scripts are printed.
     *
     * @return void
     */
    public function registerScripts(): void
    {
        wp_register_script(
            self::FRONTEND_HANDLE,
            MAW_PLUGIN_URL . 'assets/elementor/media-api-elementor.js',
            [self::PLUGIN_SCRIPT_HANDLE],
            MAW_PLUGIN_VERSION,
            true
        );
    }

    /**
     * Enqueues the atomic editor view, before Elementor's editor boots.
     *
     * @return void
     */
    public function enqueueEditorScripts(): void
    {
        if (!self::atomicAvailable()) {
            return;
        }

        wp_enqueue_script(
            self::ATOMIC_EDITOR_HANDLE,
            MAW_PLUGIN_URL . 'assets/elementor/media-api-atomic-editor.js',
            ['elementor-v2-editor-canvas', 'elementor-v2-editor-v1-adapters'],
            MAW_PLUGIN_VERSION,
            true
        );

        wp_localize_script(self::ATOMIC_EDITOR_HANDLE, 'mawElementorAtomicEditor', [
            'type' => self::ATOMIC_TYPE,
        ]);
    }
}
