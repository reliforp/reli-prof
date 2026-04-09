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

namespace Reli\Converter\BinaryTrace;

use Reli\BaseTestCase;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;

final class MultiSegmentTest extends BaseTestCase
{
    public function testTwoSegmentsWithSegmentEnd(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace1 = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/a.php', 1),
        );
        $trace2 = new ParsedCallTrace(
            new ParsedCallFrame('func_b', '/b.php', 2),
        );

        // Write segment 1
        $writer1 = new BinaryTraceWriter($stream, 10000);
        $writer1->writeHeader();
        $writer1->writeTrace($trace1);
        $writer1->writeCheckpoint();
        $writer1->writeSegmentEnd();

        // Write segment 2
        $writer2 = new BinaryTraceWriter($stream, 10000);
        $writer2->writeHeader();
        $writer2->writeTrace($trace2);
        $writer2->writeCheckpoint();
        $writer2->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(2, $results);
        $this->assertSame('func_a', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('func_b', $results[1]->trace->call_frames[0]->function_name);
    }

    public function testTwoSegmentsWithoutSegmentEnd(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace1 = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/a.php', 1),
        );
        $trace2 = new ParsedCallTrace(
            new ParsedCallFrame('func_b', '/b.php', 2),
        );

        // Segment 1 - no SEGMENT_END
        $writer1 = new BinaryTraceWriter($stream, 10000);
        $writer1->writeHeader();
        $writer1->writeTrace($trace1);

        // Segment 2 starts immediately (magic detected where event_type expected)
        $writer2 = new BinaryTraceWriter($stream, 10000);
        $writer2->writeHeader();
        $writer2->writeTrace($trace2);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(2, $results);
        $this->assertSame('func_a', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('func_b', $results[1]->trace->call_frames[0]->function_name);
    }

    public function testSegmentStateResetsBetweenSegments(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // Both segments define the same frame with frame_id=0
        $writer1 = new BinaryTraceWriter($stream, 10000);
        $writer1->writeHeader();
        $writer1->writeTrace($trace);
        $writer1->writeSegmentEnd();

        $writer2 = new BinaryTraceWriter($stream, 10000);
        $writer2->writeHeader();
        $writer2->writeTrace($trace);
        $writer2->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        // Should read both despite same frame_id=0 in both segments
        $this->assertCount(2, $results);
        $this->assertSame('func', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('func', $results[1]->trace->call_frames[0]->function_name);
    }

    public function testThreeSegmentsConcatenated(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        for ($i = 0; $i < 3; $i++) {
            $trace = new ParsedCallTrace(
                new ParsedCallFrame("func_{$i}", "/file_{$i}.php", $i + 1),
            );
            $writer = new BinaryTraceWriter($stream, 10000);
            $writer->writeHeader();
            $writer->writeTrace($trace);
            $writer->writeTrace($trace);
            $writer->writeSegmentEnd();
        }

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(6, $results);
        $this->assertSame('func_0', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('func_0', $results[1]->trace->call_frames[0]->function_name);
        $this->assertSame('func_1', $results[2]->trace->call_frames[0]->function_name);
        $this->assertSame('func_2', $results[4]->trace->call_frames[0]->function_name);
    }

    public function testEmptyStreamReturnsNothing(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(0, $results);
    }

    public function testTimestampAccumulationResetsPerSegment(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // Segment 1
        $w1 = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $w1->writeHeader();
        $w1->writeTrace($trace, 0);
        $w1->writeTrace($trace, 10000);
        $w1->writeSegmentEnd();

        // Segment 2
        $w2 = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $w2->writeHeader();
        $w2->writeTrace($trace, 0);
        $w2->writeTrace($trace, 5000);
        $w2->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(4, $results);

        // Segment 1 accumulation
        $this->assertSame(0, $results[0]->accumulated_timestamp_us);
        $this->assertSame(10000, $results[1]->accumulated_timestamp_us);

        // Segment 2: accumulated_timestamp resets
        $this->assertSame(0, $results[2]->accumulated_timestamp_us);
        $this->assertSame(5000, $results[3]->accumulated_timestamp_us);
    }
}
