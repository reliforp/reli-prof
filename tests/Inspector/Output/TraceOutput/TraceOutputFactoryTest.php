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

namespace Reli\Inspector\Output\TraceOutput;

use Reli\BaseTestCase;
use Reli\Inspector\Output\TraceFormatter\CallTraceFormatter;
use Reli\Inspector\Output\TraceFormatter\Templated\TraceFormatterFactory;
use Reli\Inspector\Settings\OutputSettings\OutputSettings;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Symfony\Component\Console\Output\StreamOutput;

class TraceOutputFactoryTest extends BaseTestCase
{
    public function testFromSettingsWritesToFile()
    {
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
                $output_settings = new OutputSettings(
                    'phpspy',
                    $tmp_path,
                )
            )
            ->andReturns(
                $call_trace_formatter = \Mockery::mock(CallTraceFormatter::class)
            )
        ;
        $call_trace_formatter->expects()
            ->format($test_trace, null)
            ->andReturns('formatted')
        ;
        $trace_output_factory = new TraceOutputFactory($trace_formatter_factory);
        $trace_output = $trace_output_factory->fromSettingsAndConsoleOutput(
            new StreamOutput(fopen('php://memory', 'w')),
            $output_settings,
        );
        $trace_output->output($test_trace);

        $this->assertSame('formatted', file_get_contents($tmp_path));
    }

    public function testFromSettingsWritesToDefaultStreamWhenNoOutputPath()
    {
        $buffer = fopen('php://memory', 'r+');

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
                $output_settings = new OutputSettings(
                    'phpspy',
                    null,
                )
            )
            ->andReturns(
                $call_trace_formatter = \Mockery::mock(CallTraceFormatter::class)
            )
        ;
        $call_trace_formatter->expects()
            ->format($test_trace, null)
            ->andReturns('formatted')
        ;

        $trace_output_factory = new TraceOutputFactory($trace_formatter_factory, $buffer);
        $trace_output = $trace_output_factory->fromSettingsAndConsoleOutput(
            new StreamOutput(fopen('php://memory', 'w')),
            $output_settings,
        );
        $trace_output->output($test_trace);

        fseek($buffer, 0);
        $this->assertSame('formatted', fread($buffer, 4096));
    }

    public function testFromSettingsCreatesRbtOutput()
    {
        $buffer = fopen('php://memory', 'r+');
        assert($buffer !== false);

        $trace_formatter_factory = \Mockery::mock(TraceFormatterFactory::class);
        $trace_output_factory = new TraceOutputFactory($trace_formatter_factory, $buffer);

        $output_settings = new OutputSettings('rbt');
        $loop_settings = new \Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings(
            10_000_000,
            'q',
            10,
            false,
        );

        $trace_output = $trace_output_factory->fromSettingsAndConsoleOutput(
            new StreamOutput(fopen('php://memory', 'w')),
            $output_settings,
            $loop_settings,
        );

        $this->assertInstanceOf(BinaryTraceOutput::class, $trace_output);
    }

    public function testFromSettingsCreatesCompressedRbtOutput()
    {
        $buffer = fopen('php://memory', 'r+');
        assert($buffer !== false);

        $trace_formatter_factory = \Mockery::mock(TraceFormatterFactory::class);
        $trace_output_factory = new TraceOutputFactory($trace_formatter_factory, $buffer);

        $output_settings = new OutputSettings('rbt', rbt_compress: true);
        $loop_settings = new \Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings(
            10_000_000,
            'q',
            10,
            false,
        );

        $trace_output = $trace_output_factory->fromSettingsAndConsoleOutput(
            new StreamOutput(fopen('php://memory', 'w')),
            $output_settings,
            $loop_settings,
        );

        $this->assertInstanceOf(BinaryTraceOutput::class, $trace_output);

        // Write a trace and finish — should produce gzip in the buffer
        $test_trace = new CallTrace(
            new CallFrame('', 'func', '/file.php', null)
        );
        $trace_output->output($test_trace);
        $trace_output->finish();

        fseek($buffer, 0);
        $magic = fread($buffer, 2);
        $this->assertSame("\x1f\x8b", $magic, 'Output should be gzip');
    }
}
