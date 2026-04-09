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
