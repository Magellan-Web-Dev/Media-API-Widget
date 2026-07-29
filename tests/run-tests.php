<?php
/**
 * Test runner for Media API Widget.
 *
 * Loads the WordPress test doubles, then executes every `tests/cases/*.php`
 * file. Each case file returns an array of `name => callable`; the runner
 * resets all in-memory state before each callable so cases cannot leak into
 * one another.
 *
 * Usage:
 *   php tests/run-tests.php            # run everything
 *   php tests/run-tests.php pagination # run only cases whose file name matches
 *
 * Exits 0 when every assertion passed, 1 otherwise, so it can be wired into CI.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files  = glob(__DIR__ . '/cases/*.php') ?: [];
sort($files);

echo "Media API Widget test suite\n";
echo str_repeat('=', 60) . "\n";

foreach ($files as $file) {
    $group = basename($file, '.php');

    if ($filter !== '' && stripos($group, $filter) === false) {
        continue;
    }

    /** @var array<string,callable> $tests */
    $tests = require $file;

    echo "\n" . $group . "\n";

    foreach ($tests as $name => $test) {
        MawTestResults::$currentTest = $group . ' :: ' . $name;
        $before                      = MawTestResults::$failed;

        maw_test_reset();

        try {
            $test();
        } catch (Throwable $e) {
            MawTestResults::$failed++;
            MawTestResults::$failures[] = MawTestResults::$currentTest
                . ' — uncaught ' . get_class($e) . ': ' . $e->getMessage()
                . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        }

        printf("  %s %s\n", MawTestResults::$failed === $before ? 'PASS' : 'FAIL', $name);
    }
}

echo "\n" . str_repeat('=', 60) . "\n";

if (MawTestResults::$failures !== []) {
    echo "Failures:\n";
    foreach (MawTestResults::$failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
    echo "\n";
}

printf(
    "%d assertions passed, %d failed\n",
    MawTestResults::$passed,
    MawTestResults::$failed
);

exit(MawTestResults::$failed === 0 ? 0 : 1);
