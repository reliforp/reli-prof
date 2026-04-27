<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

$n = (int) ($argv[1] ?? 1);
putenv("RELI_BENCH_N={$n}");

$embed = Parallel::boot()
    ->withIniEntry('zend.max_allowed_stack_size', '-1')
    ->withIniEntry('fiber.stack_size', '8M')
    ->withIniEntry('ffi.enable', 'true')
    ->withIniEntry('opcache.enable_cli', '1')
    ->withIniEntry('opcache.memory_consumption', '256');

if (getenv('RELI_BENCH_JIT') === '1') {
    $embed = $embed
        ->withIniEntry('opcache.jit_buffer_size', '64M')
        ->withIniEntry('opcache.jit', 'tracing');
} else {
    $embed = $embed->withIniEntry('opcache.jit', 'off');
}

$embed->runScript(__DIR__ . '/bench-runner-perf.php');
