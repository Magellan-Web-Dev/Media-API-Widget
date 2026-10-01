<?php
namespace MediaApiWidget\Integrations\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if (!defined('ABSPATH')) { exit; }

/**
 * "Media API — Classic" Elementor widget.
 *
 * Controls are generated from {@see WidgetSchema}; rendering is delegated to
 * {@see WidgetRenderer}, which invokes the existing shortcode callbacks. The
 * widget emits no markup of its own around the shortcode output, so the
 * Elementor wrapper contains exactly what the equivalent shortcode returns.
 *
 * Loaded only from {@see Integration::registerWidgets()}, i.e. only once
 * Elementor has defined Widget_Base.
 */
final class ClassicWidget extends Widget_Base
{
    /** Elementor widget name. */
    public const NAME = 'maw-media-api';

    /** Classic `conditions` operators for the schema's rule operators. */
    private const OPERATORS = ['eq' => '===', 'ne' => '!==', 'in' => 'in', 'nin' => '!in'];

    /**
     * @return string Widget name.
     */
    public function get_name()
    {
        return self::NAME;
    }

    /**
     * @return string Widget title.
     */
    public function get_title()
    {
        return __('Media API — Classic', 'media-api-widget');
    }

    /**
     * @return string Panel icon.
     */
    public function get_icon()
    {
        return 'eicon-youtube';
    }

    /**
     * @return array<int,string> Panel categories.
     */
    public function get_categories()
    {
        return [Integration::CATEGORY];
    }

    /**
     * @return array<int,string> Search keywords.
     */
    public function get_keywords()
    {
        return ['media', 'youtube', 'podcast', 'playlist', 'video', 'grid', 'search', 'lightbox'];
    }

    /**
     * Loads the integration lifecycle script wherever the widget is used.
     *
     * @return array<int,string> Script handles.
     */
    public function get_script_depends()
    {
        return [Integration::FRONTEND_HANDLE];
    }

    /**
     * Output depends on cached media data, stored fields and the request's page
     * parameter, so Elementor must not cache it as static content.
     *
     * @return bool Always true.
     */
    protected function is_dynamic_content(): bool
    {
        return true;
    }

    /**
     * Keeps the widget out of Elementor's element cache even when its Advanced
     * "Cache Settings" control is set to Active.
     *
     * A cached copy would keep serving a grid key whose settings transient
     * expires after a day, and would freeze the server-rendered pagination page.
     * When element caching is off this returns false, as the parent does.
     *
     * @return bool True whenever element caching is enabled.
     */
    protected function should_render_shortcode()
    {
        return (bool) apply_filters('elementor/element/should_render_shortcode', false);
    }

    /**
     * Registers the schema's sections and controls.
     *
     * @return void
     */
    protected function register_controls()
    {
        $structural = WidgetSchema::structuralControls();
        $specs      = WidgetSchema::attributeSpecs();

        foreach (WidgetSchema::sections() as $sectionId => $sectionLabel) {
            $controls = [];
            $rules    = [];

            foreach ($structural as $key => $control) {
                if ($control['section'] === $sectionId) {
                    $controls[$key] = $this->structuralArgs($control);
                    $rules[]        = $control['when'];
                }
            }

            foreach ($specs as $key => $spec) {
                if ($spec['section'] !== $sectionId) {
                    continue;
                }

                $controls[$key] = $this->sourceArgs($spec);
                $rules[]        = $spec['when'];

                if (WidgetSchema::hasValueControl($spec)) {
                    $controls[WidgetSchema::valueKey($key)] = $this->valueArgs($key, $spec);
                }
            }

            if ($controls === []) {
                continue;
            }

            $this->start_controls_section('maw_section_' . $sectionId, ['label' => $sectionLabel] + self::sectionConditions($rules));

            foreach ($controls as $key => $args) {
                $this->add_control($key, $args);
            }

            $this->end_controls_section();
        }
    }

    /**
     * Renders the widget through the shared renderer.
     *
     * @return void
     */
    protected function render()
    {
        // Shortcode output is escaped by the shortcode renderers themselves.
        echo WidgetRenderer::render($this->flatSettings(), (string) $this->get_id()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Writes the equivalent shortcode text to post_content on save.
     *
     * @return void
     */
    public function render_plain_content()
    {
        echo ShortcodeText::forSettings($this->flatSettings(), (string) $this->get_id()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Returns the widget's raw settings as the flat map the adapter expects.
     *
     * Raw settings (not "for display") are used so the adapter applies its own
     * visibility rules — the same rules the atomic widget is evaluated with.
     * Global colors and dynamic tags are not enabled on these controls, so
     * nothing needs resolving.
     *
     * @return array<string,mixed> Settings.
     */
    private function flatSettings(): array
    {
        $settings = $this->get_settings();

        return is_array($settings) ? $settings : [];
    }

    /**
     * Builds add_control() args for a structural control.
     *
     * @param array<string,mixed> $control Structural spec.
     * @return array<string,mixed> Control args.
     */
    private function structuralArgs(array $control): array
    {
        $args = [
            'label'   => $control['label'],
            'default' => $control['default'],
        ];

        if ($control['type'] === 'select') {
            $args['type']    = Controls_Manager::SELECT;
            $args['options'] = $control['options'];
        } else {
            $args['type']        = Controls_Manager::TEXT;
            $args['placeholder'] = (string) ($control['placeholder'] ?? '');
        }

        if (!empty($control['description'])) {
            $args['description'] = $control['description'];
        }

        return $args + self::conditions($control['when']);
    }

    /**
     * Builds add_control() args for an attribute spec's source select.
     *
     * @param array<string,mixed> $spec Attribute spec.
     * @return array<string,mixed> Control args.
     */
    private function sourceArgs(array $spec): array
    {
        $args = [
            'label'   => $spec['label'],
            'type'    => Controls_Manager::SELECT,
            'options' => WidgetSchema::sourceOptions($spec),
            'default' => $spec['default'],
        ];

        if ($spec['description'] !== '') {
            $args['description'] = $spec['description'];
        }

        return $args + self::conditions($spec['when']);
    }

    /**
     * Builds add_control() args for an attribute spec's value control.
     *
     * Podcast player colors are passed to /podcast/player, which accepts hex
     * only, so their color pickers have no alpha channel.
     *
     * @param string              $key  Spec setting key.
     * @param array<string,mixed> $spec Attribute spec.
     * @return array<string,mixed> Control args.
     */
    private function valueArgs(string $key, array $spec): array
    {
        $args = [
            'label'      => $spec['label'],
            'show_label' => false,
        ];

        switch ($spec['kind']) {
            case 'color':
                $args['type']   = Controls_Manager::COLOR;
                $args['alpha']  = !str_starts_with((string) $spec['attr'], 'podcast');
                $args['global'] = ['active' => false];
                $args['default'] = (string) $spec['default_value'];
                break;
            case 'number':
                $args['type']    = Controls_Manager::NUMBER;
                $args['min']     = 0;
                $args['step']    = 1;
                $args['default'] = (string) $spec['default_value'];
                break;
            case 'image':
                $args['type']    = Controls_Manager::MEDIA;
                $args['default'] = ['url' => (string) $spec['default_value']];
                break;
            default:
                $args['type']        = Controls_Manager::TEXT;
                $args['label_block'] = true;
                $args['default']     = (string) $spec['default_value'];
        }

        if ($spec['placeholder'] !== '') {
            $args['placeholder'] = $spec['placeholder'];
        }

        return $args + self::conditions(WidgetSchema::valueWhen($key, $spec));
    }

    /**
     * Converts schema rule leaves to a classic `conditions` argument.
     *
     * @param array<int,array{0:string,1:string,2:mixed}> $when Rule leaves.
     * @return array<string,mixed> `['conditions' => …]`, or [] when always visible.
     */
    private static function conditions(array $when): array
    {
        $terms = self::terms($when);

        return $terms === [] ? [] : ['conditions' => ['relation' => 'and', 'terms' => $terms]];
    }

    /**
     * Builds a section condition: visible when any of its controls can be.
     *
     * @param array<int,array<int,array{0:string,1:string,2:mixed}>> $rules One rule list per control.
     * @return array<string,mixed> `['conditions' => …]`, or [] when always visible.
     */
    private static function sectionConditions(array $rules): array
    {
        $groups = [];

        foreach ($rules as $when) {
            $terms = self::terms($when);
            if ($terms === []) {
                return [];
            }

            $groups[] = ['relation' => 'and', 'terms' => $terms];
        }

        return ['conditions' => ['relation' => 'or', 'terms' => $groups]];
    }

    /**
     * Converts rule leaves to classic condition terms.
     *
     * @param array<int,array{0:string,1:string,2:mixed}> $when Rule leaves.
     * @return array<int,array<string,mixed>> Terms.
     */
    private static function terms(array $when): array
    {
        $terms = [];

        foreach ($when as [$key, $operator, $value]) {
            $terms[] = [
                'name'     => $key,
                'operator' => self::OPERATORS[$operator],
                'value'    => $value,
            ];
        }

        return $terms;
    }
}
