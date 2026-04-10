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

namespace Reli\Inspector\Watch\Daemon\Controller;

use Amp\Sync\Channel;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchAttachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchSettingsMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchWorkerMessage;
use Reli\Inspector\Watch\Daemon\Protocol\PhpWatchControllerProtocolInterface;

final class PhpWatchControllerProtocol implements PhpWatchControllerProtocolInterface
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
    public function sendSettings(WatchSettingsMessage $message): void
    {
        $this->channel->send($message);
    }

    #[\Override]
    public function sendAttach(WatchAttachMessage $message): void
    {
        $this->channel->send($message);
    }

    #[\Override]
    public function receiveMessage(): WatchWorkerMessage
    {
        /** @var WatchWorkerMessage */
        return $this->channel->receive();
    }
}
