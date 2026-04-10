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

namespace Reli\Inspector\Watch\Daemon\Protocol;

use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchAttachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchDetachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchSettingsMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTraceNotifyMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerMessage;
use Reli\Lib\Amphp\MessageProtocolInterface;

interface PhpWatchWorkerProtocolInterface extends MessageProtocolInterface
{
    public function receiveSettings(): WatchSettingsMessage;

    public function receiveAttach(): WatchAttachMessage;

    public function sendTrigger(WatchTriggerMessage $message): void;

    public function sendTraceNotify(WatchTraceNotifyMessage $message): void;

    public function sendDetach(WatchDetachMessage $message): void;
}
