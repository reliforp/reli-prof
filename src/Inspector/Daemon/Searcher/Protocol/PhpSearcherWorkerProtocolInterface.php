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

namespace Reli\Inspector\Daemon\Searcher\Protocol;

use Amp\Promise;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Lib\Amphp\MessageProtocolInterface;

interface PhpSearcherWorkerProtocolInterface extends MessageProtocolInterface
{
    /** @return Promise<TargetPhpSettingsMessage> */
    public function receiveTargetPhpSettings(): Promise;

    public function sendUpdateTargetProcess(UpdateTargetProcessMessage $message): Promise;
}
