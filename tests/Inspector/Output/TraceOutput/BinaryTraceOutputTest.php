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

namespace Reli\Inspector\Output\TraceOutput;

use Reli\BaseTestCase;
use Reli\Converter\BinaryTrace\BinaryTraceReader;
use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Converter\StreamDecompressor;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\NativeCallFrame;

final class BinaryTraceOutputTest extends BaseTestCase
{
    public function testBasicOutput(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $output = new BinaryTraceOutput($writer);

        $trace = new CallTrace(
            new CallFrame('App', 'run', '/app.php', null),
        );
        $output->output($trace);
        $output->output($trace);
        $output->finish();

        rewind($stream);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(2, $results);
        $this->assertSame('App::run', $results[0]->trace->call_frames[0]->function_name);
    }

    public function testFinishWithNoSamplesIsNoop(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $output = new BinaryTraceOutput($writer);

        // Never call output(), so header_written = false
        $output->finish();

        // Stream should be empty (no header, no events)
        $this->assertSame(0, ftell($stream));
        fclose($stream);
    }

    public function testHasSamplesReflectsSamplePresence(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $output = new BinaryTraceOutput($writer);

        $this->assertFalse($output->hasSamples());

        $output->output(
            new CallTrace(
                new CallFrame('App', 'run', '/app.php', null),
            ),
        );

        $this->assertTrue($output->hasSamples());

        // finish() must not change the answer either way.
        $output->finish();
        $this->assertTrue($output->hasSamples());

        fclose($stream);
    }

    public function testFinishWithCompressProducesGzip(): void
    {
        $buffer = fopen('php://temp', 'r+');
        assert($buffer !== false);
        $dest = fopen('php://memory', 'r+');
        assert($dest !== false);

        $writer = new BinaryTraceWriter($buffer, 10000);
        $output = new BinaryTraceOutput(
            $writer,
            write_buffer: $buffer,
            compress_to: $dest,
        );

        $trace = new CallTrace(
            new CallFrame('', 'main', '/index.php', null),
        );
        $output->output($trace);
        $output->output($trace);
        $output->output($trace);
        $output->finish();

        // dest should contain gzip data
        rewind($dest);
        $magic = fread($dest, 2);
        $this->assertSame("\x1f\x8b", $magic);

        // Decompress and verify
        rewind($dest);
        $decompressed = StreamDecompressor::decompressIfNeeded($dest);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($decompressed));
        fclose($decompressed);
        fclose($dest);

        $this->assertCount(3, $results);
        $this->assertSame('main', $results[0]->trace->call_frames[0]->function_name);
    }

    public function testFinishCompressWithNoSamplesDoesNotWriteGzip(): void
    {
        $buffer = fopen('php://temp', 'r+');
        assert($buffer !== false);
        $dest = fopen('php://memory', 'r+');
        assert($dest !== false);

        $writer = new BinaryTraceWriter($buffer, 10000);
        $output = new BinaryTraceOutput(
            $writer,
            write_buffer: $buffer,
            compress_to: $dest,
        );

        $output->finish();

        // dest should be empty — no gzip output when no samples
        $this->assertSame(0, ftell($dest));
        fclose($dest);
    }

    public function testOutputMergedWithNativeFrames(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $output = new BinaryTraceOutput($writer);

        $merged = new MergedCallTrace(
            MergedCallFrame::fromNative(
                new NativeCallFrame('zend_execute', 'libphp.so', 0x42, 0x7f00),
            ),
            MergedCallFrame::fromPhp(
                new CallFrame('App', 'run', '/app.php', null),
            ),
        );
        $output->outputMerged($merged);
        $output->finish();

        rewind($stream);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $frames = $results[0]->trace->call_frames;
        $this->assertCount(2, $frames);
        $this->assertTrue($frames[0]->isNative());
        $this->assertSame('zend_execute', $frames[0]->function_name);
        $this->assertFalse($frames[1]->isNative());
        $this->assertSame('App::run', $frames[1]->function_name);
        $this->assertNull($results[0]->annotations);
    }

    public function testOutputMergedWithAnnotationsRoundTrips(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $output = new BinaryTraceOutput($writer);

        $merged = new MergedCallTrace(
            MergedCallFrame::fromNative(
                new NativeCallFrame('zend_execute', 'libphp.so', 0x42, 0x7f00),
            ),
            MergedCallFrame::fromPhp(
                new CallFrame('App', 'run', '/app.php', null),
            ),
        );
        $output->outputMerged(
            $merged,
            [
                'global::$counter' => '(int) 42',
                'local::App::run()$id' => '(string) "abc"',
            ],
        );
        $output->finish();

        rewind($stream);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        // Native + PHP frames both preserved.
        $frames = $results[0]->trace->call_frames;
        $this->assertCount(2, $frames);
        $this->assertTrue($frames[0]->isNative());
        $this->assertFalse($frames[1]->isNative());
        // And the annotations round-trip.
        $this->assertSame(
            [
                'global::$counter' => '(int) 42',
                'local::App::run()$id' => '(string) "abc"',
            ],
            $results[0]->annotations,
        );
    }

    public function testOutputWithAnnotationsRoundTrips(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $output = new BinaryTraceOutput($writer);

        $trace = new CallTrace(
            new CallFrame('App', 'run', '/app.php', null),
        );
        $output->output(
            $trace,
            [
                'global::$counter' => '(int) 42',
                'local::App::run()$id' => '(string) "abc"',
            ],
        );
        $output->finish();

        rewind($stream);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'global::$counter' => '(int) 42',
                'local::App::run()$id' => '(string) "abc"',
            ],
            $results[0]->annotations,
        );
    }

    public function testOutputWithNullAnnotationsEmitsNoAnnotationEvent(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $output = new BinaryTraceOutput($writer);

        $trace = new CallTrace(
            new CallFrame('App', 'run', '/app.php', null),
        );
        $output->output($trace, null);
        $output->finish();

        rewind($stream);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]->annotations);
    }

    public function testCheckpointInterval(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        // Very low checkpoint interval to trigger it
        $writer = new BinaryTraceWriter($stream, 10000);
        $output = new BinaryTraceOutput($writer, checkpoint_interval: 2);

        $trace = new CallTrace(
            new CallFrame('', 'func', '/file.php', null),
        );
        $output->output($trace);
        $output->output($trace); // triggers checkpoint
        $output->output($trace);
        $output->finish();

        rewind($stream);
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
    }
}
