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

namespace Reli\Inspector\Watch;

use PHPUnit\Framework\TestCase;

class VariableValueFormatterTest extends TestCase
{
    public function testLong(): void
    {
        $v = new VariableValue(VariableValue::TYPE_LONG, 42, null);
        $this->assertSame('(int) 42', VariableValueFormatter::format($v));
    }

    public function testLongNegative(): void
    {
        $v = new VariableValue(VariableValue::TYPE_LONG, -1, null);
        $this->assertSame('(int) -1', VariableValueFormatter::format($v));
    }

    public function testDouble(): void
    {
        $v = new VariableValue(VariableValue::TYPE_DOUBLE, 3.14, null);
        $this->assertSame('(float) 3.14', VariableValueFormatter::format($v));
    }

    public function testDoubleInteger(): void
    {
        $v = new VariableValue(VariableValue::TYPE_DOUBLE, 1.0, null);
        $this->assertSame('(float) 1', VariableValueFormatter::format($v));
    }

    public function testString(): void
    {
        $v = new VariableValue(VariableValue::TYPE_STRING, 'hello', null);
        $this->assertSame('(string) "hello"', VariableValueFormatter::format($v));
    }

    public function testStringEmpty(): void
    {
        $v = new VariableValue(VariableValue::TYPE_STRING, '', null);
        $this->assertSame('(string) ""', VariableValueFormatter::format($v));
    }

    public function testStringWithDoubleQuote(): void
    {
        $v = new VariableValue(VariableValue::TYPE_STRING, 'say "hi"', null);
        $this->assertSame('(string) "say \\"hi\\""', VariableValueFormatter::format($v));
    }

    public function testStringWithBackslash(): void
    {
        $v = new VariableValue(VariableValue::TYPE_STRING, 'a\\b', null);
        $this->assertSame('(string) "a\\\\b"', VariableValueFormatter::format($v));
    }

    public function testStringWithNewline(): void
    {
        // JSON encoding turns \n into a backslash-n escape so the output
        // stays on a single line — critical for the phpspy comment format.
        $v = new VariableValue(VariableValue::TYPE_STRING, "line1\nline2", null);
        $formatted = VariableValueFormatter::format($v);
        $this->assertSame('(string) "line1\\nline2"', $formatted);
        $this->assertStringNotContainsString("\n", $formatted);
    }

    public function testStringWithTab(): void
    {
        $v = new VariableValue(VariableValue::TYPE_STRING, "a\tb", null);
        $this->assertSame('(string) "a\\tb"', VariableValueFormatter::format($v));
    }

    public function testStringWithCarriageReturn(): void
    {
        $v = new VariableValue(VariableValue::TYPE_STRING, "a\rb", null);
        $formatted = VariableValueFormatter::format($v);
        $this->assertSame('(string) "a\\rb"', $formatted);
        $this->assertStringNotContainsString("\r", $formatted);
    }

    public function testStringWithUnicode(): void
    {
        // JSON_UNESCAPED_UNICODE keeps non-ASCII readable.
        $v = new VariableValue(VariableValue::TYPE_STRING, 'こんにちは', null);
        $this->assertSame('(string) "こんにちは"', VariableValueFormatter::format($v));
    }

    public function testStringTruncatedAtDefaultLimit(): void
    {
        $long = str_repeat('x', 250);
        $v = new VariableValue(VariableValue::TYPE_STRING, $long, null);
        $formatted = VariableValueFormatter::format($v);
        $expected_body = str_repeat('x', 200) . '...';
        $this->assertSame('(string) "' . $expected_body . '"', $formatted);
    }

    public function testStringNotTruncatedAtBoundary(): void
    {
        $exact = str_repeat('x', 200);
        $v = new VariableValue(VariableValue::TYPE_STRING, $exact, null);
        $formatted = VariableValueFormatter::format($v);
        $this->assertSame('(string) "' . $exact . '"', $formatted);
    }

    public function testStringTruncatedWithCustomLimit(): void
    {
        $v = new VariableValue(VariableValue::TYPE_STRING, 'abcdef', null);
        $formatted = VariableValueFormatter::format($v, max_string_length: 3);
        $this->assertSame('(string) "abc..."', $formatted);
    }

    public function testStringTruncationCountsUnicodeChars(): void
    {
        // 10 Japanese chars (multi-byte) must be truncated by character count,
        // not by byte count — otherwise we'd cut in the middle of a codepoint.
        $v = new VariableValue(VariableValue::TYPE_STRING, str_repeat('あ', 10), null);
        $formatted = VariableValueFormatter::format($v, max_string_length: 3);
        $this->assertSame('(string) "あああ..."', $formatted);
    }

    public function testStringInvalidUtf8FallsBack(): void
    {
        // Raw invalid UTF-8 byte; json_encode returns false in strict mode.
        $v = new VariableValue(VariableValue::TYPE_STRING, "\xc3\x28", null);
        $formatted = VariableValueFormatter::format($v);
        $this->assertSame('(string) "<invalid-utf8>"', $formatted);
    }

    public function testBoolTrue(): void
    {
        $v = new VariableValue(VariableValue::TYPE_BOOL, true, null);
        $this->assertSame('(bool) true', VariableValueFormatter::format($v));
    }

    public function testBoolFalse(): void
    {
        $v = new VariableValue(VariableValue::TYPE_BOOL, false, null);
        $this->assertSame('(bool) false', VariableValueFormatter::format($v));
    }

    public function testArrayWithCount(): void
    {
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, 10);
        $this->assertSame('(array) count=10', VariableValueFormatter::format($v));
    }

    public function testArrayEmpty(): void
    {
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, 0);
        $this->assertSame('(array) count=0', VariableValueFormatter::format($v));
    }

    public function testArrayWithNullCountFallsBackToZero(): void
    {
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, null);
        $this->assertSame('(array) count=0', VariableValueFormatter::format($v));
    }

    public function testNull(): void
    {
        $v = new VariableValue(VariableValue::TYPE_NULL, null, null);
        $this->assertSame('null', VariableValueFormatter::format($v));
    }

    public function testUnknown(): void
    {
        $v = new VariableValue(VariableValue::TYPE_UNKNOWN, null, null);
        $this->assertSame('(unknown)', VariableValueFormatter::format($v));
    }
}
