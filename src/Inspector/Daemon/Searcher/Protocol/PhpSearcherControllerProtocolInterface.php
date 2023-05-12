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

use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use Reli\Lib\Amphp\MessageProtocolInterface;

interface PhpSearcherControllerProtocolInterface extends MessageProtocolInterface
{
    public function sendTargetRegex(TargetPhpSettingsMessage $message): void;

    public function receiveUpdateTargetProcess(): UpdateTargetProcessMessage;
}
