<?php

declare(strict_types=1);

/**
 * Minimal "does threading actually work in the embed?" check, no amphp.
 * Run from the NTS host:
 *
 *   php examples/thread-mode/probe-runtime.php
 *
 * Boots the ZTS embed and runs a single parallel\Runtime worker inside
 * it; if same_pid=true the worker is a real OS thread sharing the host
 * process, not a child process.
 */

require __DIR__ . '/../../vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

Parallel::boot()->runScript(__DIR__ . '/probe-runtime-inner.php');
