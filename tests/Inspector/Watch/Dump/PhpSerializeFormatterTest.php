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

namespace Reli\Inspector\Watch\Dump;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Watch\VariableValue;

class PhpSerializeFormatterTest extends TestCase
{
    private function string(string $s): VariableValue
    {
        return new VariableValue(
            VariableValue::TYPE_STRING,
            $s,
            null,
            null,
            null,
            null,
            false,
            false,
            strlen($s),
        );
    }

    public function testScalars(): void
    {
        $this->assertSame(
            'i:42;',
            PhpSerializeFormatter::format(new VariableValue(VariableValue::TYPE_LONG, 42, null)),
        );
        $this->assertSame(
            'd:3.14;',
            PhpSerializeFormatter::format(new VariableValue(VariableValue::TYPE_DOUBLE, 3.14, null)),
        );
        $this->assertSame(
            's:5:"hello";',
            PhpSerializeFormatter::format($this->string('hello')),
        );
        $this->assertSame(
            'b:1;',
            PhpSerializeFormatter::format(new VariableValue(VariableValue::TYPE_BOOL, true, null)),
        );
        $this->assertSame(
            'b:0;',
            PhpSerializeFormatter::format(new VariableValue(VariableValue::TYPE_BOOL, false, null)),
        );
        $this->assertSame(
            'N;',
            PhpSerializeFormatter::format(new VariableValue(VariableValue::TYPE_NULL, null, null)),
        );
    }

    public function testNanAndInfinity(): void
    {
        $this->assertSame(
            'd:NAN;',
            PhpSerializeFormatter::format(new VariableValue(VariableValue::TYPE_DOUBLE, NAN, null)),
        );
        $this->assertSame(
            'd:INF;',
            PhpSerializeFormatter::format(new VariableValue(VariableValue::TYPE_DOUBLE, INF, null)),
        );
        $this->assertSame(
            'd:-INF;',
            PhpSerializeFormatter::format(new VariableValue(VariableValue::TYPE_DOUBLE, -INF, null)),
        );
    }

    public function testArrayMatchesNative(): void
    {
        $inner = new VariableValue(VariableValue::TYPE_ARRAY, null, 2, [
            [0, new VariableValue(VariableValue::TYPE_LONG, 10, null)],
            [1, new VariableValue(VariableValue::TYPE_LONG, 20, null)],
        ]);
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, 2, [
            ['a', new VariableValue(VariableValue::TYPE_LONG, 1, null)],
            ['b', $inner],
        ]);
        $this->assertSame(
            serialize(['a' => 1, 'b' => [10, 20]]),
            PhpSerializeFormatter::format($v),
        );
    }

    public function testObjectIncludesNulPrefixedPropertyNames(): void
    {
        $obj = new VariableValue(
            VariableValue::TYPE_OBJECT,
            null,
            1,
            [["\0Foo\0priv", $this->string('s')]],
            'Foo',
            1,
        );
        $this->assertSame(
            'O:3:"Foo":1:{s:9:"' . "\0Foo\0priv" . '";s:1:"s";}',
            PhpSerializeFormatter::format($obj),
        );
    }

    public function testObjectSelfCycleEmitsLowercaseR(): void
    {
        // class Node { public ?Node $next = null; } $n = new Node;
        // $n->next = $n; serialize($n)
        // → O:4:"Node":1:{s:4:"next";r:1;}
        $obj_addr = 0xDEAD;
        $cycle = new VariableValue(
            VariableValue::TYPE_OBJECT,
            null,
            1,
            [['next', new VariableValue(
                VariableValue::TYPE_RECURSION,
                null,
                null,
                null,
                null,
                1,
                false,
                false,
                null,
                $obj_addr,
            )]],
            'Node',
            1,
            false,
            false,
            null,
            $obj_addr,
        );
        $this->assertSame(
            'O:4:"Node":1:{s:4:"next";r:1;}',
            PhpSerializeFormatter::format($cycle),
        );
    }

    public function testSharedObjectAcrossSiblingsEmitsBackEdge(): void
    {
        // $o = new stdClass; $arr = [$o, $o];
        // → a:2:{i:0;O:8:"stdClass":0:{}i:1;r:2;}
        $shared = new VariableValue(
            VariableValue::TYPE_OBJECT,
            null,
            0,
            [],
            'stdClass',
            1,
            false,
            false,
            null,
            0xCAFE,
        );
        $arr = new VariableValue(
            VariableValue::TYPE_ARRAY,
            null,
            2,
            [[0, $shared], [1, $shared]],
            null,
            null,
            false,
            false,
            null,
            0xBEEF,
        );
        $this->assertSame(
            'a:2:{i:0;O:8:"stdClass":0:{}i:1;r:2;}',
            PhpSerializeFormatter::format($arr),
        );
    }

    public function testArrayCycleEmitsUppercaseR(): void
    {
        // $a = []; $a[] = &$a; the cycle is the only kind of array
        // back-edge that exists in PHP (arrays are otherwise by-value).
        $arr_addr = 0xF00D;
        $arr_cycle = new VariableValue(
            VariableValue::TYPE_ARRAY,
            null,
            1,
            [[0, new VariableValue(
                VariableValue::TYPE_RECURSION,
                null,
                null,
                null,
                null,
                null,
                false,
                false,
                null,
                $arr_addr,
            )]],
            null,
            null,
            false,
            false,
            null,
            $arr_addr,
        );
        // Shorter than native (`a:1:{i:0;a:1:{i:0;R:2;}}`) because we
        // can't see the zend_reference wrapper, but the output still
        // round-trips through unserialize as a self-cyclic array.
        $serialized = PhpSerializeFormatter::format($arr_cycle);
        $this->assertSame('a:1:{i:0;R:1;}', $serialized);
        $revived = unserialize($serialized);
        $this->assertIsArray($revived);
        $this->assertSame($revived, $revived[0]);
    }

    public function testObjectCycleRoundTripsThroughUnserialize(): void
    {
        $obj_addr = 0x1234;
        $cycle = new VariableValue(
            VariableValue::TYPE_OBJECT,
            null,
            1,
            [['self', new VariableValue(
                VariableValue::TYPE_RECURSION,
                null,
                null,
                null,
                null,
                1,
                false,
                false,
                null,
                $obj_addr,
            )]],
            'stdClass',
            1,
            false,
            false,
            null,
            $obj_addr,
        );
        $revived = unserialize(PhpSerializeFormatter::format($cycle));
        $this->assertInstanceOf(\stdClass::class, $revived);
        $this->assertSame($revived, $revived->self);
    }

    public function testCounterAdvancesForScalarsBetweenObjects(): void
    {
        // serialize(['a', $o, 'b', $o]) →
        // a:4:{i:0;s:1:"a";i:1;O:8:"stdClass":0:{}i:2;s:1:"b";i:3;r:3;}
        // Each scalar bumps the counter, so the second $o resolves to id 3
        // even though there are intervening strings.
        $shared = new VariableValue(
            VariableValue::TYPE_OBJECT,
            null,
            0,
            [],
            'stdClass',
            1,
            false,
            false,
            null,
            0x1234,
        );
        $arr = new VariableValue(
            VariableValue::TYPE_ARRAY,
            null,
            4,
            [
                [0, $this->string('a')],
                [1, $shared],
                [2, $this->string('b')],
                [3, $shared],
            ],
            null,
            null,
            false,
            false,
            null,
            0x5678,
        );
        $this->assertSame(
            'a:4:{i:0;s:1:"a";i:1;O:8:"stdClass":0:{}i:2;s:1:"b";i:3;r:3;}',
            PhpSerializeFormatter::format($arr),
        );
    }

    public function testRecursionWithUnknownAddressFallsBackToNull(): void
    {
        // The reader can drop a cycle target if the target sat below a
        // depth budget — no address means we can't emit r/R, so we
        // fall back to N; (still parseable).
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, 1, [
            [0, new VariableValue(VariableValue::TYPE_RECURSION, null, null)],
        ]);
        $this->assertSame('a:1:{i:0;N;}', PhpSerializeFormatter::format($v));
    }

    public function testTruncatedArrayHeaderMatchesEmittedCount(): void
    {
        // 10 elements claimed, only 1 emitted
        $v = new VariableValue(
            VariableValue::TYPE_ARRAY,
            null,
            10,
            [[0, new VariableValue(VariableValue::TYPE_LONG, 1, null)]],
            null,
            null,
            true,
        );
        // Header reports the emitted count (1) so unserialize() doesn't trip.
        $this->assertSame('a:1:{i:0;i:1;}', PhpSerializeFormatter::format($v));
    }
}
