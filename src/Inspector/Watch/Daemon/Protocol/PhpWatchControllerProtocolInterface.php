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
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchSettingsMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchWorkerMessage;
use Reli\Lib\Amphp\MessageProtocolInterface;

interface PhpWatchControllerProtocolInterface extends MessageProtocolInterface
{
    public function sendSettings(WatchSettingsMessage $message): void;

    public function sendAttach(WatchAttachMessage $message): void;

    public function receiveMessage(): WatchWorkerMessage;
}
