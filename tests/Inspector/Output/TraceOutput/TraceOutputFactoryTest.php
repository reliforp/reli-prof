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

namespace PhpProfiler\Inspector\Output\TraceOutput;

use PhpProfiler\Inspector\Output\TraceFormatter\CallTraceFormatter;
use PhpProfiler\Inspector\Output\TraceFormatter\Templated\TraceFormatterFactory;
use PhpProfiler\Inspector\Settings\OutputSettings\OutputSettings;
use PhpProfiler\Lib\PhpProcessReader\CallFrame;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\StreamOutput;

class TraceOutputFactoryTest extends TestCase
{
    public function testFromSettingsAndConsoleOutput()
    {
        $buffer1 = fopen('php://memory', 'w');
        $tmp_path = tempnam(sys_get_temp_dir(), 'tmp_test_php_profiler');

        $test_trace = new CallTrace(
            new CallFrame(
                'test_class',
                'test_func',
                'test_file',
                null
            )
        );

        $trace_formatter_factory = \Mockery::mock(TraceFormatterFactory::class);
        $trace_formatter_factory->expects()
            ->createFromSettings(
                $output_settings1 = new OutputSettings(
                    'phpspy',
                    null,
                )
            )
            ->andReturns(
                $call_trace_formatter = \Mockery::mock(CallTraceFormatter::class)
            )
        ;
        $trace_formatter_factory->expects()
            ->createFromSettings(
                $output_settings2 = new OutputSettings(
                    'phpspy',
                    $tmp_path,
                )
            )
            ->andReturns($call_trace_formatter)
        ;
        $call_trace_formatter->expects()
            ->format($test_trace)
            ->andReturns('formatted')
        ;
        $trace_output_factory = new TraceOutputFactory($trace_formatter_factory);
        $trace_output1 = $trace_output_factory->fromSettingsAndConsoleOutput(
            new StreamOutput($buffer1),
            $output_settings1,
        );
        $trace_output1->output($test_trace);

        fseek($buffer1, 0);
        $this->assertSame('formatted', fread($buffer1, 4096));
        ftruncate($buffer1, 0);

        $trace_output2 = $trace_output_factory->fromSettingsAndConsoleOutput(
            new StreamOutput($buffer1),
            $output_settings2,
        );
        $trace_output2->output($test_trace);

        fseek($buffer1, 0);
        $this->assertSame('', fread($buffer1, 4096));
        $this->assertSame('formatted', file_get_contents($tmp_path));
    }
}
