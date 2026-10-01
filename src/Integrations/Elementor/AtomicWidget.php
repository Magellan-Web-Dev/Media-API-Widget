<?php
namespace MediaApiWidget\Integrations\Elementor;

use Elementor\Modules\AtomicWidgets\Controls\Section;
use Elementor\Modules\AtomicWidgets\Controls\Types\Number_Control;
use Elementor\Modules\AtomicWidgets\Controls\Types\Select_Control;
use Elementor\Modules\AtomicWidgets\Controls\Types\Text_Control;
use Elementor\Modules\AtomicWidgets\DynamicTags\Dynamic_Prop_Type;
use Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base;
use Elementor\Modules\AtomicWidgets\Elements\Base\Has_Template;
use Elementor\Modules\AtomicWidgets\Elements\Loader\Frontend_Assets_Loader;
use Elementor\Modules\AtomicWidgets\PropDependencies\Manager as Dependency_Manager;
use Elementor\Modules\AtomicWidgets\PropTypes\Attributes_Prop_Type;
use Elementor\Modules\AtomicWidgets\PropTypes\Classes_Prop_Type;
use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Number_Prop_Type;
use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type;

if (!defined('ABSPATH')) { exit; }

/**
 * "Media API — Atomic" Elementor widget.
 *
 * A genuine atomic (V4) element: its own props schema, atomic controls in the
 * V4 editing panel, prop dependencies for conditional controls, the Style tab
 * through the `classes` prop, a Twig wrapper template, and the atomic
 * frontend-handler lifecycle.
 *
 * The Twig template renders only the atomic root element (id, type, classes,
 * attributes, CSS id). Everything inside it is the shortcode output produced by
 * {@see WidgetRenderer}, injected as `settings.maw_output` during the server
 * render. Elementor's editor renders atomic templates client-side and cannot
 * run PHP there, so the editor view registered by
 * assets/elementor/media-api-atomic-editor.js shows the server render
 * (Elementor's `render_atomic_element` action) instead of rebuilding the media
 * markup in JavaScript.
 *
 * Loaded only from {@see Integration::registerWidgets()} once
 * {@see Integration::atomicAvailable()} has confirmed every class used here.
 */
final class AtomicWidget extends Atomic_Widget_Base
{
    use Has_Template {
        render as private renderAtomicTemplate;
    }

    /** Atomic element type. */
    public const TYPE = Integration::ATOMIC_TYPE;

    /** Twig template name. */
    private const TEMPLATE = 'media-api-widget/elements/media-api-atomic';

    /** @var string|null Shortcode output for the render in progress. */
    private ?string $mediaOutput = null;

    /**
     * @return string Element type.
     */
    public static function get_element_type(): string
    {
        return self::TYPE;
    }

    /**
     * @return string Widget title.
     */
    public function get_title()
    {
        return __('Media API — Atomic', 'media-api-widget');
    }

    /**
     * @return string Panel icon.
     */
    public function get_icon()
    {
        return 'eicon-youtube';
    }

    /**
     * @return array<int,string> Search keywords.
     */
    public function get_keywords()
    {
        return ['atomic', 'media', 'youtube', 'podcast', 'playlist', 'video', 'grid', 'search', 'lightbox'];
    }

    /**
     * Loads the atomic frontend-handlers runtime and the integration lifecycle.
     *
     * @return array<int,string> Script handles.
     */
    public function get_script_depends()
    {
        return array_merge(
            parent::get_script_depends(),
            [Frontend_Assets_Loader::FRONTEND_HANDLERS_HANDLE, Integration::FRONTEND_HANDLE]
        );
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
     * Builds the props schema from {@see WidgetSchema}.
     *
     * Source and structural props are plain strings without an enum: their
     * options include stored field names, which can change after a page was
     * saved, and an enum would make such a page fail validation on its next
     * save. The adapter validates every value instead. Visibility rules become
     * prop dependencies with the `hide` effect.
     *
     * @return array<string,mixed> Props schema.
     */
    protected static function define_props_schema(): array
    {
        $schema = [
            'classes' => Classes_Prop_Type::make()->default([]),
        ];

        foreach (WidgetSchema::structuralControls() as $key => $control) {
            $schema[$key] = String_Prop_Type::make()
                ->default((string) $control['default'])
                ->meta(Dynamic_Prop_Type::ignore())
                ->set_dependencies(self::dependencies($control['when']));
        }

        foreach (WidgetSchema::attributeSpecs() as $key => $spec) {
            $schema[$key] = String_Prop_Type::make()
                ->default((string) $spec['default'])
                ->meta(Dynamic_Prop_Type::ignore())
                ->set_dependencies(self::dependencies($spec['when']));

            if (!WidgetSchema::hasValueControl($spec)) {
                continue;
            }

            $value = $spec['kind'] === 'number'
                ? Number_Prop_Type::make()->default(is_numeric($spec['default_value']) ? (int) $spec['default_value'] : null)
                : String_Prop_Type::make()->default((string) $spec['default_value']);

            $schema[WidgetSchema::valueKey($key)] = $value->set_dependencies(self::dependencies(WidgetSchema::valueWhen($key, $spec)));
        }

        $schema['attributes'] = Attributes_Prop_Type::make();

        return $schema;
    }

    /**
     * Builds the V4 panel controls from {@see WidgetSchema}.
     *
     * @return array<int,Section> Control sections.
     */
    protected function define_atomic_controls(): array
    {
        $structural = WidgetSchema::structuralControls();
        $specs      = WidgetSchema::attributeSpecs();
        $sections   = [];

        foreach (WidgetSchema::sections() as $sectionId => $sectionLabel) {
            $items = [];

            foreach ($structural as $key => $control) {
                if ($control['section'] !== $sectionId) {
                    continue;
                }

                if ($control['type'] === 'select') {
                    $item = Select_Control::bind_to($key)->set_options(self::options($control['options']));
                } else {
                    $item = Text_Control::bind_to($key)->set_placeholder((string) ($control['placeholder'] ?? ''));
                }

                $item->set_label($control['label']);
                if (!empty($control['description'])) {
                    $item->set_description($control['description']);
                }

                $items[] = $item;
            }

            foreach ($specs as $key => $spec) {
                if ($spec['section'] !== $sectionId) {
                    continue;
                }

                $source = Select_Control::bind_to($key)
                    ->set_options(self::options(WidgetSchema::sourceOptions($spec)))
                    ->set_label($spec['label']);

                if ($spec['description'] !== '') {
                    $source->set_description($spec['description']);
                }

                $items[] = $source;

                if (!WidgetSchema::hasValueControl($spec)) {
                    continue;
                }

                $valueKey = WidgetSchema::valueKey($key);
                $value    = $spec['kind'] === 'number'
                    ? Number_Control::bind_to($valueKey)->set_min(0)->set_step(1)->set_should_force_int(true)
                    : Text_Control::bind_to($valueKey);

                $placeholder = $spec['placeholder'] !== '' ? $spec['placeholder'] : self::kindPlaceholder($spec['kind']);
                if ($placeholder !== '') {
                    $value->set_placeholder($placeholder);
                }

                $items[] = $value->set_label(sprintf(
                    /* translators: %s: setting label. */
                    __('%s — value', 'media-api-widget'),
                    $spec['label']
                ));
            }

            if ($items !== []) {
                $sections[] = Section::make()
                    ->set_label($sectionLabel)
                    ->set_id('maw-' . $sectionId)
                    ->set_items($items);
            }
        }

        $sections[] = Section::make()
            ->set_label(__('Settings', 'media-api-widget'))
            ->set_id('settings')
            ->set_items([
                Text_Control::bind_to('_cssid')
                    ->set_label(__('ID', 'media-api-widget'))
                    ->set_meta($this->get_css_id_control_meta()),
            ]);

        return $sections;
    }

    /**
     * @return array<string,string> Template name => path.
     */
    protected function get_templates(): array
    {
        return [
            self::TEMPLATE => __DIR__ . '/templates/media-api-atomic.html.twig',
        ];
    }

    /**
     * Renders the shortcode output, then the atomic wrapper template around it.
     *
     * @return void
     */
    protected function render()
    {
        $this->mediaOutput = WidgetRenderer::render(parent::get_atomic_settings(), (string) $this->get_id());

        try {
            $this->renderAtomicTemplate();
        } finally {
            $this->mediaOutput = null;
        }
    }

    /**
     * Adds the shortcode output to the template context during a render.
     *
     * @return array<string,mixed> Resolved settings.
     */
    public function get_atomic_settings(): array
    {
        $settings = parent::get_atomic_settings();

        if ($this->mediaOutput !== null) {
            $settings['maw_output'] = $this->mediaOutput;
        }

        return $settings;
    }

    /**
     * Writes the equivalent shortcode text to post_content on save.
     *
     * @return void
     */
    public function render_plain_content()
    {
        echo ShortcodeText::forSettings(parent::get_atomic_settings(), (string) $this->get_id()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Converts schema rule leaves to an atomic dependency definition.
     *
     * @param array<int,array{0:string,1:string,2:mixed}> $when Rule leaves.
     * @return array<string,mixed>|null Dependencies, or null when always visible.
     */
    private static function dependencies(array $when): ?array
    {
        if ($when === []) {
            return null;
        }

        $manager = Dependency_Manager::make(Dependency_Manager::RELATION_AND);

        foreach ($when as [$key, $operator, $value]) {
            $manager->where([
                'operator' => $operator,
                'path'     => [$key],
                'value'    => $value,
                'effect'   => 'hide',
            ]);
        }

        return $manager->get();
    }

    /**
     * Converts `value => label` options to the atomic select format.
     *
     * @param array<string,string> $options Options.
     * @return array<int,array{value:string,label:string}> Atomic options.
     */
    private static function options(array $options): array
    {
        $out = [];

        foreach ($options as $value => $label) {
            $out[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $out;
    }

    /**
     * Returns a placeholder hinting at the expected format of a text control.
     *
     * @param string $kind Spec kind.
     * @return string Placeholder.
     */
    private static function kindPlaceholder(string $kind): string
    {
        switch ($kind) {
            case 'color':
                return '#ffffff';
            case 'image':
                return 'https://';
            default:
                return '';
        }
    }
}
