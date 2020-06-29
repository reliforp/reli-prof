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
use PhpProfiler\Inspector\Daemon\Reader\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings;
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
        $target_php_settings = new TargetPhpSettings();
        $trace_loop_settings = new TraceLoopSettings(1, 'q', 1);
        $get_trace_settings = new GetTraceSettings(1);

        $expected = new SetSettingsMessage(
            $target_php_settings,
            $trace_loop_settings,
            $get_trace_settings
        );

        $context = Mockery::mock(Context::class);
        $context->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(function (SetSettingsMessage $actual) use ($expected) {
                    $this->assertEquals($actual, $expected);
                    return true;
                })
            )
            ->andReturn(Mockery::mock(Promise::class));
        $php_reader_context = new PhpReaderContext($context);
        $this->assertInstanceOf(
            Promise::class,
            $php_reader_context->sendSettings(
                $target_php_settings,
                $trace_loop_settings,
                $get_trace_settings
            )
        );
    }

    public function testReceiveTrace(): void
    {
        $context = Mockery::mock(Context::class);
        $context->expects()->receive()->andReturn(Mockery::mock(Promise::class));
        $php_reader_context = new PhpReaderContext($context);
        $this->assertInstanceOf(Promise::class, $php_reader_context->receiveTrace());
    }
}
