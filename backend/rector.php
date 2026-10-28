<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;

// Rector keeps the code on current PHP idioms and strips dead code
// (`composer rector` to apply, `composer rector:check` — the CI step — to list).
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/api',
        __DIR__ . '/includes',
        __DIR__ . '/scripts',
        __DIR__ . '/tests',
        __DIR__ . '/og.php',
        __DIR__ . '/dev-router.php',
    ])
    ->withPhpSets(php85: true)
    ->withPreparedSets(deadCode: true)
    ->withSkip([
        // Production compiles the DI container, which has to export each factory:
        // an array callable compiles as-is, a first-class-callable closure is
        // code PHP-DI would have to lift out of the file.
        ArrayToFirstClassCallableRector::class => [__DIR__ . '/api/dependencies.php'],
    ]);
