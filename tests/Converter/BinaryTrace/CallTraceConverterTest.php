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
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class CallTraceConverterTest extends BaseTestCase
{
    public function testNullOplineProducesLinenoZero(): void
    {
        $call_trace = new CallTrace(
            new CallFrame('MyClass', 'myMethod', '/file.php', null),
        );

        $parsed = CallTraceConverter::toParsed($call_trace);

        $this->assertCount(1, $parsed->call_frames);
        $this->assertSame('MyClass::myMethod', $parsed->call_frames[0]->function_name);
        $this->assertSame('/file.php', $parsed->call_frames[0]->file_name);
        $this->assertSame(0, $parsed->call_frames[0]->lineno);
    }

    public function testNullOplineDoesNotTriggerVarintAssert(): void
    {
        $call_trace = new CallTrace(
            new CallFrame('', 'main', '/index.php', null),
        );

        $parsed = CallTraceConverter::toParsed($call_trace);

        // This should not throw: Varint::encode(0) is valid
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);

        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();
        $writer->writeTrace($parsed);
        unset($writer);

        rewind($stream);

        $reader = new BinaryTraceReader();
        $results = iterator_to_array($reader->read($stream));
        fclose($stream);

        $this->assertCount(1, $results);
        $this->assertSame(0, $results[0]->trace->call_frames[0]->lineno);
    }
}
