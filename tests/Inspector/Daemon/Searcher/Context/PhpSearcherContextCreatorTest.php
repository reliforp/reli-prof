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

namespace PhpProfiler\Inspector\Daemon\Searcher\Context;

use Mockery;
use PhpProfiler\Inspector\Daemon\Searcher\Controller\PhpSearcherControllerInterface;
use PhpProfiler\Inspector\Daemon\Searcher\Controller\PhpSearcherControllerProtocol;
use PhpProfiler\Inspector\Daemon\Searcher\Worker\PhpSearcherEntryPoint;
use PhpProfiler\Inspector\Daemon\Searcher\Worker\PhpSearcherWorkerProtocol;
use PhpProfiler\Lib\Amphp\ContextCreatorInterface;
use PhpProfiler\Lib\Amphp\ContextInterface;
use PHPUnit\Framework\TestCase;

class PhpSearcherContextCreatorTest extends TestCase
{
    public function testCreate()
    {
        $context = Mockery::mock(ContextInterface::class);
        $context_creator = Mockery::mock(ContextCreatorInterface::class);
        $context_creator->expects()
            ->create(
                PhpSearcherEntryPoint::class,
                PhpSearcherWorkerProtocol::class,
                PhpSearcherControllerProtocol::class
            )
            ->andReturns($context)
        ;

        $php_searcher_context_creator = new PhpSearcherContextCreator($context_creator);
        $this->assertInstanceOf(
            PhpSearcherControllerInterface::class,
            $php_searcher_context_creator->create()
        );
    }
}
