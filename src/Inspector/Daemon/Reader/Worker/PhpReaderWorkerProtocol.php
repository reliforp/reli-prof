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

namespace PhpProfiler\Inspector\Daemon\Reader\Worker;

use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\PhpReaderWorkerProtocolInterface;

final class PhpReaderWorkerProtocol implements PhpReaderWorkerProtocolInterface
{
    public function __construct(
        private Channel $channel
    ) {
    }

    /** @return static */
    public static function createFromChannel(Channel $channel): self
    {
        return new self($channel);
    }

    public function receiveSettings(): Promise
    {
        /** @var Promise<SetSettingsMessage> */
        return $this->channel->receive();
    }

    public function receiveAttach(): Promise
    {
        /** @var Promise<AttachMessage> */
        return $this->channel->receive();
    }

    public function sendTrace(TraceMessage $message): Promise
    {
        return $this->channel->send($message);
    }

    public function sendDetachWorker(DetachWorkerMessage $message): Promise
    {
        return $this->channel->send($message);
    }
}
