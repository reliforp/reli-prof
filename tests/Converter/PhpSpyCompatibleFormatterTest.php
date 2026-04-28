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

namespace Reli\Converter;

use Reli\BaseTestCase;

final class PhpSpyCompatibleFormatterTest extends BaseTestCase
{
    public function testFormatTraceMatchesPhpSpyTextLayout(): void
    {
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('sleep', '<internal>', -1),
            new ParsedCallFrame('aaa', '/tmp/sample.php', 5),
            new ParsedCallFrame('Ns\\Cls::run', '/tmp/sample.php', 22),
        );

        $expected = "0 sleep <internal>:-1\n"
            . "1 aaa /tmp/sample.php:5\n"
            . "2 Ns\\Cls::run /tmp/sample.php:22\n"
            . "\n";

        $this->assertSame($expected, (new PhpSpyCompatibleFormatter())->formatTrace($trace));
    }

    public function testFormatRoundTripsThroughPhpSpyParser(): void
    {
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('sleep', '<internal>', -1),
            new ParsedCallFrame('aaa', '/tmp/sample.php', 5),
            new ParsedCallFrame('main', '/tmp/sample.php', 25),
        );

        $text = (new PhpSpyCompatibleFormatter())->formatTrace($trace);

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $text);
        rewind($stream);

        $parsed = iterator_to_array((new PhpSpyCompatibleParser())->parseFile($stream));
        fclose($stream);

        $this->assertCount(1, $parsed);
        $this->assertCount(3, $parsed[0]->call_frames);
        $this->assertSame('sleep', $parsed[0]->call_frames[0]->function_name);
        $this->assertSame('<internal>', $parsed[0]->call_frames[0]->file_name);
        $this->assertSame(-1, $parsed[0]->call_frames[0]->lineno);
        $this->assertSame(5, $parsed[0]->call_frames[1]->lineno);
        $this->assertSame(25, $parsed[0]->call_frames[2]->lineno);
    }

    public function testWriteTracesToWritesEachTraceWithBlankSeparator(): void
    {
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('a', '/f.php', 1),
                new ParsedCallFrame('main', '/f.php', 10),
            ),
            new ParsedCallTrace(
                new ParsedCallFrame('b', '/f.php', 2),
                new ParsedCallFrame('main', '/f.php', 11),
            ),
        ];

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        (new PhpSpyCompatibleFormatter())->writeTracesTo($stream, $traces);
        rewind($stream);
        $text = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame(
            "0 a /f.php:1\n1 main /f.php:10\n\n0 b /f.php:2\n1 main /f.php:11\n\n",
            $text,
        );
    }
}
