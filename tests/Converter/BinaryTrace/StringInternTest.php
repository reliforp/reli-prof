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

use PHPUnit\Framework\Attributes\DataProvider;
use Reli\BaseTestCase;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;

final class StringInternTest extends BaseTestCase
{
    /**
     * @return iterable<string, array{string, array{string, string, string}}>
     */
    public static function decomposeFunctionNameProvider(): iterable
    {
        yield 'namespaced class method' => [
            'App\\Http\\Controllers\\UserController::index',
            ['App\\Http\\Controllers', 'UserController', 'index'],
        ];
        yield 'class method no namespace' => [
            'MyClass::myMethod',
            ['', 'MyClass', 'myMethod'],
        ];
        yield 'plain function' => [
            'array_map',
            ['', '', 'array_map'],
        ];
        yield 'namespaced function' => [
            'App\\Helpers\\format_date',
            ['App\\Helpers', '', 'format_date'],
        ];
        yield 'deeply namespaced' => [
            'Vendor\\Package\\Sub\\Deep\\Service::run',
            ['Vendor\\Package\\Sub\\Deep', 'Service', 'run'],
        ];
        yield 'closure' => [
            '{closure}',
            ['', '', '{closure}'],
        ];
        yield 'empty string' => [
            '',
            ['', '', ''],
        ];
        yield 'main' => [
            'main',
            ['', '', 'main'],
        ];
    }

    /**
     * @param array{string, string, string} $expected
     */
    #[DataProvider('decomposeFunctionNameProvider')]
    public function testDecomposeFunctionName(string $input, array $expected): void
    {
        $this->assertSame($expected, BinaryTraceWriter::decomposeFunctionName($input));
    }

    public function testRoundTripPreservesFunctionNames(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        $frames = [
            'App\\Http\\Controllers\\UserController::index',
            'App\\Http\\Controllers\\OrderController::show',
            'App\\Services\\UserService::find',
            'array_map',
            'main',
        ];

        foreach ($frames as $func) {
            $trace = new ParsedCallTrace(
                new ParsedCallFrame($func, '/app/src/' . basename($func) . '.php', 42),
            );
            $writer->writeTrace($trace);
        }
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(5, $results);
        foreach ($results as $i => $sample) {
            $this->assertSame(
                $frames[$i],
                $sample->trace->call_frames[0]->function_name,
                "Frame {$i} function_name mismatch"
            );
        }
    }

    public function testStringDeduplication(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();

        // Same namespace, same file, different methods
        $ns = 'App\\Controllers';
        $file1 = '/app/Controllers/UserController.php';
        $file2 = '/app/Controllers/OrderController.php';
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame("{$ns}\\UserController::index", $file1, 10),
            ),
            new ParsedCallTrace(
                new ParsedCallFrame("{$ns}\\UserController::show", $file1, 20),
            ),
            new ParsedCallTrace(
                new ParsedCallFrame("{$ns}\\OrderController::list", $file2, 30),
            ),
        ];

        foreach ($traces as $t) {
            $writer->writeTrace($t);
        }
        unset($writer);

        $size_with_intern = ftell($stream);
        rewind($stream);

        // Verify round-trip
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(3, $results);
        $this->assertSame('App\\Controllers\\UserController::index', $results[0]->trace->call_frames[0]->function_name);
        $this->assertSame('App\\Controllers\\UserController::show', $results[1]->trace->call_frames[0]->function_name);
        $this->assertSame('App\\Controllers\\OrderController::list', $results[2]->trace->call_frames[0]->function_name);

        // "App\Controllers" string is shared across all 3 frames
        // "/app/Controllers/UserController.php" is shared between frame 0 and 1
        // So interning should save space vs repeating full strings
        // Just verify it's reasonably small
        $this->assertLessThan(300, $size_with_intern, 'String interning should keep definitions compact');
    }

    public function testSegmentReEmissionBenefitsFromStringTable(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $traces = [];
        for ($i = 0; $i < 5; $i++) {
            $traces[] = new ParsedCallTrace(
                new ParsedCallFrame(
                    "App\\Http\\Controllers\\Controller{$i}::handle",
                    "/app/src/Controllers/Controller{$i}.php",
                    10 + $i,
                ),
            );
        }

        // Segment 1
        $w1 = new BinaryTraceWriter($stream, 10000);
        $w1->writeHeader();
        foreach ($traces as $t) {
            $w1->writeTrace($t);
        }
        $w1->writeSegmentEnd();
        $segment1_end = ftell($stream);

        // Segment 2 — same traces, fresh writer
        $w2 = new BinaryTraceWriter($stream, 10000);
        $w2->writeHeader();
        foreach ($traces as $t) {
            $w2->writeTrace($t);
        }
        $w2->writeSegmentEnd();
        $segment2_end = ftell($stream);

        rewind($stream);

        // Verify both segments read correctly
        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(10, $results);
        $this->assertSame(
            'App\\Http\\Controllers\\Controller0::handle',
            $results[0]->trace->call_frames[0]->function_name,
        );
        $this->assertSame(
            'App\\Http\\Controllers\\Controller0::handle',
            $results[5]->trace->call_frames[0]->function_name,
        );

        // Both segments should be roughly the same size (defs re-emitted)
        $seg1_size = $segment1_end;
        $seg2_size = $segment2_end - $segment1_end;
        // Segment sizes should be close (within 10%)
        $this->assertLessThan($seg1_size * 1.1, $seg2_size);
    }
}
