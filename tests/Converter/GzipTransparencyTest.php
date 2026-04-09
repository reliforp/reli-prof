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
use Reli\Converter\BinaryTrace\BinaryTraceException;
use Reli\Converter\BinaryTrace\BinaryTraceReader;
use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Converter\BinaryTrace\FoldedStacksFormatter;
use Reli\Converter\BinaryTrace\PprofEncoder;

final class GzipTransparencyTest extends BaseTestCase
{
    private function createRbtData(): string
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('App\\Controller::index', '/app/Controller.php', 42),
            new ParsedCallFrame('main', '/app/index.php', 10),
        );
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        $writer->writeCheckpoint();
        $writer->writeSegmentEnd();
        rewind($stream);
        $data = stream_get_contents($stream);
        fclose($stream);
        assert($data !== false);
        return $data;
    }

    public function testDecompressRawRbt(): void
    {
        $raw = $this->createRbtData();
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $raw);
        rewind($stream);

        $decompressed = StreamDecompressor::decompressIfNeeded($stream);
        $content = stream_get_contents($decompressed);
        fclose($stream);
        fclose($decompressed);

        $this->assertSame($raw, $content);
    }

    public function testDecompressGzippedRbt(): void
    {
        $raw = $this->createRbtData();
        $compressed = gzencode($raw);
        assert($compressed !== false);

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $compressed);
        rewind($stream);

        $decompressed = StreamDecompressor::decompressIfNeeded($stream);
        $content = stream_get_contents($decompressed);
        fclose($stream);
        fclose($decompressed);

        $this->assertSame($raw, $content);
    }

    public function testTraceInputReaderReadsGzippedRbt(): void
    {
        $raw = $this->createRbtData();
        $compressed = gzencode($raw);
        assert($compressed !== false);

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $compressed);
        rewind($stream);

        $reader = new TraceInputReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
        $this->assertSame(
            'App\\Controller::index',
            $results[0]->call_frames[0]->function_name,
        );
    }

    public function testTraceInputReaderReadsRawRbt(): void
    {
        $raw = $this->createRbtData();
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $raw);
        rewind($stream);

        $reader = new TraceInputReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
    }

    public function testGzippedRbtToFolded(): void
    {
        $raw = $this->createRbtData();
        $compressed = gzencode($raw);
        assert($compressed !== false);

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $compressed);
        rewind($stream);

        $reader = new TraceInputReader();
        $formatter = new FoldedStacksFormatter();
        $result = $formatter->format($reader->read($stream));
        fclose($stream);

        $this->assertStringContainsString('App\\Controller::index', $result);
        $this->assertStringContainsString(' 3', $result);
    }

    public function testGzippedRbtToPprof(): void
    {
        $raw = $this->createRbtData();
        $compressed = gzencode($raw);
        assert($compressed !== false);

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $compressed);
        rewind($stream);

        $reader = new TraceInputReader();
        $encoder = new PprofEncoder();
        $data = $encoder->encode($reader->read($stream));
        fclose($stream);

        $this->assertNotEmpty($data);
    }

    public function testGzippedRbtRecovery(): void
    {
        $raw = $this->createRbtData();
        $compressed = gzencode($raw);
        assert($compressed !== false);

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $compressed);
        rewind($stream);

        $decompressed = StreamDecompressor::decompressIfNeeded($stream);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($decompressed));
        fclose($stream);
        fclose($decompressed);

        $this->assertCount(3, $results);
    }

    public function testRawAndGzipDecodeResultsMatch(): void
    {
        $raw = $this->createRbtData();
        $compressed = gzencode($raw);
        assert($compressed !== false);

        // Read raw
        $stream_raw = fopen('php://memory', 'r+');
        assert($stream_raw !== false);
        fwrite($stream_raw, $raw);
        rewind($stream_raw);
        $reader_raw = new TraceInputReader();
        $results_raw = iterator_to_array($reader_raw->read($stream_raw));
        fclose($stream_raw);

        // Read gzipped
        $stream_gz = fopen('php://memory', 'r+');
        assert($stream_gz !== false);
        fwrite($stream_gz, $compressed);
        rewind($stream_gz);
        $reader_gz = new TraceInputReader();
        $results_gz = iterator_to_array($reader_gz->read($stream_gz));
        fclose($stream_gz);

        $this->assertCount(count($results_raw), $results_gz);
        foreach ($results_raw as $i => $raw_trace) {
            $gz_trace = $results_gz[$i];
            $this->assertSame(
                count($raw_trace->call_frames),
                count($gz_trace->call_frames),
            );
            foreach ($raw_trace->call_frames as $j => $raw_frame) {
                $gz_frame = $gz_trace->call_frames[$j];
                $this->assertSame($raw_frame->function_name, $gz_frame->function_name);
                $this->assertSame($raw_frame->file_name, $gz_frame->file_name);
                $this->assertSame($raw_frame->lineno, $gz_frame->lineno);
            }
        }
    }

    public function testCorruptGzipThrows(): void
    {
        // Valid gzip magic but corrupt data
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, "\x1f\x8b\x08\x00corrupt_garbage_data");
        rewind($stream);

        $this->expectException(BinaryTraceException::class);
        $this->expectExceptionMessage('Failed to decompress gzip input');
        StreamDecompressor::decompressIfNeeded($stream);
    }

    public function testGzipMagicDetection(): void
    {
        // Not gzip — phpspy text starting with '0'
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, "0 func /file.php:1\n\n");
        rewind($stream);

        $result = StreamDecompressor::decompressIfNeeded($stream);
        rewind($result);
        $content = stream_get_contents($result);
        fclose($stream);
        fclose($result);

        $this->assertStringStartsWith('0 func', $content);
    }
}
