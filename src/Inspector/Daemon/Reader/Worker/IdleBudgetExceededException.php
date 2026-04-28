<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Reli\Inspector\Daemon\Reader\Worker;

/**
 * Raised by PhpReaderTraceLoop when a target TID has stayed off the
 * Zend VM (current_execute_data == 0) for longer than the idle-tick
 * budget — the signal to release the worker so the dispatcher can
 * reassign it. Caught by the reader's
 * ExitLoopOnSpecificExceptionMiddlewareAsync, which terminates the
 * AsyncLoop cleanly so the reader sends DetachWorkerMessage instead
 * of looping forever inside the retry middleware.
 */
final class IdleBudgetExceededException extends \RuntimeException
{
}
