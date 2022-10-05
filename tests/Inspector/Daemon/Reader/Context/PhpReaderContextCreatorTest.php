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

use Mockery;
use Reli\Inspector\Daemon\Reader\Controller\PhpReaderControllerInterface;
use Reli\Inspector\Daemon\Reader\Controller\PhpReaderControllerProtocol;
use Reli\Inspector\Daemon\Reader\Worker\PhpReaderEntryPoint;
use Reli\Inspector\Daemon\Reader\Worker\PhpReaderWorkerProtocol;
use Reli\Lib\Amphp\ContextCreatorInterface;
use Reli\Lib\Amphp\ContextInterface;
use PHPUnit\Framework\TestCase;

class PhpReaderContextCreatorTest extends TestCase
{
    public function testCreate()
    {
        $context = Mockery::mock(ContextInterface::class);
        $context_creator = Mockery::mock(ContextCreatorInterface::class);
        $context_creator->expects()
            ->create(
                PhpReaderEntryPoint::class,
                PhpReaderWorkerProtocol::class,
                PhpReaderControllerProtocol::class
            )
            ->andReturns($context);

        $php_reader_context_creator = new PhpReaderContextCreator($context_creator);
        $this->assertInstanceOf(
            PhpReaderControllerInterface::class,
            $php_reader_context_creator->create()
        );
    }
}
