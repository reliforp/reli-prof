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

/**
 * Tests simulating daemon output patterns:
 * - per-worker mode: attach/detach cycles producing segments
 * - bundled mode: PID_SAMPLE events from multiple PIDs
 */
final class DaemonOutputTest extends BaseTestCase
{
    /**
     * Simulates per-worker mode: two attach/detach cycles on the same stream.
     * Each cycle must produce a self-contained segment with header + defs.
     */
    public function testPerWorkerAttachDetachProducesSelfContainedSegments(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $traceA = new ParsedCallTrace(
            new ParsedCallFrame('App\\Handler::run', '/app/Handler.php', 42),
        );
        $traceB = new ParsedCallTrace(
            new ParsedCallFrame('App\\Worker::process', '/app/Worker.php', 10),
        );

        // Attach 1: pid=100
        $writer1 = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $writer1->writeHeader();
        $writer1->writeMetadata('pid', '100');
        $writer1->writeTrace($traceA, 0);
        $writer1->writeTrace($traceA, 10000);
        $writer1->writeCheckpoint();
        $writer1->writeSegmentEnd();

        // Attach 2: pid=200, fresh writer on same stream
        $writer2 = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $writer2->writeHeader();
        $writer2->writeMetadata('pid', '200');
        $writer2->writeTrace($traceB, 0);
        $writer2->writeTrace($traceB, 10000);
        $writer2->writeCheckpoint();
        $writer2->writeSegmentEnd();

        rewind($stream);

        // Reader should see 4 samples across 2 segments
        $reader = new BinaryTraceReader();
        $samples = [];
        $segment_metadata = [];
        foreach ($reader->read($stream) as $sample) {
            $metadata = $reader->getMetadata();
            $samples[] = $sample;
            $segment_metadata[] = $metadata;
        }
        fclose($stream);

        $this->assertCount(4, $samples);

        // First segment: pid=100
        $this->assertSame('100', $segment_metadata[0]['pid']);
        $this->assertSame('100', $segment_metadata[1]['pid']);
        $this->assertSame('App\\Handler::run', $samples[0]->trace->call_frames[0]->function_name);

        // Second segment: pid=200
        $this->assertSame('200', $segment_metadata[2]['pid']);
        $this->assertSame('200', $segment_metadata[3]['pid']);
        $this->assertSame('App\\Worker::process', $samples[2]->trace->call_frames[0]->function_name);

        // Timestamps within each segment
        $this->assertSame(0, $samples[0]->timestamp_delta_us);
        $this->assertSame(10000, $samples[1]->timestamp_delta_us);
        $this->assertSame(0, $samples[2]->timestamp_delta_us);
        $this->assertSame(10000, $samples[3]->timestamp_delta_us);
    }

    /**
     * Verify each segment is independently decodable by reading them separately.
     */
    public function testEachSegmentIsIndependentlyDecodable(): void
    {
        $full_stream = fopen('php://memory', 'r+');
        assert($full_stream !== false);

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // Write two segments
        for ($i = 0; $i < 2; $i++) {
            $writer = new BinaryTraceWriter($full_stream, 10000, has_timestamps: true);
            $writer->writeHeader();
            $writer->writeMetadata('pid', (string)(100 + $i));
            $writer->writeTrace($trace, 0);
            $writer->writeCheckpoint();
            $writer->writeSegmentEnd();
        }

        // Read the full stream and find segment boundaries
        rewind($full_stream);
        $full_data = stream_get_contents($full_stream);
        fclose($full_stream);

        // Find second "RELI" magic (first is at offset 0)
        $second_magic_pos = strpos($full_data, BinaryTraceWriter::MAGIC, 1);
        $this->assertNotFalse($second_magic_pos);

        // Read segment 1 alone
        $seg1_stream = fopen('php://memory', 'r+');
        assert($seg1_stream !== false);
        fwrite($seg1_stream, substr($full_data, 0, $second_magic_pos));
        rewind($seg1_stream);

        $reader1 = new BinaryTraceReader();
        $seg1_samples = iterator_to_array($reader1->read($seg1_stream));
        fclose($seg1_stream);

        $this->assertCount(1, $seg1_samples);
        $this->assertSame('100', $reader1->getMetadata()['pid']);

        // Read segment 2 alone
        $seg2_stream = fopen('php://memory', 'r+');
        assert($seg2_stream !== false);
        fwrite($seg2_stream, substr($full_data, $second_magic_pos));
        rewind($seg2_stream);

        $reader2 = new BinaryTraceReader();
        $seg2_samples = iterator_to_array($reader2->read($seg2_stream));
        fclose($seg2_stream);

        $this->assertCount(1, $seg2_samples);
        $this->assertSame('101', $reader2->getMetadata()['pid']);
    }

    /**
     * Frame/stack IDs must not leak across segments.
     * Use the same function name in both segments — each segment should
     * define its own frame_id=0.
     */
    public function testFrameStackStateDoesNotLeakAcrossSegments(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('shared_func', '/shared.php', 1),
        );

        // Segment 1
        $w1 = new BinaryTraceWriter($stream, 10000);
        $w1->writeHeader();
        $w1->writeTrace($trace);
        $w1->writeSegmentEnd();

        // Segment 2 — fresh writer, same trace definition
        $w2 = new BinaryTraceWriter($stream, 10000);
        $w2->writeHeader();
        $w2->writeTrace($trace);
        $w2->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(2, $results);
        $this->assertSame('shared_func', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('shared_func', $results[1]->trace->call_frames[0]->function_name);
    }

    /**
     * Bundled mode: PID_SAMPLE from multiple PIDs in single stream.
     */
    public function testBundledModeMultiplePids(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $traceA = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/a.php', 1),
        );
        $traceB = new ParsedCallTrace(
            new ParsedCallFrame('func_b', '/b.php', 2),
        );

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $writer->writeHeader();
        $writer->writePidTrace($traceA, 100, 0);
        $writer->writePidTrace($traceB, 200, 10000);
        $writer->writePidTrace($traceA, 100, 10000);
        $writer->writeCheckpoint();
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
        $this->assertSame(100, $results[0]->pid);
        $this->assertSame(200, $results[1]->pid);
        $this->assertSame(100, $results[2]->pid);
    }

    /**
     * Bundled mode clean shutdown: CHECKPOINT + SEGMENT_END at end.
     */
    public function testBundledModeCleanEnd(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();
        $writer->writeTrace($trace);
        $writer->writeCheckpoint();
        $writer->writeSegmentEnd();

        $pos = ftell($stream);
        rewind($stream);
        $data = fread($stream, $pos);
        fclose($stream);

        // Last byte of SEGMENT_END payload_length=0: event(0x05) + varint(0x00)
        $this->assertSame(chr(EventType::SEGMENT_END->value), $data[$pos - 2]);
        $this->assertSame("\x00", $data[$pos - 1]);
    }

    /**
     * Sampling period is correctly written to header when derived from settings.
     */
    public function testSamplingPeriodInHeader(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // 20ms = 20000µs
        $writer = new BinaryTraceWriter($stream, 20000, has_timestamps: true);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace, 0);

        rewind($stream);

        $reader = new BinaryTraceReader();
        iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertSame(20000, $reader->getSamplingPeriodUs());
    }
}
