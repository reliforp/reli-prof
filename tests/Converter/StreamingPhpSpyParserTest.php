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

class StreamingPhpSpyParserTest extends BaseTestCase
{
    /**
     * @param string ...$chunks
     * @return list<ParsedCallTrace>
     */
    private function feedChunks(StreamingPhpSpyParser $parser, string ...$chunks): array
    {
        $traces = [];
        foreach ($chunks as $chunk) {
            foreach ($parser->feed($chunk) as $trace) {
                $traces[] = $trace;
            }
        }
        foreach ($parser->flush() as $trace) {
            $traces[] = $trace;
        }
        return $traces;
    }

    public function testSingleTraceInOneChunk(): void
    {
        $parser = new StreamingPhpSpyParser();
        $traces = $this->feedChunks(
            $parser,
            "0 func_a /a.php:10\n1 func_b /a.php:20\n\n",
        );
        $this->assertCount(1, $traces);
        $this->assertSame('func_a', $traces[0]->call_frames[0]->function_name);
        $this->assertSame('func_b', $traces[0]->call_frames[1]->function_name);
    }

    public function testTraceSplitAcrossChunks(): void
    {
        $parser = new StreamingPhpSpyParser();
        $traces = $this->feedChunks(
            $parser,
            "0 func_a /a.ph",
            "p:10\n1 fu",
            "nc_b /a.php:20\n\n",
        );
        $this->assertCount(1, $traces);
        $this->assertSame('func_a', $traces[0]->call_frames[0]->function_name);
        $this->assertSame(10, $traces[0]->call_frames[0]->lineno);
        $this->assertSame('func_b', $traces[0]->call_frames[1]->function_name);
        $this->assertSame(20, $traces[0]->call_frames[1]->lineno);
    }

    public function testMultipleTraces(): void
    {
        $parser = new StreamingPhpSpyParser();
        $traces = $this->feedChunks(
            $parser,
            "0 a /a.php:1\n\n0 b /b.php:2\n\n0 c /c.php:3\n\n",
        );
        $this->assertCount(3, $traces);
        $this->assertSame('a', $traces[0]->call_frames[0]->function_name);
        $this->assertSame('b', $traces[1]->call_frames[0]->function_name);
        $this->assertSame('c', $traces[2]->call_frames[0]->function_name);
    }

    public function testTrailingTraceWithoutBlankLineEmittedOnFlush(): void
    {
        $parser = new StreamingPhpSpyParser();
        $traces = [];
        foreach ($parser->feed("0 a /a.php:1\n") as $trace) {
            $traces[] = $trace;
        }
        $this->assertCount(0, $traces);
        foreach ($parser->flush() as $trace) {
            $traces[] = $trace;
        }
        $this->assertCount(1, $traces);
        $this->assertSame('a', $traces[0]->call_frames[0]->function_name);
    }

    public function testCommentLinesIgnored(): void
    {
        $parser = new StreamingPhpSpyParser();
        $traces = $this->feedChunks(
            $parser,
            "# pid = 123\n0 a /a.php:1\n# ts = 456\n\n",
        );
        $this->assertCount(1, $traces);
        $this->assertCount(1, $traces[0]->call_frames);
        $this->assertSame('a', $traces[0]->call_frames[0]->function_name);
    }

    public function testCarriageReturnStripped(): void
    {
        $parser = new StreamingPhpSpyParser();
        $traces = $this->feedChunks(
            $parser,
            "0 a /a.php:1\r\n\r\n",
        );
        $this->assertCount(1, $traces);
        $this->assertSame('a', $traces[0]->call_frames[0]->function_name);
        $this->assertSame('/a.php', $traces[0]->call_frames[0]->file_name);
    }

    public function testEmptyChunkNoOp(): void
    {
        $parser = new StreamingPhpSpyParser();
        $traces = $this->feedChunks($parser, '', '0 a /a.php:1', '', "\n", "\n");
        $this->assertCount(1, $traces);
        $this->assertSame('a', $traces[0]->call_frames[0]->function_name);
    }
}
