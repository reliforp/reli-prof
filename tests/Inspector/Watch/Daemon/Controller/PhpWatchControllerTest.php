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

namespace Reli\Inspector\Watch\Daemon\Controller;

use Mockery;
use Reli\BaseTestCase;
use Reli\Inspector\Daemon\AutoContextRecoveringInterface;
use Reli\Inspector\Watch\Daemon\Searcher\WatchTargetDescriptor;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchAttachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchDetachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchSettingsMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerMessage;
use Reli\Inspector\Watch\Daemon\Protocol\PhpWatchControllerProtocolInterface;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Lib\Amphp\ContextInterface;
use Reli\Lib\PhpInternals\ZendTypeReader;

final class PhpWatchControllerTest extends BaseTestCase
{
    public function testStart(): void
    {
        $auto = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto->expects()
            ->getContext()
            ->andReturn($ctx = Mockery::mock(ContextInterface::class))
            ->zeroOrMoreTimes()
        ;
        $auto->expects()->onRecover(Mockery::type('\Closure'));
        $ctx->expects()->start();

        $controller = new PhpWatchController($auto);
        $controller->start();
    }

    public function testIsRunning(): void
    {
        $auto = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto->expects()
            ->getContext()
            ->andReturn($ctx = Mockery::mock(ContextInterface::class))
            ->zeroOrMoreTimes()
        ;
        $auto->expects()->onRecover(Mockery::type('\Closure'));
        $ctx->expects()->isRunning()->andReturn(true);
        $ctx->expects()->isRunning()->andReturn(false);

        $controller = new PhpWatchController($auto);
        $this->assertTrue($controller->isRunning());
        $this->assertFalse($controller->isRunning());
    }

    public function testSendSettings(): void
    {
        $protocol = Mockery::mock(PhpWatchControllerProtocolInterface::class);
        $protocol->expects()
            ->sendSettings(Mockery::type(WatchSettingsMessage::class))
            ->once()
        ;

        $auto = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto->expects()->onRecover(Mockery::type('\Closure'));
        $auto->expects()
            ->withAutoRecover(Mockery::type('\Closure'), Mockery::type('string'))
            ->andReturnUsing(
                function (\Closure $callback) use ($protocol) {
                    return $callback($protocol);
                }
            )
        ;

        $controller = new PhpWatchController($auto);
        $controller->sendSettings(
            $this->makeWatchSettings(),
            new TraceLoopSettings(1000, 'q', 10, false),
            new GetTraceSettings(PHP_INT_MAX),
        );
    }

    public function testSendAttach(): void
    {
        $protocol = Mockery::mock(PhpWatchControllerProtocolInterface::class);
        $protocol->expects()
            ->sendAttach(Mockery::type(WatchAttachMessage::class))
            ->once()
        ;

        $auto = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto->expects()->onRecover(Mockery::type('\Closure'));
        $auto->expects()
            ->withAutoRecover(Mockery::type('\Closure'), Mockery::type('string'))
            ->andReturnUsing(
                function (\Closure $callback) use ($protocol) {
                    return $callback($protocol);
                }
            )
        ;

        $controller = new PhpWatchController($auto);
        $controller->sendAttach(
            new WatchTargetDescriptor(123, 0, 0, 0, ZendTypeReader::V84),
        );
    }

    public function testReceiveTrigger(): void
    {
        $triggerMsg = new WatchTriggerMessage(
            pid: 123,
            event: new TriggerEvent('memory-limit', 'test', 100.0),
            heap_stats: new HeapStats(1024, 2048, 1024, 0),
            call_trace: null,
        );

        $protocol = Mockery::mock(PhpWatchControllerProtocolInterface::class);
        $protocol->expects()
            ->receiveTriggerOrDetach()
            ->andReturn($triggerMsg)
        ;

        $auto = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto->expects()->onRecover(Mockery::type('\Closure'));
        $auto->expects()
            ->withAutoRecover(Mockery::type('\Closure'), Mockery::type('string'))
            ->andReturnUsing(
                function (\Closure $callback) use ($protocol) {
                    return $callback($protocol);
                }
            )
        ;

        $controller = new PhpWatchController($auto);
        $result = $controller->receiveTriggerOrDetach();
        $this->assertInstanceOf(WatchTriggerMessage::class, $result);
        $this->assertSame(123, $result->pid);
    }

    public function testReceiveDetachClearsAttach(): void
    {
        $detachMsg = new WatchDetachMessage(pid: 456);

        $protocol = Mockery::mock(PhpWatchControllerProtocolInterface::class);
        $protocol->expects()
            ->receiveTriggerOrDetach()
            ->andReturn($detachMsg)
        ;

        $auto = Mockery::mock(AutoContextRecoveringInterface::class);
        $auto->expects()->onRecover(Mockery::type('\Closure'));
        $auto->expects()
            ->withAutoRecover(Mockery::type('\Closure'), Mockery::type('string'))
            ->andReturnUsing(
                function (\Closure $callback) use ($protocol) {
                    return $callback($protocol);
                }
            )
        ;

        $controller = new PhpWatchController($auto);
        $result = $controller->receiveTriggerOrDetach();
        $this->assertInstanceOf(WatchDetachMessage::class, $result);
        $this->assertSame(456, $result->pid);
    }

    private function makeWatchSettings(): WatchSettings
    {
        return new WatchSettings(
            poll_interval_ms: 1000,
            cooldown_seconds: 60,
            max_triggers: 0,
            max_triggers_per_hour: 10,
            max_dump_size_bytes: 1024 * 1024 * 1024,
            backoff_multiplier: 2.0,
            backoff_max_seconds: 3600,
            action_output_dir: '.',
            status_interval_seconds: 60,
            quiet: false,
            memory_limit_bytes: null,
            memory_growth_rate: null,
            memory_peak_watch: false,
            watch_function: null,
            trace_depth_limit: null,
            watch_var: [],
            actions: ['log'],
            action_exec_command: null,
            log_file: null,
            memory_output_format: null,
        );
    }
}
