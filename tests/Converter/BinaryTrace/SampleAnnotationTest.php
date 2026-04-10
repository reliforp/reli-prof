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

final class SampleAnnotationTest extends BaseTestCase
{
    public function testAnnotationRoundTrip(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('PDO::execute', '/app/db.php', 42),
        );
        $writer->writeTrace($trace);
        $writer->writeSampleAnnotation([
            'query' => 'SELECT * FROM users WHERE id = ?',
            'db.system' => 'mysql',
        ]);
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]->annotations);
        $this->assertSame(
            'SELECT * FROM users WHERE id = ?',
            $results[0]->annotations['query'],
        );
        $this->assertSame('mysql', $results[0]->annotations['db.system']);
    }

    public function testSampleWithoutAnnotation(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]->annotations);
    }

    public function testMixedAnnotatedAndUnannotated(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $writer->writeHeader();

        $traceA = new ParsedCallTrace(
            new ParsedCallFrame('PDO::execute', '/db.php', 10),
        );
        $traceB = new ParsedCallTrace(
            new ParsedCallFrame('main', '/index.php', 1),
        );

        // Sample 1: annotated
        $writer->writeTrace($traceA, 0);
        $writer->writeSampleAnnotation(['query' => 'SELECT 1']);

        // Sample 2: not annotated
        $writer->writeTrace($traceB, 10000);

        // Sample 3: annotated
        $writer->writeTrace($traceA, 10000);
        $writer->writeSampleAnnotation(['query' => 'INSERT INTO logs']);

        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
        $this->assertSame('SELECT 1', $results[0]->annotations['query']);
        $this->assertNull($results[1]->annotations);
        $this->assertSame('INSERT INTO logs', $results[2]->annotations['query']);
    }

    public function testAnnotationStringInterning(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // Same key "query" used twice — should be interned
        $writer->writeTrace($trace);
        $writer->writeSampleAnnotation(['query' => 'SELECT 1']);
        $writer->writeTrace($trace);
        $writer->writeSampleAnnotation(['query' => 'SELECT 2']);
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(2, $results);
        $this->assertSame('SELECT 1', $results[0]->annotations['query']);
        $this->assertSame('SELECT 2', $results[1]->annotations['query']);
    }

    public function testAnnotationWithCompactSample(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // No timestamps → compact samples
        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('PDO::execute', '/db.php', 10),
        );
        $writer->writeTrace($trace);
        $writer->flushPendingRun();
        $writer->writeSampleAnnotation(['query' => 'SELECT 1']);
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertSame('SELECT 1', $results[0]->annotations['query']);
    }

    public function testEmptyAnnotationIsSkipped(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        $writer->writeSampleAnnotation([]); // empty — should be no-op
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]->annotations);
    }

    public function testAnnotatedSampleRepeat(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('PDO::execute', '/db.php', 10),
        );

        // 5 identical annotated samples → COMPACT + ANNOTATION + REPEAT(4)
        for ($i = 0; $i < 5; $i++) {
            $writer->writeAnnotatedTrace($trace, annotations: [
                'query' => 'SELECT * FROM users WHERE id = ?',
            ]);
        }
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(5, $results);
        foreach ($results as $i => $sample) {
            $this->assertSame(
                'SELECT * FROM users WHERE id = ?',
                $sample->annotations['query'] ?? null,
                "Sample {$i} should have query annotation",
            );
        }
    }

    public function testAnnotationChangeBreaksRun(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('PDO::execute', '/db.php', 10),
        );

        // Same stack, different annotations → run broken
        $writer->writeAnnotatedTrace($trace, annotations: ['query' => 'SELECT 1']);
        $writer->writeAnnotatedTrace($trace, annotations: ['query' => 'SELECT 1']);
        $writer->writeAnnotatedTrace($trace, annotations: ['query' => 'SELECT 1']);
        $writer->writeAnnotatedTrace($trace, annotations: ['query' => 'SELECT 2']);
        $writer->writeAnnotatedTrace($trace, annotations: ['query' => 'SELECT 2']);
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(5, $results);
        $this->assertSame('SELECT 1', $results[0]->annotations['query']);
        $this->assertSame('SELECT 1', $results[2]->annotations['query']);
        $this->assertSame('SELECT 2', $results[3]->annotations['query']);
        $this->assertSame('SELECT 2', $results[4]->annotations['query']);
    }

    public function testMixedAnnotatedAndUnannotatedRuns(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000, has_timestamps: false);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );

        // No annotation → annotation → no annotation
        $writer->writeAnnotatedTrace($trace);
        $writer->writeAnnotatedTrace($trace);
        $writer->writeAnnotatedTrace($trace);
        $writer->writeAnnotatedTrace($trace, annotations: ['key' => 'val']);
        $writer->writeAnnotatedTrace($trace, annotations: ['key' => 'val']);
        $writer->writeAnnotatedTrace($trace);
        $writer->writeSegmentEnd();

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(6, $results);
        $this->assertNull($results[0]->annotations);
        $this->assertNull($results[2]->annotations);
        $this->assertSame('val', $results[3]->annotations['key']);
        $this->assertSame('val', $results[4]->annotations['key']);
        $this->assertNull($results[5]->annotations);
    }

    public function testAnnotatedRepeatSmallerThanIndividual(): void
    {
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('PDO::execute', '/db.php', 10),
        );
        $annotations = ['query' => 'SELECT * FROM users'];
        $count = 100;

        // With RLE
        $stream_rle = fopen('php://memory', 'r+');
        assert($stream_rle !== false);
        $writer = new BinaryTraceWriter($stream_rle, 10000, has_timestamps: false);
        $writer->writeHeader();
        for ($i = 0; $i < $count; $i++) {
            $writer->writeAnnotatedTrace($trace, annotations: $annotations);
        }
        $writer->writeSegmentEnd();
        $size_rle = ftell($stream_rle);
        fclose($stream_rle);

        // Without RLE (timestamped, each sample individual)
        $stream_ts = fopen('php://memory', 'r+');
        assert($stream_ts !== false);
        $writer_ts = new BinaryTraceWriter($stream_ts, 10000, has_timestamps: true);
        $writer_ts->writeHeader();
        for ($i = 0; $i < $count; $i++) {
            $writer_ts->writeAnnotatedTrace($trace, annotations: $annotations);
        }
        $writer_ts->writeSegmentEnd();
        $size_ts = ftell($stream_ts);
        fclose($stream_ts);

        $this->assertLessThan($size_ts, $size_rle);
    }

    public function testOrphanAnnotationInRecovery(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        // Write a valid sample first
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        );
        $writer->writeTrace($trace);
        $writer->writeSegmentEnd();

        // Segment 2: orphan annotation (no preceding sample)
        $writer2 = new BinaryTraceWriter($stream, 10000);
        $writer2->writeHeader();
        // Manually inject SAMPLE_ANNOTATION without sample
        $payload = Varint::encode(1)
            . Varint::encode(0) . Varint::encode(0); // dummy string ids
        fwrite($stream, chr(EventType::SAMPLE_ANNOTATION->value));
        fwrite($stream, Varint::encode(strlen($payload)));
        fwrite($stream, $payload);

        rewind($stream);

        $reader = new BinaryTraceReader();
        // Should recover at least segment 1
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testAnnotationSurvivesRecovery(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame('PDO::execute', '/db.php', 10),
        );
        $writer->writeTrace($trace);
        $writer->writeSampleAnnotation(['query' => 'SELECT 1']);
        $writer->writeSegmentEnd();

        // Append garbage
        fwrite($stream, "\xFF\xFF\xFF");

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->readWithRecovery($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertSame('SELECT 1', $results[0]->annotations['query']);
    }
}
