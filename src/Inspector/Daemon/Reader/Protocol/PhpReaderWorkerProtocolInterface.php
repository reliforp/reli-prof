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

namespace Reli\Inspector\Daemon\Reader\Protocol;

use Reli\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use Reli\Lib\Amphp\MessageProtocolInterface;

interface PhpReaderWorkerProtocolInterface extends MessageProtocolInterface
{
    public function receiveSettings(): SetSettingsMessage;

    public function receiveAttach(): AttachMessage;

    public function sendTrace(TraceMessage $message): void;

    public function sendDetachWorker(DetachWorkerMessage $message): void;
}
