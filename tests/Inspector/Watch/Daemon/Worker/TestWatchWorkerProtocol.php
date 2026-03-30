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
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerMessage;
use Reli\Inspector\Watch\Daemon\Protocol\PhpWatchWorkerProtocolInterface;

final class TestWatchWorkerProtocol implements PhpWatchWorkerProtocolInterface
{
    public bool $settings_sent = false;
    public int $attach_count = 0;
    public ?WatchDetachMessage $detach_message = null;
    /** @var list<WatchTriggerMessage> */
    public array $triggers = [];

    public function __construct(
        private WatchSettingsMessage $settings_message,
        private WatchAttachMessage $attach_message,
    ) {
    }

    public static function createFromChannel(Channel $channel): static
    {
        throw new \LogicException('Not used in tests');
    }

    public function receiveSettings(): WatchSettingsMessage
    {
        $this->settings_sent = true;
        return $this->settings_message;
    }

    public function receiveAttach(): WatchAttachMessage
    {
        $this->attach_count++;
        return $this->attach_message;
    }

    public function sendTrigger(WatchTriggerMessage $message): void
    {
        $this->triggers[] = $message;
    }

    public function sendDetach(WatchDetachMessage $message): void
    {
        $this->detach_message = $message;
    }
}
