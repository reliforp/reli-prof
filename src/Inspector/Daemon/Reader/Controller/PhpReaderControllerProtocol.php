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

namespace PhpProfiler\Inspector\Daemon\Reader\Controller;

use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\PhpReaderControllerProtocolInterface;

final class PhpReaderControllerProtocol implements PhpReaderControllerProtocolInterface
{
    public function __construct(
        private Channel $channel
    ) {
    }

    public static function createFromChannel(Channel $channel): static
    {
        return new self($channel);
    }

    public function sendSettings(SetSettingsMessage $message): Promise
    {
        return $this->channel->send($message);
    }

    public function sendAttach(AttachMessage $message): Promise
    {
        return $this->channel->send($message);
    }

    /** @return Promise<TraceMessage|DetachWorkerMessage> */
    public function receiveTraceOrDetachWorker(): Promise
    {
        /** @var Promise<TraceMessage|DetachWorkerMessage> */
        return $this->channel->receive();
    }
}
