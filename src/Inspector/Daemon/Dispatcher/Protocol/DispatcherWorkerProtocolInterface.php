<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Daemon\Dispatcher\Protocol;

use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Lib\Amphp\MessageProtocolInterface;

interface DispatcherWorkerProtocolInterface extends MessageProtocolInterface
{
    public function getSettings(): Promise;
    public function sendTraces(TraceMessage $trace_message): Promise;

}