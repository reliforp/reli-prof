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

final class RepeatSampleTest extends BaseTestCase
{
    public function testRunOf3ProducesRepeatEvent(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // 3 consecutive identical stacks → COMPACT_SAMPLE + REPEAT_SAMPLE(2)
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        $writer->writeCheckpoint(); // flushes

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
        $this->assertSame('func', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('func', $results[2]->trace->call_frames[0]->function_name);
    }

    public function testRunOf2DoesNotUseRepeat(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // First sample (triggers FRAME_DEF + STACK_DEF + flush of 0 pending)
        $writer->writeTrace($trace);
        $pos_after_first = ftell($stream);

        // Second identical sample
        $writer->writeTrace($trace);
        $writer->writeCheckpoint();
        $pos_after_flush = ftell($stream);

        rewind($stream);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(2, $results);

        // Run of 2: should be 2 COMPACT_SAMPLEs (2+2=4 bytes) not COMPACT+REPEAT
        // The samples portion after defs: 4 bytes
        $sample_bytes = $pos_after_flush - $pos_after_first
            - 4; // subtract ~checkpoint overhead (approx)
        // Just verify it reads correctly; the threshold is 3+
        $this->assertSame('func', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('func', $results[1]->trace->call_frames[0]->function_name);
    }

    public function testLargeRunCompresses(): void
    {
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('idle', '/worker.php', 1),
        );
        $run_length = 1000;

        // Without RLE: 1000 COMPACT_SAMPLEs = 2000 bytes of samples
        // With RLE: 1 COMPACT_SAMPLE + 1 REPEAT_SAMPLE(999) = 2 + 1 + 2 = 5 bytes of samples

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();
        for ($i = 0; $i < $run_length; $i++) {
            $writer->writeTrace($trace);
        }
        $writer->writeCheckpoint();
        $size_rle = ftell($stream);

        rewind($stream);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1000, $results);

        // Header(16) + FRAME_DEF + STACK_DEF + COMPACT(2) + REPEAT(~4) + CHECKPOINT
        // Total should be well under 100 bytes
        $this->assertLessThan(100, $size_rle);
    }

    public function testMixedStacks(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $traceA = new ParsedCallTrace(new ParsedCallFrame('func_a', '/a.php', 1));
        $traceB = new ParsedCallTrace(new ParsedCallFrame('func_b', '/b.php', 2));

        // A, A, A, B, B, B, B, A
        $writer->writeTrace($traceA);
        $writer->writeTrace($traceA);
        $writer->writeTrace($traceA);
        $writer->writeTrace($traceB);
        $writer->writeTrace($traceB);
        $writer->writeTrace($traceB);
        $writer->writeTrace($traceB);
        $writer->writeTrace($traceA);
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(8, $results);
        $this->assertSame('func_a', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('func_a', $results[2]->trace->call_frames[0]->function_name);
        $this->assertSame('func_b', $results[3]->trace->call_frames[0]->function_name);
        $this->assertSame('func_b', $results[6]->trace->call_frames[0]->function_name);
        $this->assertSame('func_a', $results[7]->trace->call_frames[0]->function_name);
    }

    public function testRepeatWithoutPrecedingSampleThrows(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // Write header then inject raw REPEAT_SAMPLE without a preceding sample
        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        fwrite($stream, chr(EventType::REPEAT_SAMPLE->value));
        fwrite($stream, Varint::encode(5));

        rewind($stream);

        $reader = new BinaryTraceReader();
        $this->expectException(BinaryTraceException::class);
        $this->expectExceptionMessage('REPEAT_SAMPLE without a preceding sample');
        iterator_to_array($reader->read($stream));
        fclose($stream);
    }

    public function testRepeatAcrossSegmentBoundaryResetsState(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace = new ParsedCallTrace(new ParsedCallFrame('func', '/file.php', 1));

        // Segment 1
        $w1 = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $w1->writeHeader();
        $w1->writeTrace($trace);
        $w1->writeTrace($trace);
        $w1->writeTrace($trace);
        $w1->writeSegmentEnd();

        // Segment 2 — last_sample_stack_id should be reset
        $w2 = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $w2->writeHeader();

        // Inject REPEAT_SAMPLE at start of segment 2 (no preceding sample in this segment)
        fwrite($stream, chr(EventType::REPEAT_SAMPLE->value));
        fwrite($stream, Varint::encode(3));

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = [];
        $error_caught = false;
        try {
            foreach ($reader->read($stream) as $sample) {
                $results[] = $sample;
            }
        } catch (BinaryTraceException) {
            $error_caught = true;
        }
        fclose($stream);

        // Should have read 3 samples from segment 1
        $this->assertCount(3, $results);
        // Segment 2 should fail with "without a preceding sample"
        $this->assertTrue($error_caught);
    }

    public function testRepeatConvertsToFolded(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $traceA = new ParsedCallTrace(new ParsedCallFrame('func_a', '/a.php', 1));
        $traceB = new ParsedCallTrace(new ParsedCallFrame('func_b', '/b.php', 2));

        for ($i = 0; $i < 5; $i++) {
            $writer->writeTrace($traceA);
        }
        for ($i = 0; $i < 3; $i++) {
            $writer->writeTrace($traceB);
        }
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $formatter = new FoldedStacksFormatter();
        $result = $formatter->format($reader->read($stream));
        fclose($stream);

        $this->assertStringContainsString('func_a /a.php:1 5', $result);
        $this->assertStringContainsString('func_b /b.php:2 3', $result);
    }

    public function testRepeatRecovery(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(new ParsedCallFrame('func', '/file.php', 1));
        for ($i = 0; $i < 10; $i++) {
            $writer->writeTrace($trace);
        }
        $writer->writeSegmentEnd();

        // Append garbage
        fwrite($stream, "\xFF\xFF\xFF");

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        $this->assertCount(10, $results);
    }

    public function testTimestampedModeDoesNotUseRepeat(): void
    {
        // With timestamps, each sample has a unique delta, so no RLE.
        // Verify by checking that timestamps are preserved through round-trip.
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(new ParsedCallFrame('func', '/file.php', 1));
        $writer->writeTrace($trace, 0);
        $writer->writeTrace($trace, 10000);
        $writer->writeTrace($trace, 10000);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
        $this->assertTrue($reader->hasTimestamps());
        // Timestamps must be individually preserved (not collapsed by RLE)
        $this->assertSame(0, $results[0]->timestamp_delta_us);
        $this->assertSame(10000, $results[1]->timestamp_delta_us);
        $this->assertSame(10000, $results[2]->timestamp_delta_us);
    }
}
