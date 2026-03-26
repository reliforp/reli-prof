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

namespace Reli\Lib\Dwarf;

use Reli\BaseTestCase;
use Reli\Inspector\Output\OutputChannel\StreamOutputChannel;
use Reli\Inspector\Output\TraceFormatter\MergedCallTraceFormatter;
use Reli\Inspector\Output\TraceOutput\FormattedMergedTraceOutput;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\NativeCallFrame;

class FormattedMergedTraceOutputTest extends BaseTestCase
{
    public function testOutputWritesToStream(): void
    {
        $stream = fopen('php://memory', 'rw');
        assert($stream !== false);

        $output = new FormattedMergedTraceOutput(
            new StreamOutputChannel($stream),
            new MergedCallTraceFormatter(),
        );

        $trace = new MergedCallTrace(
            MergedCallFrame::fromNative(
                new NativeCallFrame('execute_ex', 'php', 0, 0x1000)
            ),
            MergedCallFrame::fromPhp(
                new CallFrame('', 'main', '/app/test.php', null)
            ),
        );

        $output->outputMerged($trace);

        rewind($stream);
        $written = stream_get_contents($stream);
        fclose($stream);

        $this->assertStringContainsString('execute_ex', $written);
        $this->assertStringContainsString('[native]:0', $written);
        $this->assertStringContainsString('main', $written);
        $this->assertStringContainsString('/app/test.php:', $written);
    }

    public function testOutputNativeOnlyTrace(): void
    {
        $stream = fopen('php://memory', 'rw');
        assert($stream !== false);

        $output = new FormattedMergedTraceOutput(
            new StreamOutputChannel($stream),
            new MergedCallTraceFormatter(),
        );

        $trace = new MergedCallTrace(
            MergedCallFrame::fromNative(
                new NativeCallFrame('clock_nanosleep', 'libc.so.6', 0x5a, 0x2000)
            ),
        );

        $output->outputMerged($trace);

        rewind($stream);
        $written = stream_get_contents($stream);
        fclose($stream);

        $this->assertStringContainsString('clock_nanosleep+0x5a', $written);
        $this->assertStringContainsString('libc.so.6', $written);
    }
}
