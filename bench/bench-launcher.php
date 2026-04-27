<?php

declare(strict_types=1);

/**
 * Boots the ffi-zts ZTS embed and runs bench-runner.php inside it. Forwards
 * the worker-count argument via the RELI_BENCH_N env var because Embed::
 * runScript() does not propagate argv into the embedded interpreter.
 */

require __DIR__ . '/../vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

$n = (int) ($argv[1] ?? 3);
putenv("RELI_BENCH_N={$n}");

Parallel::boot()
    ->withIniEntry('zend.max_allowed_stack_size', '-1')
    ->withIniEntry('fiber.stack_size', '8M')
    ->withIniEntry('ffi.enable', 'true')
    ->runScript(__DIR__ . '/bench-runner.php');
