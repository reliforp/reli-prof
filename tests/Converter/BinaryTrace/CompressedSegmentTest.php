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
        $streams = [];
        $factory = function (int $index) use (&$streams) {
            $s = fopen('php://memory', 'r+');
            assert($s !== false);
            $streams[$index] = $s;
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

        // Each stream should contain gzip data
        foreach ($streams as $index => $s) {
            rewind($s);
            $magic = fread($s, 2);
            $this->assertSame(
                "\x1f\x8b",
                $magic,
                "Stream {$index} should start with gzip magic",
            );

            // Each should be independently decompressible and readable
            rewind($s);
            $decompressed = StreamDecompressor::decompressIfNeeded($s);
            $reader = new BinaryTraceReader();
            $results = iterator_to_array($reader->read($decompressed));
            fclose($decompressed);
            $this->assertGreaterThanOrEqual(1, count($results));
            fclose($s);
        }
    }
}
