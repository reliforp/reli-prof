<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

$n = (int) ($argv[1] ?? 3);
putenv("RELI_BENCH_N={$n}");

Parallel::boot()
    ->withIniEntry('zend.max_allowed_stack_size', '-1')
    ->withIniEntry('fiber.stack_size', '8M')
    ->withIniEntry('ffi.enable', 'true')
    ->runScript(__DIR__ . '/bench-runner-live.php');
