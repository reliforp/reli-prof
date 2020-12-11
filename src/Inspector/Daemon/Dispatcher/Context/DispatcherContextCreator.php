<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Daemon\Dispatcher\Context;

use PhpProfiler\Inspector\Daemon\Dispatcher\Controller\DispatcherController;
use PhpProfiler\Inspector\Daemon\Dispatcher\Controller\DispatcherControllerProtocol;
use PhpProfiler\Inspector\Daemon\Dispatcher\Worker\DispatcherEntryPoint;
use PhpProfiler\Inspector\Daemon\Dispatcher\Worker\DispatcherWorkerProtocol;
use PhpProfiler\Lib\Amphp\ContextCreatorInterface;

final class DispatcherContextCreator
{
    private ContextCreatorInterface $context_creator;

    public function __construct(
        ContextCreatorInterface $context_creator
    ) {
        $this->context_creator = $context_creator;
    }

    public function create(): DispatcherController
    {
        return new DispatcherController(
            $this->context_creator->create(
                DispatcherEntryPoint::class,
                DispatcherWorkerProtocol::class,
                DispatcherControllerProtocol::class
            )
        );
    }
}