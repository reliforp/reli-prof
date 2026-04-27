<?php

declare(strict_types=1);

/**
 * Wraps reli's CLI in the ffi-zts ZTS embed so amphp/parallel picks
 * ThreadContextFactory automatically. Forwards argv via env var because
 * Embed::runScript() does not propagate argv into the embedded interp.
 */

require __DIR__ . '/../vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

$forward = $argv;
array_shift($forward); // drop launcher script path
putenv('RELI_THREAD_ARGV=' . json_encode($forward));

Parallel::boot()
    ->withIniEntry('zend.max_allowed_stack_size', '-1')
    ->withIniEntry('fiber.stack_size', '8M')
    ->withIniEntry('ffi.enable', 'true')
    ->withIniEntry('opcache.enable', '0')
    ->runScript(__DIR__ . '/reli-thread-inner.php');
