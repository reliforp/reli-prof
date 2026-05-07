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

class VarDumpFormatterTest extends TestCase
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

    public function testLong(): void
    {
        $v = new VariableValue(VariableValue::TYPE_LONG, 42, null);
        $this->assertSame('int(42)', VarDumpFormatter::format($v));
    }

    public function testFloatWholeNumberDropsDecimals(): void
    {
        $v = new VariableValue(VariableValue::TYPE_DOUBLE, 1.0, null);
        $this->assertSame('float(1)', VarDumpFormatter::format($v));
    }

    public function testFloatFractional(): void
    {
        $v = new VariableValue(VariableValue::TYPE_DOUBLE, 3.14, null);
        $this->assertSame('float(3.14)', VarDumpFormatter::format($v));
    }

    public function testStringEmitsByteLength(): void
    {
        $this->assertSame('string(3) "abc"', VarDumpFormatter::format($this->string('abc')));
    }

    public function testStringTruncatedShowsOriginalLength(): void
    {
        $v = new VariableValue(
            VariableValue::TYPE_STRING,
            'abc',
            null,
            null,
            null,
            null,
            false,
            true,
            10,
        );
        $this->assertSame('string(10) "abc..."', VarDumpFormatter::format($v));
    }

    public function testBoolTrue(): void
    {
        $v = new VariableValue(VariableValue::TYPE_BOOL, true, null);
        $this->assertSame('bool(true)', VarDumpFormatter::format($v));
    }

    public function testBoolFalse(): void
    {
        $v = new VariableValue(VariableValue::TYPE_BOOL, false, null);
        $this->assertSame('bool(false)', VarDumpFormatter::format($v));
    }

    public function testNull(): void
    {
        $v = new VariableValue(VariableValue::TYPE_NULL, null, null);
        $this->assertSame('NULL', VarDumpFormatter::format($v));
    }

    public function testFlatArrayMatchesNative(): void
    {
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, 2, [
            ['a', new VariableValue(VariableValue::TYPE_LONG, 1, null)],
            ['b', $this->string('x')],
        ]);
        ob_start();
        var_dump(['a' => 1, 'b' => 'x']);
        $native = rtrim((string)ob_get_clean(), "\n");
        $this->assertSame($native, VarDumpFormatter::format($v));
    }

    public function testNestedArrayMatchesNative(): void
    {
        $inner = new VariableValue(VariableValue::TYPE_ARRAY, null, 2, [
            [0, new VariableValue(VariableValue::TYPE_LONG, 10, null)],
            [1, new VariableValue(VariableValue::TYPE_LONG, 20, null)],
        ]);
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, 2, [
            ['a', new VariableValue(VariableValue::TYPE_LONG, 1, null)],
            ['b', $inner],
        ]);
        ob_start();
        var_dump(['a' => 1, 'b' => [10, 20]]);
        $native = rtrim((string)ob_get_clean(), "\n");
        $this->assertSame($native, VarDumpFormatter::format($v));
    }

    public function testObjectWithVisibility(): void
    {
        $obj = new VariableValue(
            VariableValue::TYPE_OBJECT,
            null,
            2,
            [
                ['pub', new VariableValue(VariableValue::TYPE_LONG, 1, null)],
                ["\0Foo\0priv", $this->string('secret')],
            ],
            'Foo',
            42,
        );
        $expected =
            "object(Foo)#42 (2) {\n"
            . "  [\"pub\"]=>\n"
            . "  int(1)\n"
            . "  [\"priv\":\"Foo\":private]=>\n"
            . "  string(6) \"secret\"\n"
            . "}";
        $this->assertSame($expected, VarDumpFormatter::format($obj));
    }

    public function testRecursionMarker(): void
    {
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, 1, [
            [0, new VariableValue(VariableValue::TYPE_RECURSION, null, null)],
        ]);
        $this->assertSame(
            "array(1) {\n  [0]=>\n  *RECURSION*\n}",
            VarDumpFormatter::format($v),
        );
    }

    public function testDepthLimitedShallowArray(): void
    {
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, 5, null);
        $this->assertSame(
            "array(5) {\n  ...\n}",
            VarDumpFormatter::format($v),
        );
    }

    public function testElementsTruncatedAddsComment(): void
    {
        $v = new VariableValue(
            VariableValue::TYPE_ARRAY,
            null,
            10,
            [[0, new VariableValue(VariableValue::TYPE_LONG, 1, null)]],
            null,
            null,
            true,
        );
        $this->assertStringContainsString(
            '// ... (truncated)',
            VarDumpFormatter::format($v),
        );
    }
}
