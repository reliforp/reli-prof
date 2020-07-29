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

namespace PhpProfiler\Inspector\Daemon\Reader\Context;

use Amp\Parallel\Context;
use Mockery;
use PhpProfiler\Lib\Amphp\ContextCreatorInterface;
use PHPUnit\Framework\TestCase;

class PhpReaderContextCreatorTest extends TestCase
{
    public function testCreate()
    {
        $context = Mockery::mock(Context\Context::class);
        $context_creator = Mockery::mock(ContextCreatorInterface::class);
        $context_creator->expects()
            ->create(PhpReaderEntryPoint::class)
            ->andReturns($context);

        $php_reader_context_creator = new PhpReaderContextCreator($context_creator);
        $this->assertInstanceOf(
            PhpReaderContextInterface::class,
            $php_reader_context_creator->create()
        );
    }
}
