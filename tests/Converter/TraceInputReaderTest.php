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
use Reli\Converter\BinaryTrace\BinaryTraceWriter;

final class TraceInputReaderTest extends BaseTestCase
{
    public function testReadPhpspyText(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, "0 func /file.php:1\n1 main /index.php:5\n\n");
        rewind($stream);

        $reader = new TraceInputReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertSame('func', $results[0]->call_frames[0]->function_name);
        $this->assertSame('main', $results[0]->call_frames[1]->function_name);
        $this->assertNull($reader->getBinaryReader());
    }

    public function testReadRbt(): void
    {
        $rbt_stream = fopen('php://memory', 'r+');
        assert($rbt_stream !== false);
        $writer = new BinaryTraceWriter($rbt_stream, 10000);
        $writer->writeHeader();
        $writer->writeTrace(new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        ));
        $writer->writeSegmentEnd();
        rewind($rbt_stream);

        $reader = new TraceInputReader();
        $results = iterator_to_array($reader->read($rbt_stream));
        fclose($rbt_stream);

        $this->assertCount(1, $results);
        $this->assertSame('func', $results[0]->call_frames[0]->function_name);
        $this->assertNotNull($reader->getBinaryReader());
    }

    public function testReadGzippedRbt(): void
    {
        // Create raw rbt
        $raw_stream = fopen('php://memory', 'r+');
        assert($raw_stream !== false);
        $writer = new BinaryTraceWriter($raw_stream, 10000);
        $writer->writeHeader();
        $writer->writeTrace(new ParsedCallTrace(
            new ParsedCallFrame('func', '/file.php', 1),
        ));
        $writer->writeSegmentEnd();
        rewind($raw_stream);
        $raw = stream_get_contents($raw_stream);
        fclose($raw_stream);
        assert($raw !== false);

        // Gzip it
        $compressed = gzencode($raw);
        assert($compressed !== false);
        $gz_stream = fopen('php://memory', 'r+');
        assert($gz_stream !== false);
        fwrite($gz_stream, $compressed);
        rewind($gz_stream);

        $reader = new TraceInputReader();
        $results = iterator_to_array($reader->read($gz_stream));
        fclose($gz_stream);

        $this->assertCount(1, $results);
        $this->assertSame('func', $results[0]->call_frames[0]->function_name);
    }

    public function testReadGzippedPhpspyText(): void
    {
        $text = "0 func /file.php:1\n1 main /index.php:5\n\n";
        $compressed = gzencode($text);
        assert($compressed !== false);

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $compressed);
        rewind($stream);

        $reader = new TraceInputReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertSame('func', $results[0]->call_frames[0]->function_name);
    }

    public function testReadEmptyStream(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $reader = new TraceInputReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(0, $results);
    }

    public function testReadTooShortStream(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, 'ab'); // 2 bytes — not gzip, not enough for magic
        rewind($stream);

        $reader = new TraceInputReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(0, $results);
    }
}
