<?php

declare(strict_types=1);

/**
 * Outer NTS launcher. Boots the ZTS embed shipped by sj-i/ffi-zts-parallel
 * and runs inner.php inside it. Inside the embed, ext-parallel is loaded,
 * so amphp/parallel's DefaultContextFactory auto-selects ThreadContextFactory
 * when reli's ContextCreator calls Amp\Parallel\Context\startContext().
 *
 * No reli source change is required for the swap itself: the entire daemon
 * upper layer (worker-entry.php, channels, protocols) is context-agnostic.
 *
 * Run on PHP 8.5 NTS with sj-i/ffi-zts-parallel installed:
 *
 *   composer require --dev sj-i/ffi-zts-parallel
 *   php examples/thread-mode/launch.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

Parallel::boot()
    ->withIniEntry('opcache.enable_cli', '1')
    ->runScript(__DIR__ . '/inner.php');
