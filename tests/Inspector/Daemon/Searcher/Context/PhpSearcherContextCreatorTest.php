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

namespace Reli\Inspector\Daemon\Searcher\Context;

use Mockery;
use Reli\Inspector\Daemon\Searcher\Controller\PhpSearcherControllerInterface;
use Reli\Inspector\Daemon\Searcher\Controller\PhpSearcherControllerProtocol;
use Reli\Inspector\Daemon\Searcher\Worker\PhpSearcherEntryPoint;
use Reli\Inspector\Daemon\Searcher\Worker\PhpSearcherWorkerProtocol;
use Reli\Lib\Amphp\ContextCreatorInterface;
use Reli\Lib\Amphp\ContextInterface;
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
