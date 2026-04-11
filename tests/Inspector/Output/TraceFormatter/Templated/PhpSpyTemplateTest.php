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

namespace Reli\Inspector\Output\TraceFormatter\Templated;

use Mockery;
use Reli\BaseTestCase;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

/**
 * Exercises the real `resources/templates/phpspy.php` and
 * `phpspy_with_opcode.php` templates with and without annotations, so
 * that changes to the templates (frame line shape, annotation line
 * format, blank-line terminator) are caught here rather than in a
 * live profiling run.
 */
class PhpSpyTemplateTest extends BaseTestCase
{
    private const TEMPLATE_DIR = __DIR__ . '/../../../../../resources/templates';

    private function buildFormatter(string $template_name): TemplatedCallTraceFormatter
    {
        $resolver = Mockery::mock(TemplatePathResolverInterface::class);
        $resolver
            ->allows()
            ->resolve($template_name)
            ->andReturns(self::TEMPLATE_DIR . "/{$template_name}.php");
        return new TemplatedCallTraceFormatter($resolver, $template_name);
    }

    private function buildTrace(): CallTrace
    {
        return new CallTrace(
            new CallFrame('App\\Service', 'run', '/app/src/Service.php', null),
            new CallFrame('', '<main>', '/app/public/index.php', null),
        );
    }

    public function testPhpSpyWithoutAnnotationsIsUnchanged(): void
    {
        $formatter = $this->buildFormatter('phpspy');
        $output = $formatter->format($this->buildTrace());

        $expected = "0 App\\Service::run /app/src/Service.php:-1\n"
            . "1 <main> /app/public/index.php:-1\n"
            . "\n";
        $this->assertSame($expected, $output);
    }

    public function testPhpSpyWithNullAnnotationsIsUnchanged(): void
    {
        $formatter = $this->buildFormatter('phpspy');
        $output = $formatter->format($this->buildTrace(), null);

        $expected = "0 App\\Service::run /app/src/Service.php:-1\n"
            . "1 <main> /app/public/index.php:-1\n"
            . "\n";
        $this->assertSame($expected, $output);
    }

    public function testPhpSpyWithEmptyAnnotationsIsUnchanged(): void
    {
        $formatter = $this->buildFormatter('phpspy');
        $output = $formatter->format($this->buildTrace(), []);

        $expected = "0 App\\Service::run /app/src/Service.php:-1\n"
            . "1 <main> /app/public/index.php:-1\n"
            . "\n";
        $this->assertSame($expected, $output);
    }

    public function testPhpSpyEmitsAnnotationsAfterFrames(): void
    {
        $formatter = $this->buildFormatter('phpspy');
        $annotations = [
            'global::$counter' => '(int) 42',
            'local::App\\Service::run()$id' => '(string) "abc"',
        ];
        $output = $formatter->format($this->buildTrace(), $annotations);

        // Annotations appear as `# key = value` comment lines after all
        // frames, before the blank terminator.
        $expected = "0 App\\Service::run /app/src/Service.php:-1\n"
            . "1 <main> /app/public/index.php:-1\n"
            . "# global::\$counter = (int) 42\n"
            . "# local::App\\Service::run()\$id = (string) \"abc\"\n"
            . "\n";
        $this->assertSame($expected, $output);
    }

    public function testPhpSpyAnnotationLinesAreParsedAsComments(): void
    {
        // Round-trip: the annotated output must still be parseable by
        // reli-prof's own phpspy parser (which treats `#` lines as
        // comments). This guards the CommentParser:47 contract.
        $formatter = $this->buildFormatter('phpspy');
        $annotations = ['global::$x' => '(int) 7'];
        $text = $formatter->format($this->buildTrace(), $annotations);

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $text);
        rewind($stream);

        $parser = new \Reli\Converter\PhpSpyCompatibleParser();
        $traces = iterator_to_array($parser->parseFile($stream));

        $this->assertCount(1, $traces);
        $parsed = $traces[0];
        $this->assertCount(2, $parsed->call_frames);
        $this->assertSame('App\\Service::run', $parsed->call_frames[0]->function_name);
        $this->assertSame('<main>', $parsed->call_frames[1]->function_name);
    }

    public function testPhpSpyWithOpcodeWithoutAnnotationsIsUnchanged(): void
    {
        $formatter = $this->buildFormatter('phpspy_with_opcode');
        $output = $formatter->format($this->buildTrace());

        // No opcodes on these frames, so output matches plain phpspy
        // (depth_offset stays 0 because the innermost frame has no opcode).
        $expected = "0 App\\Service::run /app/src/Service.php:-1\n"
            . "1 <main> /app/public/index.php:-1\n"
            . "\n";
        $this->assertSame($expected, $output);
    }

    public function testPhpSpyWithOpcodeEmitsAnnotationsAfterFrames(): void
    {
        $formatter = $this->buildFormatter('phpspy_with_opcode');
        $output = $formatter->format($this->buildTrace(), ['global::$a' => '(int) 1']);

        $expected = "0 App\\Service::run /app/src/Service.php:-1\n"
            . "1 <main> /app/public/index.php:-1\n"
            . "# global::\$a = (int) 1\n"
            . "\n";
        $this->assertSame($expected, $output);
    }

    public function testPhpSpyAnnotationOrderIsPreserved(): void
    {
        // Operators expect deterministic ordering (same as --trace-var
        // declaration order). Associative-array iteration in PHP is
        // insertion-ordered, so this works for free — but the guarantee
        // is important enough to pin down as a test.
        $formatter = $this->buildFormatter('phpspy');
        $annotations = [
            'global::$c' => '(int) 1',
            'global::$a' => '(int) 2',
            'global::$b' => '(int) 3',
        ];
        $output = $formatter->format($this->buildTrace(), $annotations);
        $c_pos = strpos($output, '$c');
        $a_pos = strpos($output, '$a');
        $b_pos = strpos($output, '$b');
        $this->assertNotFalse($c_pos);
        $this->assertNotFalse($a_pos);
        $this->assertNotFalse($b_pos);
        $this->assertLessThan($a_pos, $c_pos);
        $this->assertLessThan($b_pos, $a_pos);
    }
}
