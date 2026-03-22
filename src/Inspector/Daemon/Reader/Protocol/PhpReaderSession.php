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

use Reli\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Lib\Session\Attribute\Initial;
use Reli\Lib\Session\Attribute\Recv;
use Reli\Lib\Session\Attribute\Send;
use Reli\Lib\Session\Attribute\SessionProtocol;

/**
 * Session type definition for the PhpReader protocol.
 *
 * Worker perspective:
 *   ?SetSettings . μX. ?Attach . μY. ⊕{ !Trace.Y, !Detach.X }
 */
#[SessionProtocol(
    name: 'PhpReader',
    workerNamespace: 'Reli\Inspector\Daemon\Reader\Worker',
    controllerNamespace: 'Reli\Inspector\Daemon\Reader\Controller',
)]
enum PhpReaderSession
{
    #[Initial]
    #[Recv(SetSettingsMessage::class, next: 'AwaitingAttach')]
    case AwaitingSettings;

    #[Recv(AttachMessage::class, next: 'Tracing')]
    case AwaitingAttach;

    #[Send(TraceMessage::class, next: 'Tracing')]
    #[Send(DetachWorkerMessage::class, next: 'AwaitingAttach')]
    case Tracing;
}
