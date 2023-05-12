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

use Amp\Sync\Channel;
use Reli\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderWorkerProtocolInterface;

final class PhpReaderWorkerProtocol implements PhpReaderWorkerProtocolInterface
{
    public function __construct(
        private Channel $channel
    ) {
    }

    public static function createFromChannel(Channel $channel): static
    {
        return new self($channel);
    }

    public function receiveSettings(): SetSettingsMessage
    {
        /** @var SetSettingsMessage */
        return $this->channel->receive();
    }

    public function receiveAttach(): AttachMessage
    {
        /** @var AttachMessage */
        return $this->channel->receive();
    }

    public function sendTrace(TraceMessage $message): void
    {
        $this->channel->send($message);
    }

    public function sendDetachWorker(DetachWorkerMessage $message): void
    {
        $this->channel->send($message);
    }
}
