<?php

require_once dirname(__DIR__) . '/utils.php';
require_once dirname(__DIR__) . '/scoring.php';
require_once dirname(__DIR__) . '/render.php';
require_once __DIR__ . '/tests.php';

$tests = scoringTestSuite();
$failures = 0;

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}

if ($failures > 0) {
    exit(1);
}

echo "OK " . count($tests) . " tests\n";
