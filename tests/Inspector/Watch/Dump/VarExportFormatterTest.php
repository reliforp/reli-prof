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

class VarExportFormatterTest extends TestCase
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
        $this->assertSame('42', VarExportFormatter::format($v));
    }

    public function testFloatWholeNumberKeepsZero(): void
    {
        $v = new VariableValue(VariableValue::TYPE_DOUBLE, 1.0, null);
        $this->assertSame('1.0', VarExportFormatter::format($v));
    }

    public function testStringSingleQuoted(): void
    {
        $this->assertSame("'abc'", VarExportFormatter::format($this->string('abc')));
    }

    public function testStringEscapesSingleQuoteAndBackslash(): void
    {
        $this->assertSame(
            "'a\\\\b\\'c'",
            VarExportFormatter::format($this->string("a\\b'c")),
        );
    }

    public function testBoolNull(): void
    {
        $this->assertSame(
            'true',
            VarExportFormatter::format(new VariableValue(VariableValue::TYPE_BOOL, true, null)),
        );
        $this->assertSame(
            'NULL',
            VarExportFormatter::format(new VariableValue(VariableValue::TYPE_NULL, null, null)),
        );
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
        $native = var_export(['a' => 1, 'b' => [10, 20]], true);
        $this->assertSame($native, VarExportFormatter::format($v));
    }

    public function testRecursionEmitsComment(): void
    {
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, 1, [
            [0, new VariableValue(VariableValue::TYPE_RECURSION, null, null)],
        ]);
        $this->assertSame(
            "array (\n  0 => /* RECURSION */,\n)",
            VarExportFormatter::format($v),
        );
    }

    public function testObjectAsSetState(): void
    {
        $obj = new VariableValue(
            VariableValue::TYPE_OBJECT,
            null,
            1,
            [['x', new VariableValue(VariableValue::TYPE_LONG, 1, null)]],
            'Foo',
            7,
        );
        $this->assertSame(
            "\\Foo::__set_state(array(\n  'x' => 1,\n))",
            VarExportFormatter::format($obj),
        );
    }

    public function testObjectStripsVisibilityMarker(): void
    {
        $obj = new VariableValue(
            VariableValue::TYPE_OBJECT,
            null,
            1,
            [["\0Foo\0secret", new VariableValue(VariableValue::TYPE_LONG, 1, null)]],
            'Foo',
            7,
        );
        $this->assertStringContainsString(
            "'secret' => 1",
            VarExportFormatter::format($obj),
        );
    }
}
