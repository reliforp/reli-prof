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

namespace PhpProfiler\Inspector\Daemon\Reader\Protocol;

use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use PhpProfiler\Lib\Amphp\MessageProtocolInterface;

interface PhpReaderControllerProtocolInterface extends MessageProtocolInterface
{
    /** @return Promise<int> */
    public function sendSettings(SetSettingsMessage $message): Promise;

    /** @return Promise<int> */
    public function sendAttach(AttachMessage $message): Promise;

    /** @return Promise<TraceMessage|DetachWorkerMessage> */
    public function receiveTraceOrDetachWorker(): Promise;
}
