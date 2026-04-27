<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

$n = (int) ($argv[1] ?? 5);
putenv("RELI_BENCH_N={$n}");

$embed = Parallel::boot()
    ->withIniEntry('zend.max_allowed_stack_size', '-1')
    ->withIniEntry('fiber.stack_size', '8M')
    ->withIniEntry('ffi.enable', 'true');

switch (getenv('RELI_BENCH_OPCACHE')) {
    case 'truly-off':
        $embed = $embed->withIniEntry('opcache.enable', '0');
        break;
    case 'preload':
        $embed = $embed
            ->withIniEntry('opcache.enable_cli', '1')
            ->withIniEntry('opcache.memory_consumption', '256')
            ->withIniEntry('opcache.preload', __DIR__ . '/preload.php')
            ->withIniEntry('opcache.preload_user', get_current_user());
        break;
    case 'preload-tight':
        $embed = $embed
            ->withIniEntry('opcache.enable_cli', '1')
            ->withIniEntry('opcache.memory_consumption', '256')
            ->withIniEntry('opcache.preload', __DIR__ . '/preload-tight.php')
            ->withIniEntry('opcache.preload_user', get_current_user());
        break;
}

$embed->runScript(__DIR__ . '/bench-runner-stages.php');
