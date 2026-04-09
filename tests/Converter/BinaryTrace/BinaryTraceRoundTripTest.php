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

final class BinaryTraceRoundTripTest extends BaseTestCase
{
    public function testSingleSampleRoundTrip(): void
    {
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('App\\Controller::index', '/app/src/Controller.php', 42),
            new ParsedCallFrame('main', '/app/public/index.php', 10),
        );

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();
        $writer->writeTrace($trace);
        $writer->writeCheckpoint();
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertCount(2, $result->call_frames);
        $this->assertSame('App\\Controller::index', $result->call_frames[0]->function_name);
        $this->assertSame('/app/src/Controller.php', $result->call_frames[0]->file_name);
        $this->assertSame(42, $result->call_frames[0]->lineno);
        $this->assertSame('main', $result->call_frames[1]->function_name);
        $this->assertSame('/app/public/index.php', $result->call_frames[1]->file_name);
        $this->assertSame(10, $result->call_frames[1]->lineno);
    }

    public function testMultipleSamplesWithSharedFrames(): void
    {
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('func_a', '/a.php', 1),
                new ParsedCallFrame('main', '/index.php', 5),
            ),
            new ParsedCallTrace(
                new ParsedCallFrame('func_b', '/b.php', 2),
                new ParsedCallFrame('main', '/index.php', 5),
            ),
            // Same stack as first sample
            new ParsedCallTrace(
                new ParsedCallFrame('func_a', '/a.php', 1),
                new ParsedCallFrame('main', '/index.php', 5),
            ),
        ];

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();
        foreach ($traces as $trace) {
            $writer->writeTrace($trace);
        }

        $data_size = ftell($stream);
        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);

        // First and third should be identical
        $this->assertSame(
            $results[0]->call_frames[0]->function_name,
            $results[2]->call_frames[0]->function_name
        );

        // Verify sampling period was preserved
        $this->assertSame(10000, $reader->getSamplingPeriodUs());

        // The third sample should reuse the existing stack, so data should be compact
        // Header(16) + 3 FRAME_DEFs + 2 STACK_DEFs + 3 SAMPLEs
        // Third sample should be just event_type(1) + payload_len(1) + stack_id(1) = 3 bytes
        $this->assertLessThan(200, $data_size, 'Binary format should be compact');
    }

    public function testEmptyStackRoundTrip(): void
    {
        $trace = new ParsedCallTrace();

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();
        $writer->writeTrace($trace);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertCount(0, $results[0]->call_frames);
    }

    public function testSampleCountTracking(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $this->assertSame(0, $writer->getSampleCount());

        $writer->writeTrace($trace);
        $this->assertSame(1, $writer->getSampleCount());

        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        $this->assertSame(3, $writer->getSampleCount());

        $writer->writeCheckpoint();
        $this->assertSame(0, $writer->getSamplesSinceCheckpoint());

        $writer->writeTrace($trace);
        $this->assertSame(1, $writer->getSamplesSinceCheckpoint());
        $this->assertSame(4, $writer->getSampleCount());

        fclose($stream);
    }

    public function testCompactSampleSize(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('App\\Service::process', '/app/src/Service.php', 100),
            new ParsedCallFrame('App\\Controller::handle', '/app/src/Controller.php', 50),
            new ParsedCallFrame('main', '/app/public/index.php', 3),
        );

        // Write first sample (includes FRAME_DEFs + STACK_DEF + SAMPLE)
        $writer->writeTrace($trace);
        $pos_after_first = ftell($stream);

        // Write second sample of same stack (only SAMPLE)
        $writer->writeTrace($trace);
        $pos_after_second = ftell($stream);

        $sample_only_size = $pos_after_second - $pos_after_first;

        // A repeated sample should be very compact: type(1) + len(1) + stack_id(1) = 3 bytes
        $this->assertLessThanOrEqual(5, $sample_only_size, 'Repeated sample should be 2-5 bytes');

        fclose($stream);
    }

    public function testInvalidMagicThrows(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, "XXXX" . str_repeat("\0", 12));
        rewind($stream);

        $reader = new BinaryTraceReader();
        $this->expectException(BinaryTraceException::class);
        $this->expectExceptionMessage('Invalid magic');
        iterator_to_array($reader->read($stream));
        fclose($stream);
    }

    public function testUnsupportedVersionThrows(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, "RELI" . chr(99) . str_repeat("\0", 11));
        rewind($stream);

        $reader = new BinaryTraceReader();
        $this->expectException(BinaryTraceException::class);
        $this->expectExceptionMessage('Unsupported version');
        iterator_to_array($reader->read($stream));
        fclose($stream);
    }

    public function testUndefinedStackThrows(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // Write valid header
        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        // Manually write a SAMPLE referencing non-existent stack_id=99
        fwrite($stream, chr(EventType::SAMPLE->value));
        $payload = Varint::encode(99);
        fwrite($stream, Varint::encode(strlen($payload)));
        fwrite($stream, $payload);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $this->expectException(BinaryTraceException::class);
        $this->expectExceptionMessage('undefined stack_id');
        iterator_to_array($reader->read($stream));
        fclose($stream);
    }

    public function testUnknownEventTypesAreSkipped(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        // Write a normal trace
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);

        // Inject an unknown event type (0xFF) with some payload
        fwrite($stream, chr(0xFF));
        $unknown_payload = 'some_unknown_data';
        fwrite($stream, Varint::encode(strlen($unknown_payload)));
        fwrite($stream, $unknown_payload);

        // Write another normal trace (reuses existing frame/stack)
        $writer->writeTrace($trace);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        // Both samples should be read, unknown event skipped
        $this->assertCount(2, $results);
    }
}
