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

use Reli\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Lib\Session\Attribute\Initial;
use Reli\Lib\Session\Attribute\Recv;
use Reli\Lib\Session\Attribute\Send;
use Reli\Lib\Session\Attribute\SessionProtocol;

/**
 * Session type definition for the PhpSearcher protocol.
 *
 * Worker perspective:
 *   ?TargetPhpSettings . μX. !UpdateTargetProcess . X
 */
#[SessionProtocol(
    name: 'PhpSearcher',
    workerNamespace: 'Reli\Inspector\Daemon\Searcher\Worker',
    controllerNamespace: 'Reli\Inspector\Daemon\Searcher\Controller',
)]
enum PhpSearcherSession
{
    #[Initial]
    #[Recv(TargetPhpSettingsMessage::class, next: self::Reporting)]
    case AwaitingSettings;

    #[Send(UpdateTargetProcessMessage::class, next: self::Reporting)]
    case Reporting;
}
