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

namespace Reli\Inspector\Daemon\Reader\Worker;

use Hamcrest\Matchers;
use Mockery;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderWorkerProtocolInterface;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Lib\Loop\LoopCondition\OnlyOnceCondition;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTrace;
use PHPUnit\Framework\TestCase;

class PhpReaderEntryPointTest extends TestCase
{
    public function testRun(): void
    {
        $settings = new SetSettingsMessage(
            new TraceLoopSettings(1, 'q', 10, false),
            new GetTraceSettings(PHP_INT_MAX)
        );
        $attach = new AttachMessage(
            new TargetProcessDescriptor(
                123,
                0,
                0,
                ZendTypeReader::V80
            )
        );
        $php_reader_task = Mockery::mock(PhpReaderTraceLoopInterface::class);
        $protocol = Mockery::mock(PhpReaderWorkerProtocolInterface::class);
        $protocol->expects()->receiveSettings()->andReturns($settings)->once();
        $protocol->expects()->receiveAttach()->andReturns($attach)->once();
        $php_reader_task->shouldReceive('run')
            ->withArgs(
                function (
                    $trace_loop_serrings,
                    $target_process_descriptor,
                    $get_trace_settings
                ) {
                    $this->assertEquals(
                        [
                            new TraceLoopSettings(1, 'q', 10, false),
                            new TargetProcessDescriptor(123, 0, 0, ZendTypeReader::V80),
                            new GetTraceSettings(PHP_INT_MAX),
                        ],
                        [
                            $trace_loop_serrings,
                            $target_process_descriptor,
                            $get_trace_settings,
                        ]
                    );
                    return true;
                }
            )
            ->andReturns(
                (function () {
                    yield $this->getTestTrace('abc');
                    yield $this->getTestTrace('def');
                    yield $this->getTestTrace('ghi');
                })()
            )
        ;
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo($this->getTestTrace('abc')))
        ;
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo($this->getTestTrace('def')))
        ;
        $protocol->expects()
            ->sendTrace()
            ->with(Matchers::equalTo($this->getTestTrace('ghi')))
        ;
        $protocol->expects()
            ->sendDetachWorker()
            ->with(Matchers::equalTo(new DetachWorkerMessage(123)))
            ->once();

        $php_reader_entry_point = new PhpReaderEntryPoint(
            $php_reader_task,
            $protocol,
            new OnlyOnceCondition(),
        );

        $php_reader_entry_point->run();
    }

    private function getTestTrace(string $function): TraceMessage
    {
        return new TraceMessage(
            new CallTrace(
                new CallFrame('class', $function, 'file', null)
            )
        );
    }
}
