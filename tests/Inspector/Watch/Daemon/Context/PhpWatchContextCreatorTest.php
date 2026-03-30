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

use Mockery;
use Reli\BaseTestCase;
use Reli\Inspector\Watch\Daemon\Controller\PhpWatchControllerProtocol;
use Reli\Inspector\Watch\Daemon\Worker\PhpWatchEntryPoint;
use Reli\Inspector\Watch\Daemon\Worker\PhpWatchWorkerProtocol;
use Reli\Lib\Amphp\ContextCreatorInterface;
use Reli\Lib\Amphp\ContextInterface;

class PhpWatchContextCreatorTest extends BaseTestCase
{
    public function testCreate(): void
    {
        $context = Mockery::mock(ContextInterface::class);
        $context_creator = Mockery::mock(ContextCreatorInterface::class);
        $context_creator->expects()
            ->create(
                PhpWatchEntryPoint::class,
                PhpWatchWorkerProtocol::class,
                PhpWatchControllerProtocol::class,
            )
            ->andReturns($context)
        ;
        $context->expects()->start();

        $creator = new PhpWatchContextCreator($context_creator);
        $controller = $creator->create();
        $controller->start();
    }
}
