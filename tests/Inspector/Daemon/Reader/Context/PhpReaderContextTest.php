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

use Amp\Parallel\Context\Context;
use Amp\Promise;
use Mockery;
use PHPUnit\Framework\TestCase;

final class PhpReaderContextTest extends TestCase
{
    public function testStart(): void
    {
        $context = Mockery::mock(Context::class);
        $context->expects()->start()->andReturn(Mockery::mock(Promise::class));
        $php_reader_context = new PhpReaderContext($context);
        $this->assertInstanceOf(Promise::class, $php_reader_context->start());
    }

    public function testIsRunning(): void
    {
        $context = Mockery::mock(Context::class);
        $context->expects()->isRunning()->andReturn(true);
        $context->expects()->isRunning()->andReturn(false);
        $php_reader_context = new PhpReaderContext($context);
        $this->assertSame(true, $php_reader_context->isRunning());
        $this->assertSame(false, $php_reader_context->isRunning());
    }

    public function testSendSettings(): void
    {
        $settings = [];
        $context = Mockery::mock(Context::class);
        $context->expects()->send($settings)->andReturn(Mockery::mock(Promise::class));
        $php_reader_context = new PhpReaderContext($context);
        $this->assertInstanceOf(Promise::class, $php_reader_context->sendSettings($settings));
    }

    public function testReceiveTrace(): void
    {
        $context = Mockery::mock(Context::class);
        $context->expects()->receive()->andReturn(Mockery::mock(Promise::class));
        $php_reader_context = new PhpReaderContext($context);
        $this->assertInstanceOf(Promise::class, $php_reader_context->receiveTrace());
    }
}
