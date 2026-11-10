<?php
/**
 * Atomic widget compatibility probe against a real Elementor install.
 *
 * The main suite never loads Elementor. This script does: it loads the atomic
 * classes of the given Elementor copy through Elementor's own autoloader and
 * then exercises the "Media API — Atomic" widget exactly where Elementor does
 * while booting the editor:
 *
 *   1. the dependency check behind {@see Integration::atomicAvailable()};
 *   2. declaring the widget class, which makes PHP verify every overridden
 *      method signature against that Elementor version's base class;
 *   3. `get_atomic_controls()`, which calls `define_atomic_controls()` and
 *      validates every control against `get_props_schema()` (Elementor debug
 *      mode is on, so an invalid control throws instead of being dropped);
 *   4. JSON-encoding the controls and props schema as the editor config does.
 *
 * An undefined control method, such as the 6.2.0 `set_show_icon_library()`
 * fatal on any Elementor other than 4.3.x, fails step 3. Run it once per
 * Elementor copy:
 *
 *   php tests/elementor-atomic-compat.php /path/to/wp-content/plugins/elementor
 *
 * Exits 0 when the widget is compatible, or when the atomic widget would
 * correctly stay unregistered; 1 otherwise.
 *
 * The widget is created without its constructor, so this does not cover
 * Elementor's runtime (experiments, editor JS, rendering); those still need a
 * real editor session.
 */

declare(strict_types=1);

use MediaApiWidget\Integrations\Elementor\AtomicWidget;
use MediaApiWidget\Integrations\Elementor\Integration;

$elementor = rtrim((string) ($argv[1] ?? ''), '/');

if ($elementor === '' || !is_file($elementor . '/includes/autoloader.php')) {
    fwrite(STDERR, "Usage: php tests/elementor-atomic-compat.php /path/to/plugins/elementor\n");
    exit(2);
}

define('ELEMENTOR_PATH', $elementor . '/');
define('ELEMENTOR_DEBUG', true);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/support/wp-shortcodes.php';
require_once ELEMENTOR_PATH . 'includes/autoloader.php';
\Elementor\Autoloader::run();

// Elementor's element and widget managers require these two directly rather
// than autoloading them.
require_once ELEMENTOR_PATH . 'includes/base/element-base.php';
require_once ELEMENTOR_PATH . 'includes/base/widget-base.php';

preg_match("/define\(\s*'ELEMENTOR_VERSION',\s*'([^']+)'/", (string) file_get_contents(ELEMENTOR_PATH . 'elementor.php'), $match);
printf("Elementor %s (%s)\n", $match[1] ?? 'unknown', $elementor);

// 1. The class/trait half of Integration::atomicAvailable(). Its remaining
//    half, Module::is_active(), needs a booted Elementor and is not run here.
$integration = new ReflectionClass(Integration::class);
$missing     = array_merge(
    array_filter($integration->getConstant('ATOMIC_CLASSES'), static fn (string $c): bool => !class_exists($c)),
    array_filter($integration->getConstant('ATOMIC_TRAITS'), static fn (string $t): bool => !trait_exists($t))
);

if ($missing !== []) {
    echo "  OK   atomic widget stays unregistered; missing:\n";
    foreach ($missing as $name) {
        echo "         - {$name}\n";
    }
    exit(0);
}

echo "  OK   every atomic class and trait the widget uses exists\n";

$failed = false;
$fail   = static function (string $message) use (&$failed): void {
    $failed = true;
    echo "  FAIL {$message}\n";
};

// Saved media sources, so source selects carry real options.
MediaApiWidget\Config\Options::setMediaItems([
    ['type' => 'youtube', 'playlist_name' => 'show', 'api_key' => 'k', 'media_data' => 'PLx'],
    ['type' => 'podcast', 'playlist_name' => 'cast', 'podcast_platform' => 'custom', 'media_data' => 'https://feeds.example.com/cast.xml'],
]);

try {
    // 2. Declares every Elementor-facing class; an incompatible override is a
    //    fatal error here.
    class_exists(AtomicWidget::class);
    class_exists(MediaApiWidget\Integrations\Elementor\ClassicWidget::class);
    foreach (['MediaFieldTag', 'MediaImageTag', 'MediaUrlTag', 'MediaClickTag'] as $tag) {
        class_exists('MediaApiWidget\\Integrations\\Elementor\\DynamicTags\\' . $tag);
    }
    echo "  OK   atomic widget, classic widget and dynamic tags declare against this version\n";

    // 3. The editor's controls path.
    $widget   = (new ReflectionClass(AtomicWidget::class))->newInstanceWithoutConstructor();
    $controls = $widget->get_atomic_controls();
    $schema   = AtomicWidget::get_props_schema();
    echo '  OK   get_atomic_controls(): ' . count($controls) . " sections, every control bound to the schema\n";

    // 4. Editor config serialization.
    $encodedControls = json_decode(json_encode($controls, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    json_encode($schema, JSON_THROW_ON_ERROR);
    echo '  OK   controls and props schema (' . count($schema) . " props) serialize\n";

    $types = [];
    foreach ($encodedControls as $section) {
        foreach ($section['value']['items'] ?? [] as $item) {
            $types[$item['value']['type'] ?? '?'] = ($types[$item['value']['type'] ?? '?'] ?? 0) + 1;
        }
    }
    ksort($types);
    echo '       control types: ' . implode(', ', array_map(static fn ($t, $n) => "{$t}×{$n}", array_keys($types), $types)) . "\n";

    $svgPicker = class_exists('Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Svg_Control')
        && class_exists('Elementor\\Modules\\AtomicWidgets\\PropTypes\\Svg_Src_Prop_Type');
    if ($svgPicker && !isset($types['svg-media'])) {
        $fail('the SVG picker exists but no svg-media control was produced');
    } else {
        echo '  OK   SVG settings use ' . ($svgPicker ? 'the svg-media picker' : 'the text fallback') . "\n";
    }
} catch (Throwable $e) {
    $fail(get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo $failed ? "  => INCOMPATIBLE\n" : "  => compatible\n";
exit($failed ? 1 : 0);
