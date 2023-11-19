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

namespace Reli\Inspector\Daemon\Reader\Context;

use Reli\Inspector\Daemon\AutoContextRecovering;
use Reli\Inspector\Daemon\Reader\Controller\PhpReaderController;
use Reli\Inspector\Daemon\Reader\Controller\PhpReaderControllerInterface;
use Reli\Inspector\Daemon\Reader\Controller\PhpReaderControllerProtocol;
use Reli\Inspector\Daemon\Reader\Worker\PhpReaderWorkerProtocol;
use Reli\Inspector\Daemon\Reader\Worker\PhpReaderEntryPoint;
use Reli\Lib\Amphp\ContextCreatorInterface;

final class PhpReaderContextCreator implements PhpReaderContextCreatorInterface
{
    public function __construct(
        private ContextCreatorInterface $context_creator
    ) {
    }

    public function create(): PhpReaderControllerInterface
    {
        return new PhpReaderController(
            new AutoContextRecovering(
                fn () => $this->context_creator->create(
                    PhpReaderEntryPoint::class,
                    PhpReaderWorkerProtocol::class,
                    PhpReaderControllerProtocol::class
                )
            )
        );
    }
}
