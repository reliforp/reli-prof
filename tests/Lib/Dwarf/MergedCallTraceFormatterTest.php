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
use Reli\Inspector\Output\TraceFormatter\MergedCallTraceFormatter;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\NativeCallFrame;

class MergedCallTraceFormatterTest extends BaseTestCase
{
    private MergedCallTraceFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MergedCallTraceFormatter();
    }

    public function testFormatNativeOnly(): void
    {
        $trace = new MergedCallTrace(
            MergedCallFrame::fromNative(new NativeCallFrame('execute_ex', 'php8.4', 0x141, 0x5000)),
            MergedCallFrame::fromNative(new NativeCallFrame('main', 'php8.4', 0, 0x1000)),
        );

        $output = $this->formatter->format($trace);
        $lines = explode("\n", trim($output));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('execute_ex+0x141', $lines[0]);
        $this->assertStringContainsString('[native]:0', $lines[0]);
        $this->assertStringContainsString('php8.4::main', $lines[1]);
    }

    public function testFormatPhpOnly(): void
    {
        $trace = new MergedCallTrace(
            MergedCallFrame::fromPhp(new CallFrame('MyClass', 'doWork', '/app/src/MyClass.php', null)),
        );

        $output = $this->formatter->format($trace);
        $this->assertStringContainsString('MyClass::doWork', $output);
        $this->assertStringContainsString('/app/src/MyClass.php:', $output);
        $this->assertStringNotContainsString('[native]', $output);
    }

    public function testFormatMixed(): void
    {
        $trace = new MergedCallTrace(
            MergedCallFrame::fromNative(new NativeCallFrame('execute_ex', 'php8.4', 0, 0x2000)),
            MergedCallFrame::fromPhp(new CallFrame('', 'myFunc', '/app/test.php', null)),
        );

        $output = $this->formatter->format($trace);
        $lines = explode("\n", trim($output));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('[native]:0', $lines[0]);
        $this->assertStringContainsString('myFunc', $lines[1]);
        $this->assertStringContainsString('/app/test.php:', $lines[1]);
    }

    public function testPhpSpyCompatibility(): void
    {
        // Verify output can be parsed by PhpSpyCompatibleParser
        $trace = new MergedCallTrace(
            MergedCallFrame::fromNative(new NativeCallFrame('execute_ex', 'php', 0, 0x1000)),
            MergedCallFrame::fromPhp(new CallFrame('', 'main', '/app/test.php', null)),
        );

        $output = $this->formatter->format($trace);
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            // Each line should match phpspy format: "<depth> <name> <file>:<line>"
            $parts = explode(' ', $line, 3);
            $this->assertCount(3, $parts, "Line should have 3 parts: '$line'");
            $this->assertMatchesRegularExpression('/^\d+$/', $parts[0], 'First part should be depth');
            $this->assertStringContainsString(':', $parts[2], "Third part should contain ':' for file:line");
        }
    }
}
