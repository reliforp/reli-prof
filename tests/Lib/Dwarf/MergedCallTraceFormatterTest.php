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

    public function testFormatWithAnnotationsEmitsCommentLinesAfterFrames(): void
    {
        $trace = new MergedCallTrace(
            MergedCallFrame::fromNative(new NativeCallFrame('execute_ex', 'php8.4', 0, 0x2000)),
            MergedCallFrame::fromPhp(new CallFrame('App\\Service', 'run', '/app/svc.php', null)),
        );

        $output = $this->formatter->format(
            $trace,
            [
                'global::$counter' => '(int) 42',
                'local::App\\Service::run()$id' => '(string) "abc"',
            ],
        );

        $expected = "0 php8.4::execute_ex [native]:0\n"
            . "1 App\\Service::run /app/svc.php:-1\n"
            . "# global::\$counter = (int) 42\n"
            . "# local::App\\Service::run()\$id = (string) \"abc\"\n"
            . "\n";
        $this->assertSame($expected, $output);
    }

    public function testFormatWithEmptyAnnotationsIsIdenticalToNullAnnotations(): void
    {
        $trace = new MergedCallTrace(
            MergedCallFrame::fromPhp(new CallFrame('', 'main', '/index.php', null)),
        );
        $this->assertSame(
            $this->formatter->format($trace),
            $this->formatter->format($trace, []),
        );
        $this->assertSame(
            $this->formatter->format($trace),
            $this->formatter->format($trace, null),
        );
    }

    public function testFormatAnnotationLinesRoundTripThroughPhpSpyParser(): void
    {
        // reli's own parser must treat the annotation lines as comments
        // so merged-output streams remain re-parseable after annotations
        // are added.
        $trace = new MergedCallTrace(
            MergedCallFrame::fromNative(new NativeCallFrame('execute_ex', 'php', 0, 0x1000)),
            MergedCallFrame::fromPhp(new CallFrame('App', 'run', '/app.php', null)),
        );
        $text = $this->formatter->format($trace, ['global::$x' => '(int) 7']);

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $text);
        rewind($stream);

        $parser = new \Reli\Converter\PhpSpyCompatibleParser();
        $parsed = iterator_to_array($parser->parseFile($stream));
        fclose($stream);

        $this->assertCount(1, $parsed);
        $this->assertCount(2, $parsed[0]->call_frames);
        // Frame 0 is the native frame; parser treats its display name as
        // the function token.
        $this->assertStringContainsString('execute_ex', $parsed[0]->call_frames[0]->function_name);
        $this->assertSame('App::run', $parsed[0]->call_frames[1]->function_name);
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
