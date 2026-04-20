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
        $sample = $results[0];
        $this->assertInstanceOf(BinaryTraceSample::class, $sample);
        $this->assertCount(2, $sample->trace->call_frames);
        $this->assertSame('App\\Controller::index', $sample->trace->call_frames[0]->function_name);
        $this->assertSame('/app/src/Controller.php', $sample->trace->call_frames[0]->file_name);
        $this->assertSame(42, $sample->trace->call_frames[0]->lineno);
        $this->assertSame('main', $sample->trace->call_frames[1]->function_name);
        $this->assertSame('/app/public/index.php', $sample->trace->call_frames[1]->file_name);
        $this->assertSame(10, $sample->trace->call_frames[1]->lineno);
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
        unset($writer);
        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
        $this->assertSame(
            $results[0]->trace->call_frames[0]->function_name,
            $results[2]->trace->call_frames[0]->function_name
        );
        $this->assertSame(10000, $reader->getSamplingPeriodUs());
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
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertCount(0, $results[0]->trace->call_frames);
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

        $traceA = new ParsedCallTrace(
            new ParsedCallFrame('App\\Service::process', '/app/src/Service.php', 100),
            new ParsedCallFrame('App\\Controller::handle', '/app/src/Controller.php', 50),
            new ParsedCallFrame('main', '/app/public/index.php', 3),
        );
        $traceB = new ParsedCallTrace(
            new ParsedCallFrame('other', '/other.php', 1),
        );

        $writer->writeTrace($traceA);
        $writer->writeTrace($traceB); // different stack, flushes A
        $writer->flushPendingRun();
        $pos_after_defs = ftell($stream);

        $writer->writeTrace($traceA); // reuse existing stack, triggers flush of B
        $writer->flushPendingRun();
        $pos_after_one_sample = ftell($stream);

        $sample_only_size = $pos_after_one_sample - $pos_after_defs;
        $this->assertLessThanOrEqual(5, $sample_only_size, 'Repeated sample should be 2-5 bytes');

        fclose($stream);
    }

    public function testTimestampRoundTrip(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $writer->writeTrace($trace, 0);
        $writer->writeTrace($trace, 10000);
        $writer->writeTrace($trace, 10500);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
        $this->assertTrue($reader->hasTimestamps());

        $this->assertSame(0, $results[0]->timestamp_delta_us);
        $this->assertSame(0, $results[0]->accumulated_timestamp_us);

        $this->assertSame(10000, $results[1]->timestamp_delta_us);
        $this->assertSame(10000, $results[1]->accumulated_timestamp_us);

        $this->assertSame(10500, $results[2]->timestamp_delta_us);
        $this->assertSame(20500, $results[2]->accumulated_timestamp_us);
    }

    public function testTimestampNotLostWithoutFlag(): void
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
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]->timestamp_delta_us);
        $this->assertNull($results[0]->accumulated_timestamp_us);
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

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

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

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);

        // Inject an unknown event type (0x10) with some payload
        fwrite($stream, chr(0x10));
        $unknown_payload = 'some_unknown_data';
        fwrite($stream, Varint::encode(strlen($unknown_payload)));
        fwrite($stream, $unknown_payload);

        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(2, $results);
    }

    public function testDefineTraceDoesNotEmitSample(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $stack_id = $writer->defineTrace($trace);
        $this->assertSame(0, $stack_id);
        $this->assertSame(0, $writer->getSampleCount());

        $writer->writeTrace($trace);
        $this->assertSame(1, $writer->getSampleCount());
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        // Only 1 sample, not 2
        $this->assertCount(1, $results);
    }

    public function testCompactSampleSizeWithTimestamps(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $writer->writeTrace($trace, 0);
        $pos_after_first = ftell($stream);

        $writer->writeTrace($trace, 10000);
        $pos_after_second = ftell($stream);

        $sample_with_ts_size = $pos_after_second - $pos_after_first;
        // event_type(1) + payload_len(1) + stack_id(1) + timestamp_delta(2) = 5 bytes
        $this->assertLessThanOrEqual(6, $sample_with_ts_size, 'Repeated sample with timestamp should be <= 6 bytes');

        fclose($stream);
    }

    public function testReservedEventType0x52ThrowsWithoutMagic(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        unset($writer);

        // Inject 0x52 followed by bytes that do NOT form "RELI"
        fwrite($stream, chr(0x52) . "XYZ");

        rewind($stream);

        $reader = new BinaryTraceReader();
        $this->expectException(BinaryTraceException::class);
        $this->expectExceptionMessage('Reserved event type 0x52');
        iterator_to_array($reader->read($stream));
        fclose($stream);
    }

    public function testSamplingPeriodPreservedThroughReadWrite(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // Write with a non-default sampling period
        $writer = new BinaryTraceWriter($stream, 7500);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertSame(7500, $reader->getSamplingPeriodUs());
    }

    public function testSamplingPeriodAvailableAfterFirstSample(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 25000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();

        // Before iteration, period is not yet known
        $this->assertSame(0, $reader->getSamplingPeriodUs());

        // Consume first sample (triggers header parse)
        $gen = $reader->read($stream);
        $first = $gen->current();
        $this->assertInstanceOf(BinaryTraceSample::class, $first);

        // Now the period is available
        $this->assertSame(25000, $reader->getSamplingPeriodUs());

        fclose($stream);
    }

    public function testMetadataRoundTrip(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();
        $writer->writeMetadata('pid', '12345');
        $writer->writeMetadata('host', 'web-01');

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $metadata = $reader->getMetadata();
        $this->assertSame('12345', $metadata['pid']);
        $this->assertSame('web-01', $metadata['host']);
    }

    public function testPidSampleRoundTrip(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writePidTrace($trace, 1234, 0);
        $writer->writePidTrace($trace, 5678, 10000);
        $writer->writePidTrace($trace, 1234, 10000);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
        $this->assertSame(1234, $results[0]->pid);
        $this->assertSame(5678, $results[1]->pid);
        $this->assertSame(1234, $results[2]->pid);

        // Timestamps should be preserved
        $this->assertSame(0, $results[0]->timestamp_delta_us);
        $this->assertSame(10000, $results[1]->timestamp_delta_us);
    }

    public function testPidSampleNullForRegularSample(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]->pid);
    }

    public function testMetadataResetsOnNewSegment(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // Segment 1 with pid metadata
        $w1 = new BinaryTraceWriter($stream, 10000);
        $w1->writeHeader();
        $w1->writeMetadata('pid', '100');
        $trace = new ParsedCallTrace(new ParsedCallFrame('func', '/file.php', 1));
        $w1->writeTrace($trace);
        $w1->writeSegmentEnd();

        // Segment 2 with different pid
        $w2 = new BinaryTraceWriter($stream, 10000);
        $w2->writeHeader();
        $w2->writeMetadata('pid', '200');
        $w2->writeTrace($trace);
        $w2->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $samples = [];
        foreach ($reader->read($stream) as $sample) {
            $samples[] = ['sample' => $sample, 'metadata' => $reader->getMetadata()];
        }
        fclose($stream);

        $this->assertCount(2, $samples);
        $this->assertSame('100', $samples[0]['metadata']['pid']);
        $this->assertSame('200', $samples[1]['metadata']['pid']);
    }
}
