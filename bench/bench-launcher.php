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

$embed = Parallel::boot()
    ->withIniEntry('zend.max_allowed_stack_size', '-1')
    ->withIniEntry('fiber.stack_size', '8M')
    ->withIniEntry('ffi.enable', 'true');

switch (getenv('RELI_BENCH_OPCACHE')) {
    case 'preload':
        $embed = $embed
            ->withIniEntry('opcache.enable_cli', '1')
            ->withIniEntry('opcache.memory_consumption', '256')
            ->withIniEntry('opcache.preload', __DIR__ . '/preload.php')
            ->withIniEntry('opcache.preload_user', get_current_user());
        break;
    case 'on':
        $embed = $embed
            ->withIniEntry('opcache.enable_cli', '1')
            ->withIniEntry('opcache.memory_consumption', '256');
        break;
    case 'off':
    case '':
    default:
        // opcache stays inactive
        break;
}

$embed->runScript(__DIR__ . '/bench-runner.php');
