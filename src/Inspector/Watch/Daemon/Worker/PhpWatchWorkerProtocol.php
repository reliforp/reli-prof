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

namespace Reli\Inspector\Watch\Daemon\Worker;

use Amp\Sync\Channel;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchAttachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchDetachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchSettingsMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTraceNotifyMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerExitMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerMessage;
use Reli\Inspector\Watch\Daemon\Protocol\PhpWatchWorkerProtocolInterface;

final class PhpWatchWorkerProtocol implements PhpWatchWorkerProtocolInterface
{
    public function __construct(
        private Channel $channel,
    ) {
    }

    #[\Override]
    public static function createFromChannel(Channel $channel): static
    {
        return new self($channel);
    }

    #[\Override]
    public function receiveSettings(): WatchSettingsMessage
    {
        /** @var WatchSettingsMessage */
        return $this->channel->receive();
    }

    #[\Override]
    public function receiveAttach(): WatchAttachMessage
    {
        /** @var WatchAttachMessage */
        return $this->channel->receive();
    }

    #[\Override]
    public function sendTrigger(WatchTriggerMessage $message): void
    {
        $this->channel->send($message);
    }

    #[\Override]
    public function sendTriggerExit(WatchTriggerExitMessage $message): void
    {
        $this->channel->send($message);
    }

    #[\Override]
    public function sendTraceNotify(WatchTraceNotifyMessage $message): void
    {
        $this->channel->send($message);
    }

    #[\Override]
    public function sendDetach(WatchDetachMessage $message): void
    {
        $this->channel->send($message);
    }
}
