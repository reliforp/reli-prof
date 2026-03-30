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

namespace Reli\Inspector\Watch\Daemon\Context;

use Reli\Inspector\Daemon\AutoContextRecovering;
use Reli\Inspector\Watch\Daemon\Controller\PhpWatchController;
use Reli\Inspector\Watch\Daemon\Controller\PhpWatchControllerInterface;
use Reli\Inspector\Watch\Daemon\Controller\PhpWatchControllerProtocol;
use Reli\Inspector\Watch\Daemon\Worker\PhpWatchEntryPoint;
use Reli\Inspector\Watch\Daemon\Worker\PhpWatchWorkerProtocol;
use Reli\Lib\Amphp\ContextCreatorInterface;

final class PhpWatchContextCreator
{
    public function __construct(
        private ContextCreatorInterface $context_creator,
    ) {
    }

    public function create(): PhpWatchControllerInterface
    {
        return new PhpWatchController(
            new AutoContextRecovering(
                fn () => $this->context_creator->create(
                    PhpWatchEntryPoint::class,
                    PhpWatchWorkerProtocol::class,
                    PhpWatchControllerProtocol::class,
                )
            )
        );
    }
}
