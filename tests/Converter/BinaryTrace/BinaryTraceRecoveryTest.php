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

final class BinaryTraceRecoveryTest extends BaseTestCase
{
    public function testRecoverTruncatedTail(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        $writer->writeTrace($trace);
        unset($writer);

        // Append truncated garbage (incomplete event)
        fwrite($stream, chr(EventType::SAMPLE->value));
        fwrite($stream, Varint::encode(50)); // says 50 bytes but only write 5
        fwrite($stream, 'short');

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        // Should recover the 3 good samples before the truncated one
        $this->assertCount(3, $results);
    }

    public function testRecoverBrokenEventSkipsToNextSegment(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace1 = new ParsedCallTrace(
            new ParsedCallFrame('good_func_1', '/good1.php', 1),
        );
        $trace2 = new ParsedCallTrace(
            new ParsedCallFrame('good_func_2', '/good2.php', 2),
        );

        // Segment 1: good data + corruption
        $writer1 = new BinaryTraceWriter($stream, 10000);
        $writer1->writeHeader();
        $writer1->writeTrace($trace1);
        unset($writer1);

        // Inject corruption: invalid payload_length varint then garbage
        fwrite($stream, chr(EventType::SAMPLE->value));
        fwrite($stream, "\xFF\xFF\xFF\xFF\x7F"); // huge varint
        fwrite($stream, str_repeat("\xAB", 20)); // garbage

        // Segment 2: good data
        $writer2 = new BinaryTraceWriter($stream, 10000);
        $writer2->writeHeader();
        $writer2->writeTrace($trace2);
        $writer2->writeTrace($trace2);
        $writer2->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        // Should recover sample from segment 1 + 2 samples from segment 2
        $this->assertCount(3, $results);
        $this->assertSame('good_func_1', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('good_func_2', $results[1]->trace->call_frames[0]->function_name);
        $this->assertSame('good_func_2', $results[2]->trace->call_frames[0]->function_name);
    }

    public function testRecoverUndefinedRefSkipsToNextSegment(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // Segment 1: good sample then bad reference
        $writer1 = new BinaryTraceWriter($stream, 10000);
        $writer1->writeHeader();
        $writer1->writeTrace($trace);
        unset($writer1);

        // Manually write SAMPLE with undefined stack_id=99
        fwrite($stream, chr(EventType::SAMPLE->value));
        $payload = Varint::encode(99);
        fwrite($stream, Varint::encode(strlen($payload)));
        fwrite($stream, $payload);

        // Segment 2: clean data
        $writer2 = new BinaryTraceWriter($stream, 10000);
        $writer2->writeHeader();
        $writer2->writeTrace($trace);
        $writer2->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        // First good sample + second segment's sample
        $this->assertCount(2, $results);
    }

    public function testRecoverGarbageBeforeFirstSegment(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // Garbage before the real data
        fwrite($stream, str_repeat("\xAB\xCD\xEF", 30));

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();
        $writer->writeTrace($trace);
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertSame('func', $results[0]->trace->call_frames[0]->function_name);
    }

    public function testRecoverCompletelyCorruptedReturnsEmpty(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        fwrite($stream, str_repeat("\xAB\xCD", 100));

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        $this->assertCount(0, $results);
    }

    public function testRecoverEmptyStream(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        $this->assertCount(0, $results);
    }

    public function testRecoverBrokenPayloadLength(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // Segment 1: good sample then broken payload_length (truncated varint)
        $writer1 = new BinaryTraceWriter($stream, 10000);
        $writer1->writeHeader();
        $writer1->writeTrace($trace);
        unset($writer1);

        // Broken varint: continuation bit set but no more data before next segment
        fwrite($stream, chr(EventType::FRAME_DEF->value));
        fwrite($stream, "\x80"); // continuation bit set, truncated

        // Segment 2
        $writer2 = new BinaryTraceWriter($stream, 10000);
        $writer2->writeHeader();
        $writer2->writeTrace($trace);
        unset($writer2);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        $this->assertGreaterThanOrEqual(1, count($results));
    }
}
