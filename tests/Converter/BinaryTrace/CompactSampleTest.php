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

final class CompactSampleTest extends BaseTestCase
{
    public function testCompactSampleRoundTrip(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // has_timestamps=false → uses COMPACT_SAMPLE
        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
        $this->assertSame('func', $results[0]->trace->call_frames[0]->function_name);
        // No timestamps
        $this->assertNull($results[0]->timestamp_delta_us);
        $this->assertNull($results[0]->accumulated_timestamp_us);
        $this->assertFalse($reader->hasTimestamps());
    }

    public function testCompactSampleIsSmallerThanTimestampedSample(): void
    {
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('App\\Service::process', '/app/src/Service.php', 100),
            new ParsedCallFrame('App\\Controller::handle', '/app/src/Controller.php', 50),
            new ParsedCallFrame('main', '/app/public/index.php', 3),
        );
        $sample_count = 1000;

        // Write with timestamps
        $stream_ts = fopen('php://memory', 'r+');
        assert($stream_ts !== false);
        $writer_ts = new BinaryTraceWriter($stream_ts, 10000, has_timestamps: true);
        $writer_ts->writeHeader();
        for ($i = 0; $i < $sample_count; $i++) {
            $writer_ts->writeTrace($trace, 10000);
        }
        $size_with_ts = ftell($stream_ts);
        fclose($stream_ts);

        // Write without timestamps (compact)
        $stream_compact = fopen('php://memory', 'r+');
        assert($stream_compact !== false);
        $writer_compact = new BinaryTraceWriter($stream_compact, 10000, has_timestamps: false);
        $writer_compact->writeHeader();
        for ($i = 0; $i < $sample_count; $i++) {
            $writer_compact->writeTrace($trace);
        }
        $writer_compact->flushPendingRun();
        $size_compact = ftell($stream_compact);
        fclose($stream_compact);

        $this->assertLessThan(
            $size_with_ts,
            $size_compact,
            'Compact (no timestamps) should be smaller than timestamped'
        );

        // Compact sample: 2 bytes each (event_type + stack_id varint)
        // Timestamped: 5 bytes each (event_type + payload_len + stack_id + ts_delta)
        // So compact should be roughly 40% of timestamped sample size
        $sample_size_compact = $size_compact - 16; // subtract header
        $sample_size_ts = $size_with_ts - 16;
        $this->assertLessThan(
            $sample_size_ts * 0.6,
            $sample_size_compact,
            'Compact samples should be well under 60% of timestamped size'
        );
    }

    public function testCompactSampleExactSize(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $traceA = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/a.php', 1),
        );
        $traceB = new ParsedCallTrace(
            new ParsedCallFrame('func_b', '/b.php', 2),
        );

        // First two writes: defs + samples for A and B
        $writer->writeTrace($traceA);
        $writer->writeTrace($traceB);
        $writer->flushPendingRun();
        $pos_after_defs = ftell($stream);

        // Next write: only a COMPACT_SAMPLE (different stack, triggers flush of previous)
        $writer->writeTrace($traceA);
        $writer->flushPendingRun();
        $pos_after_one_sample = ftell($stream);

        $compact_sample_size = $pos_after_one_sample - $pos_after_defs;
        // COMPACT_SAMPLE: event_type(1) + stack_id_varint(1) = 2 bytes
        $this->assertSame(2, $compact_sample_size);

        fclose($stream);
    }

    public function testCompactSampleConvertsToFolded(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('leaf', '/leaf.php', 10),
            new ParsedCallFrame('main', '/index.php', 1),
        );
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $formatter = new FoldedStacksFormatter();
        $result = $formatter->format($reader->read($stream));
        fclose($stream);

        $this->assertSame("main /index.php:1;leaf /leaf.php:10 2\n", $result);
    }

    public function testCompactSampleConvertsToPprof(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $encoder = new PprofEncoder();
        $data = $encoder->encode(
            $reader->read($stream),
            fn () => $reader->getSamplingPeriodUs() ?: 10000,
        );
        fclose($stream);

        $this->assertNotEmpty($data);
    }

    public function testCompactSampleRecovers(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        unset($writer);

        // Append truncated garbage
        fwrite($stream, "\xFF\xFF");

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        $this->assertCount(2, $results);
    }

    public function testTimestampedSampleStillWorks(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // has_timestamps=true → uses length-delimited SAMPLE (not COMPACT_SAMPLE)
        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace, 0);
        $writer->writeTrace($trace, 10000);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(2, $results);
        $this->assertTrue($reader->hasTimestamps());
        $this->assertSame(0, $results[0]->timestamp_delta_us);
        $this->assertSame(10000, $results[1]->timestamp_delta_us);
    }
}
