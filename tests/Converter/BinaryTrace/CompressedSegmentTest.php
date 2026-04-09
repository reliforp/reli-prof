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
use Reli\Converter\StreamDecompressor;

final class CompressedSegmentTest extends BaseTestCase
{
    public function testCompressedSegmentsProduceConcatenatedGzip(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new SegmentedBinaryTraceWriter(
            stream: $stream,
            sampling_period_us: 10000,
            segment_duration_us: 50_000,
            compress_completed_segments: true,
        );

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // Write samples across 2 segments
        $writer->writeTrace($trace, 0);
        $writer->writeTrace($trace, 30_000);
        $writer->writeTrace($trace, 50_000);  // triggers rotation
        $writer->writeTrace($trace, 80_000);
        $writer->finish();

        $size = ftell($stream);
        $this->assertGreaterThan(0, $size);

        // The output should start with gzip magic
        rewind($stream);
        $magic = fread($stream, 2);
        $this->assertSame("\x1f\x8b", $magic);

        // Decompress (handles concatenated gzip members)
        rewind($stream);
        $decompressed = StreamDecompressor::decompressIfNeeded($stream);
        fclose($stream);

        // Read the decompressed data as multi-segment rbt
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($decompressed));
        fclose($decompressed);

        $this->assertCount(4, $results);
    }

    public function testCompressedSegmentsReadableByConverters(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new SegmentedBinaryTraceWriter(
            stream: $stream,
            sampling_period_us: 10000,
            segment_duration_us: 30_000,
            compress_completed_segments: true,
        );

        $traceA = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/a.php', 1),
        );
        $traceB = new ParsedCallTrace(
            new ParsedCallFrame('func_b', '/b.php', 2),
        );

        $writer->writeTrace($traceA, 0);
        $writer->writeTrace($traceA, 10_000);
        $writer->writeTrace($traceB, 30_000); // rotate
        $writer->writeTrace($traceB, 40_000);
        $writer->finish();

        rewind($stream);

        // Use StreamDecompressor → BinaryTraceReader → FoldedStacksFormatter
        $decompressed = StreamDecompressor::decompressIfNeeded($stream);
        $reader = new BinaryTraceReader();
        $formatter = new FoldedStacksFormatter();
        $result = $formatter->format($reader->read($decompressed));
        fclose($stream);
        fclose($decompressed);

        $this->assertStringContainsString('func_a', $result);
        $this->assertStringContainsString('func_b', $result);
    }

    public function testUncompressedSegmentsStillWork(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // compress_completed_segments=false (default)
        $writer = new SegmentedBinaryTraceWriter(
            stream: $stream,
            sampling_period_us: 10000,
            segment_duration_us: 50_000,
        );

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $writer->writeTrace($trace, 0);
        $writer->writeTrace($trace, 50_000);
        $writer->finish();

        // Should start with RELI magic, not gzip magic
        rewind($stream);
        $magic = fread($stream, 4);
        $this->assertSame('RELI', $magic);

        rewind($stream);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(2, $results);
    }

    public function testConcatenatedGzipVsRawSizeComparison(): void
    {
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('App\\Service::process', '/app/Service.php', 42),
            new ParsedCallFrame('main', '/app/index.php', 10),
        );

        // Raw (uncompressed)
        $raw_stream = fopen('php://memory', 'r+');
        assert($raw_stream !== false);
        $raw_writer = new SegmentedBinaryTraceWriter(
            stream: $raw_stream,
            sampling_period_us: 10000,
            segment_duration_us: 50_000,
        );
        for ($i = 0; $i < 100; $i++) {
            $raw_writer->writeTrace($trace, $i * 10_000);
        }
        $raw_writer->finish();
        $raw_size = ftell($raw_stream);
        fclose($raw_stream);

        // Compressed
        $gz_stream = fopen('php://memory', 'r+');
        assert($gz_stream !== false);
        $gz_writer = new SegmentedBinaryTraceWriter(
            stream: $gz_stream,
            sampling_period_us: 10000,
            segment_duration_us: 50_000,
            compress_completed_segments: true,
        );
        for ($i = 0; $i < 100; $i++) {
            $gz_writer->writeTrace($trace, $i * 10_000);
        }
        $gz_writer->finish();
        $gz_size = ftell($gz_stream);
        fclose($gz_stream);

        $this->assertLessThan(
            $raw_size,
            $gz_size,
            'Compressed segments should be smaller than raw',
        );
    }

    public function testFileRotationWithCompression(): void
    {
        // Capture data written to each stream before the writer closes them
        /** @var array<int, string> */
        $captured = [];
        $factory = function (int $index) use (&$captured) {
            // Use a real temp file so data persists after fclose
            $tmp = tempnam(sys_get_temp_dir(), "rbt_rot_test_{$index}_");
            assert($tmp !== false);
            $captured[$index] = $tmp;
            $s = fopen($tmp, 'w+b');
            assert($s !== false);
            return $s;
        };

        $writer = new SegmentedBinaryTraceWriter(
            stream: null,
            sampling_period_us: 10000,
            segment_duration_us: 50_000,
            stream_factory: $factory,
            compress_completed_segments: true,
        );

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $writer->writeTrace($trace, 0);
        $writer->writeTrace($trace, 50_000); // rotate
        $writer->writeTrace($trace, 100_000); // rotate
        $writer->finish();

        // Writer has closed all streams. Read from temp files.
        $this->assertCount(3, $captured);
        foreach ($captured as $index => $path) {
            $data = file_get_contents($path);
            $this->assertNotEmpty($data, "File {$index} should have data");
            $this->assertSame(
                "\x1f\x8b",
                substr($data, 0, 2),
                "File {$index} should start with gzip magic",
            );

            // Each should be independently decompressible
            $s = fopen($path, 'rb');
            assert($s !== false);
            $decompressed = StreamDecompressor::decompressIfNeeded($s);
            $reader = new BinaryTraceReader();
            $results = iterator_to_array($reader->read($decompressed));
            fclose($decompressed);
            $this->assertGreaterThanOrEqual(1, count($results));
            unlink($path);
        }
    }

    /**
     * Verify that compress + file rotation creates separate streams per segment
     * and the factory is called the expected number of times.
     */
    public function testFileRotationCompressCallsFactoryPerSegment(): void
    {
        $factory_calls = [];
        /** @var array<int, string> */
        $paths = [];
        $factory = function (int $index) use (&$factory_calls, &$paths) {
            $factory_calls[] = $index;
            $tmp = tempnam(sys_get_temp_dir(), "rbt_fc_test_{$index}_");
            assert($tmp !== false);
            $paths[$index] = $tmp;
            $s = fopen($tmp, 'w+b');
            assert($s !== false);
            return $s;
        };

        $writer = new SegmentedBinaryTraceWriter(
            stream: null,
            sampling_period_us: 10000,
            segment_duration_us: 30_000,
            stream_factory: $factory,
            compress_completed_segments: true,
        );

        $traceA = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/a.php', 1),
        );
        $traceB = new ParsedCallTrace(
            new ParsedCallFrame('func_b', '/b.php', 2),
        );

        $writer->writeTrace($traceA, 0);
        $writer->writeTrace($traceA, 10_000);
        $writer->writeTrace($traceB, 30_000);  // rotate
        $writer->writeTrace($traceB, 40_000);
        $writer->writeTrace($traceA, 60_000);  // rotate
        $writer->finish();

        // Factory called exactly 3 times
        $this->assertSame([0, 1, 2], $factory_calls);
        $this->assertCount(3, $paths);

        // Each file is independent gzip with valid rbt content
        foreach ($paths as $index => $path) {
            $data = file_get_contents($path);
            $this->assertNotEmpty($data, "File {$index} should have data");
            $this->assertSame(
                "\x1f\x8b",
                substr($data, 0, 2),
                "File {$index} should be gzip",
            );
        }

        // Segment 0 → func_a, Segment 1 → func_b
        $s0 = fopen($paths[0], 'rb');
        assert($s0 !== false);
        $d0 = StreamDecompressor::decompressIfNeeded($s0);
        $r0 = new BinaryTraceReader();
        $samples0 = iterator_to_array($r0->read($d0));
        fclose($d0);
        $this->assertSame('func_a', $samples0[0]->trace->call_frames[0]->function_name);

        $s1 = fopen($paths[1], 'rb');
        assert($s1 !== false);
        $d1 = StreamDecompressor::decompressIfNeeded($s1);
        $r1 = new BinaryTraceReader();
        $samples1 = iterator_to_array($r1->read($d1));
        fclose($d1);
        $this->assertSame('func_b', $samples1[0]->trace->call_frames[0]->function_name);

        // Cleanup
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
}
