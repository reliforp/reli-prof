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
 *   php -d zend.max_allowed_stack_size=-1 examples/thread-mode/launch.php
 *
 * Verified on PHP 8.5.4 NTS (host) + libphp 8.5.5 ZTS (embed) + parallel
 * 1.2.12: inner.php prints `same_pid=true` confirming the worker started by
 * Amp\Parallel\Context\startContext() runs in the same process as the host.
 *
 * The host -d flag is necessary because amphp/parallel's worker bootstrap
 * (Revolt event loop + ipcHub accept) recurses past the default 8 MB Zend
 * stack when called from inside an FFI-driven embed; the embed-side ini
 * below covers the inner interpreter's same limit.
 */

require __DIR__ . '/../../vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

Parallel::boot()
    ->withIniEntry('opcache.enable_cli', '1')
    ->withIniEntry('zend.max_allowed_stack_size', '-1')
    ->withIniEntry('fiber.stack_size', '8M')
    ->runScript(__DIR__ . '/inner.php');
