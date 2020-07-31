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

namespace PhpProfiler\Inspector\Daemon\Searcher\Context;

use Mockery;
use Amp\Parallel\Context;
use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContextCreator;
use PhpProfiler\Lib\Amphp\ContextCreatorInterface;
use PHPUnit\Framework\TestCase;

class PhpSearcherContextCreatorTest extends TestCase
{

    public function testCreate()
    {
        $context = Mockery::mock(Context\Context::class);
        $context_creator = Mockery::mock(ContextCreatorInterface::class);
        $context_creator->expects()
            ->create(PhpSearcherEntryPoint::class)
            ->andReturns($context);

        $php_searcher_context_creator = new PhpSearcherContextCreator($context_creator);
        $this->assertInstanceOf(
            PhpSearcherContextInterface::class,
            $php_searcher_context_creator->create()
        );
    }}
