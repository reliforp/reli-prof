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

namespace PhpProfiler\Inspector\Daemon\Reader\Protocol;

use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use PhpProfiler\Lib\Amphp\MessageProtocolInterface;

interface PhpReaderWorkerProtocolInterface extends MessageProtocolInterface
{
    /**
     * @return Promise<SetSettingsMessage>
     */
    public function receiveSettings(): Promise;

    /**
     * @return Promise<AttachMessage>
     */
    public function receiveAttach(): Promise;

    public function sendTrace(TraceMessage $message): Promise;

    public function sendDetachWorker(DetachWorkerMessage $message): Promise;
}
