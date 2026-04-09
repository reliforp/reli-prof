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

final class NativeFrameTest extends BaseTestCase
{
    public function testNativeFrameRoundTrip(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame(
                'zend_execute_scripts',
                'libphp.so',
                0,
                frame_type: ParsedCallFrame::TYPE_NATIVE,
                module_name: 'libphp.so',
                symbol_offset: 0x1a3,
            ),
        );
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $frame = $results[0]->trace->call_frames[0];
        $this->assertTrue($frame->isNative());
        $this->assertSame('zend_execute_scripts', $frame->function_name);
        $this->assertSame('libphp.so', $frame->module_name);
        $this->assertSame(0x1a3, $frame->symbol_offset);
        $this->assertSame(0, $frame->lineno);
    }

    public function testMixedPhpAndNativeFrames(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame(
                'zend_do_fcall',
                'libphp.so',
                0,
                frame_type: ParsedCallFrame::TYPE_NATIVE,
                module_name: 'libphp.so',
                symbol_offset: 0x42,
            ),
            new ParsedCallFrame(
                'App\\Controller::index',
                '/app/Controller.php',
                42,
            ),
            new ParsedCallFrame(
                '__libc_start_main',
                'libc.so.6',
                0,
                frame_type: ParsedCallFrame::TYPE_NATIVE,
                module_name: 'libc.so.6',
                symbol_offset: 0x29d90,
            ),
        );
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $frames = $results[0]->trace->call_frames;

        $this->assertCount(3, $frames);
        $this->assertTrue($frames[0]->isNative());
        $this->assertSame('zend_do_fcall', $frames[0]->function_name);

        $this->assertFalse($frames[1]->isNative());
        $this->assertSame('App\\Controller::index', $frames[1]->function_name);

        $this->assertTrue($frames[2]->isNative());
        $this->assertSame('__libc_start_main', $frames[2]->function_name);
        $this->assertSame(0x29d90, $frames[2]->symbol_offset);
    }

    public function testOpcodeRoundTrip(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $trace = new ParsedCallTrace(
            new ParsedCallFrame(
                'App\\Service::process',
                '/app/Service.php',
                42,
                opcode_name: 'ZEND_DO_FCALL',
            ),
            new ParsedCallFrame(
                'main',
                '/app/index.php',
                10,
                // No opcode
            ),
        );
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $frames = $results[0]->trace->call_frames;

        $this->assertSame('ZEND_DO_FCALL', $frames[0]->opcode_name);
        $this->assertNull($frames[1]->opcode_name);
    }

    public function testOpcodeInterning(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        // Multiple frames with the same opcode name — should be interned
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/a.php', 1, opcode_name: 'ZEND_DO_FCALL'),
            new ParsedCallFrame('func_b', '/b.php', 2, opcode_name: 'ZEND_DO_FCALL'),
            new ParsedCallFrame('func_c', '/c.php', 3, opcode_name: 'ZEND_INIT_FCALL'),
        );
        $writer->writeTrace($trace);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $frames = $results[0]->trace->call_frames;
        $this->assertSame('ZEND_DO_FCALL', $frames[0]->opcode_name);
        $this->assertSame('ZEND_DO_FCALL', $frames[1]->opcode_name);
        $this->assertSame('ZEND_INIT_FCALL', $frames[2]->opcode_name);
    }

    public function testNativeFrameStringInterning(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        // Multiple native frames from the same module
        $trace = new ParsedCallTrace(
            new ParsedCallFrame(
                'zend_execute_scripts',
                'libphp.so',
                0,
                frame_type: ParsedCallFrame::TYPE_NATIVE,
                module_name: 'libphp.so',
                symbol_offset: 0x100,
            ),
            new ParsedCallFrame(
                'zend_execute',
                'libphp.so',
                0,
                frame_type: ParsedCallFrame::TYPE_NATIVE,
                module_name: 'libphp.so',
                symbol_offset: 0x200,
            ),
        );
        $writer->writeTrace($trace);
        unset($writer);

        $size = ftell($stream);
        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        // "libphp.so" should be interned (defined once)
        $this->assertSame('libphp.so', $results[0]->trace->call_frames[0]->module_name);
        $this->assertSame('libphp.so', $results[0]->trace->call_frames[1]->module_name);
        // Size should be compact
        $this->assertLessThan(150, $size);
    }

    public function testCallTraceConverterWithOpcode(): void
    {
        $call_trace = new \Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace(
            new \Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame(
                'MyClass',
                'myMethod',
                '/file.php',
                null, // no opline → opcode_name should be null
            ),
        );

        $parsed = CallTraceConverter::toParsed($call_trace);
        $this->assertNull($parsed->call_frames[0]->opcode_name);
        $this->assertFalse($parsed->call_frames[0]->isNative());
    }

    public function testCallTraceConverterMerged(): void
    {
        $merged = new \Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallTrace(
            \Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallFrame::fromNative(
                new \Reli\Lib\PhpProcessReader\CallTraceReader\NativeCallFrame(
                    'zend_execute',
                    'libphp.so',
                    0x42,
                    0x7f0000000042,
                ),
            ),
            \Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallFrame::fromPhp(
                new \Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame(
                    'App',
                    'run',
                    '/app.php',
                    null,
                ),
            ),
        );

        $parsed = CallTraceConverter::mergedToParsed($merged);
        $this->assertCount(2, $parsed->call_frames);

        $this->assertTrue($parsed->call_frames[0]->isNative());
        $this->assertSame('zend_execute', $parsed->call_frames[0]->function_name);
        $this->assertSame('libphp.so', $parsed->call_frames[0]->module_name);
        $this->assertSame(0x42, $parsed->call_frames[0]->symbol_offset);

        $this->assertFalse($parsed->call_frames[1]->isNative());
        $this->assertSame('App::run', $parsed->call_frames[1]->function_name);
    }
}
