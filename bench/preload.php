<?php

declare(strict_types=1);

/**
 * Pre-loaded by the ZTS embed. Pulls reli + its vendor class graph into
 * opcache SHM so every parallel\Runtime thread sees the classes already
 * compiled instead of paying its own zend_compile cost.
 *
 * Files that crash opcache_compile_file (e.g. ones using FFI::cdef in
 * static initialisers, or with first-class callable syntax that opcache
 * refuses to preload) are skipped silently -- preload is best-effort here.
 */

require __DIR__ . '/../vendor/autoload.php';

$classmap = require __DIR__ . '/../vendor/composer/autoload_classmap.php';

$runtimeVendors = [
    '/vendor/amphp/',
    '/vendor/revolt/',
    '/vendor/php-di/',
    '/vendor/symfony/',
    '/vendor/monolog/',
    '/vendor/psr/',
    '/vendor/hassankhan/',
    '/vendor/noodlehaus/',
    '/vendor/sj-i/',
    '/vendor/webmozart/',
];

$loaded = 0;
$skipped = 0;
foreach ($classmap as $class => $file) {
    if (!is_string($file) || !is_file($file)) {
        continue;
    }
    if (str_contains($file, '/tests/') || str_contains($file, '/Tests/')) {
        continue;
    }
    $isReli = str_contains($file, '/reli-prof/src/') || str_contains($file, $_SERVER['PWD'] ?? '/src/');
    $isRuntimeVendor = false;
    foreach ($runtimeVendors as $needle) {
        if (str_contains($file, $needle)) {
            $isRuntimeVendor = true;
            break;
        }
    }
    if (!$isReli && !$isRuntimeVendor) {
        continue;
    }
    try {
        if (opcache_compile_file($file)) {
            $loaded++;
        } else {
            $skipped++;
        }
    } catch (\Throwable) {
        $skipped++;
    }
}

fwrite(STDERR, "[preload] compiled={$loaded} skipped={$skipped}\n");
