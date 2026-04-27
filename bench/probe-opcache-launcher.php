<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

$embed = Parallel::boot()
    ->withIniEntry('zend.max_allowed_stack_size', '-1')
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
    case 'on':
        $embed = $embed
            ->withIniEntry('opcache.enable_cli', '1')
            ->withIniEntry('opcache.memory_consumption', '256');
        break;
}

$embed->runScript(__DIR__ . '/probe-opcache.php');
