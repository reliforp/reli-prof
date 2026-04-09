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

final class SegmentedBinaryTraceWriterTest extends BaseTestCase
{
    public function testTimeBasedSegmentRotation(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // 100ms segments
        $writer = new SegmentedBinaryTraceWriter(
            stream: $stream,
            sampling_period_us: 10000,
            segment_duration_us: 100_000,
        );

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // Write samples across segment boundary
        $writer->writeTrace($trace, 0);       // t=0ms, segment 0
        $writer->writeTrace($trace, 50_000);   // t=50ms, segment 0
        $writer->writeTrace($trace, 100_000);  // t=100ms, triggers rotation to segment 1
        $writer->writeTrace($trace, 150_000);  // t=150ms, segment 1
        $writer->finish();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(4, $results);
        // All 4 samples should be readable
        foreach ($results as $sample) {
            $this->assertSame('func', $sample->trace->call_frames[0]->function_name);
        }

        // Verify timestamps are preserved
        $this->assertNotNull($results[0]->timestamp_delta_us);
    }

    public function testSegmentSelfContainment(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new SegmentedBinaryTraceWriter(
            stream: $stream,
            sampling_period_us: 10000,
            segment_duration_us: 50_000,
        );

        $traceA = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/a.php', 1),
        );
        $traceB = new ParsedCallTrace(
            new ParsedCallFrame('func_b', '/b.php', 2),
        );

        // traceA in segment 0, traceB introduced in segment 0, used in segment 1
        $writer->writeTrace($traceA, 0);
        $writer->writeTrace($traceB, 20_000);
        $writer->writeTrace($traceB, 50_000);  // triggers rotation
        $writer->writeTrace($traceA, 70_000);  // segment 1, traceA defs should be re-emitted
        $writer->finish();

        rewind($stream);

        // Read and verify all samples are intact
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(4, $results);
        $this->assertSame('func_a', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('func_b', $results[1]->trace->call_frames[0]->function_name);
        $this->assertSame('func_b', $results[2]->trace->call_frames[0]->function_name);
        $this->assertSame('func_a', $results[3]->trace->call_frames[0]->function_name);
    }

    public function testSegmentIndexIncrement(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new SegmentedBinaryTraceWriter(
            stream: $stream,
            sampling_period_us: 10000,
            segment_duration_us: 10_000,
        );

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $this->assertSame(0, $writer->getSegmentIndex());

        $writer->writeTrace($trace, 0);
        $this->assertSame(0, $writer->getSegmentIndex());

        $writer->writeTrace($trace, 10_000); // triggers rotation
        $this->assertSame(1, $writer->getSegmentIndex());

        $writer->writeTrace($trace, 20_000); // triggers rotation again
        $this->assertSame(2, $writer->getSegmentIndex());

        $writer->finish();
        fclose($stream);
    }

    public function testFileRotationMode(): void
    {
        $streams = [];
        $factory = function (int $index) use (&$streams) {
            $stream = fopen('php://memory', 'r+');
            assert($stream !== false);
            $streams[$index] = $stream;
            return $stream;
        };

        $writer = new SegmentedBinaryTraceWriter(
            stream: null,
            sampling_period_us: 10000,
            segment_duration_us: 50_000,
            stream_factory: $factory,
        );

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $writer->writeTrace($trace, 0);
        $writer->writeTrace($trace, 50_000); // triggers rotation
        $writer->writeTrace($trace, 100_000); // triggers rotation
        $writer->finish();

        // Should have created 3 streams (segments 0, 1, 2)
        $this->assertCount(3, $streams);

        // Each stream should be independently readable
        $reader = new BinaryTraceReader();
        foreach ($streams as $index => $stream) {
            rewind($stream);
            $results = iterator_to_array($reader->read($stream));
            $this->assertGreaterThanOrEqual(1, count($results), "Segment {$index} should have samples");
            fclose($stream);
        }
    }

    public function testTimestampDeltasAcrossSegments(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new SegmentedBinaryTraceWriter(
            stream: $stream,
            sampling_period_us: 10000,
            segment_duration_us: 50_000,
        );

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $writer->writeTrace($trace, 0);
        $writer->writeTrace($trace, 10_000);
        $writer->writeTrace($trace, 50_000);  // new segment
        $writer->writeTrace($trace, 60_000);
        $writer->finish();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(4, $results);

        // First sample: delta=0
        $this->assertSame(0, $results[0]->timestamp_delta_us);

        // Second sample: delta=10000
        $this->assertSame(10000, $results[1]->timestamp_delta_us);

        // Third sample (new segment): delta from last sample = 40000
        $this->assertSame(40000, $results[2]->timestamp_delta_us);

        // Fourth sample: delta=10000
        $this->assertSame(10000, $results[3]->timestamp_delta_us);
    }

    public function testNoSamplesProducesNothing(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new SegmentedBinaryTraceWriter(
            stream: $stream,
            sampling_period_us: 10000,
            segment_duration_us: 10_000,
        );

        $writer->finish();

        $this->assertSame(0, ftell($stream));
        fclose($stream);
    }
}
