<?php

declare(strict_types=1);

/**
 * Runs INSIDE the ZTS embed booted by launch.php. Demonstrates that
 * the same Amp\Parallel\Context\startContext() call reli already uses
 * (src/Lib/Amphp/ContextCreator.php) resolves to a thread-backed
 * ThreadContext here, with the worker sharing this script's PID.
 *
 * If this prints `same_pid=true`, swapping reli's daemon worker
 * substrate from process to thread is a zero-line change at the reli
 * layer -- the only thing that has to happen is making ext-parallel
 * available, which the ffi-zts-parallel embed does for us.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Amp\Parallel\Context;
use Amp\Parallel\Context\ThreadContext;

assert(PHP_ZTS === 1, 'inner.php must run inside the ZTS embed');
assert(extension_loaded('parallel'), 'ext-parallel must be loaded inside the embed');

fwrite(STDOUT, sprintf("PHP_ZTS=%d\n", PHP_ZTS));
fwrite(STDOUT, sprintf("ThreadContext::isSupported()=%s\n", var_export(ThreadContext::isSupported(), true)));
fwrite(STDOUT, sprintf("factory=%s\n", get_class(Context\contextFactory())));

$context = Context\startContext(__DIR__ . '/probe-worker.php');
$child_pid = $context->receive();
$context->join();

$host_pid = getmypid();
fwrite(STDOUT, sprintf(
    "host_pid=%d child_pid=%d same_pid=%s\n",
    $host_pid,
    $child_pid,
    var_export($host_pid === $child_pid, true),
));
