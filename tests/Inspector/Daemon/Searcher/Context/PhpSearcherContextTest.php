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

use Amp\Parallel\Context\Context;
use Amp\Promise;
use Mockery;
use PHPUnit\Framework\TestCase;

class PhpSearcherContextTest extends TestCase
{
    public function testStart(): void
    {
        $context = Mockery::mock(Context::class);
        $context->expects()->start()->andReturn(Mockery::mock(Promise::class));
        $php_searcher_context = new PhpSearcherContext($context);
        $this->assertInstanceOf(Promise::class, $php_searcher_context->start());
    }

    public function testSendTargetRegex(): void
    {

        $context = Mockery::mock(Context::class);
        $context->shouldReceive('send')
            ->once()
            ->with('abcdefg')
            ->andReturn(Mockery::mock(Promise::class));
        $php_searcher_context = new PhpSearcherContext($context);
        $this->assertInstanceOf(
            Promise::class,
            $php_searcher_context->sendTargetRegex('abcdefg')
        );
    }

    public function testReceivePidList(): void
    {
        $context = Mockery::mock(Context::class);
        $context->expects()->receive()->andReturn(Mockery::mock(Promise::class));
        $php_searcher_context = new PhpSearcherContext($context);
        $this->assertInstanceOf(Promise::class, $php_searcher_context->receivePidList());
    }
}
