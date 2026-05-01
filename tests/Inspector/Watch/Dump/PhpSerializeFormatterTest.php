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

    public function testRecursionDegradesToNull(): void
    {
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
