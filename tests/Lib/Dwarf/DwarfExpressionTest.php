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

namespace Reli\Lib\Dwarf;

use Reli\BaseTestCase;

class DwarfExpressionTest extends BaseTestCase
{
    private DwarfExpression $expr;

    protected function setUp(): void
    {
        $this->expr = new DwarfExpression();
    }

    public function testLiteral(): void
    {
        // DW_OP_lit5 = 0x35
        $result = $this->expr->evaluate("\x35", []);
        $this->assertSame(5, $result);
    }

    public function testConstu(): void
    {
        // DW_OP_constu(42) = 0x10 0x2a
        $result = $this->expr->evaluate("\x10\x2a", []);
        $this->assertSame(42, $result);
    }

    public function testConsts(): void
    {
        // DW_OP_consts(-1) = 0x11 0x7f
        $result = $this->expr->evaluate("\x11\x7f", []);
        $this->assertSame(-1, $result);
    }

    public function testBreg(): void
    {
        // DW_OP_breg7(offset=16) = 0x77 0x10
        // Register 7 (RSP) = 0x1000
        $result = $this->expr->evaluate("\x77\x10", [7 => 0x1000]);
        $this->assertSame(0x1010, $result);
    }

    public function testBregNegativeOffset(): void
    {
        // DW_OP_breg6(offset=-8) = 0x76 0x78
        // Register 6 (RBP) = 0x2000
        $result = $this->expr->evaluate("\x76\x78", [6 => 0x2000]);
        $this->assertSame(0x2000 - 8, $result);
    }

    public function testPlus(): void
    {
        // DW_OP_lit3 DW_OP_lit4 DW_OP_plus = 0x33 0x34 0x22
        $result = $this->expr->evaluate("\x33\x34\x22", []);
        $this->assertSame(7, $result);
    }

    public function testPlusUconst(): void
    {
        // DW_OP_lit10 DW_OP_plus_uconst(5) = 0x3a 0x23 0x05
        $result = $this->expr->evaluate("\x3a\x23\x05", []);
        $this->assertSame(15, $result);
    }

    public function testMinus(): void
    {
        // DW_OP_lit10 DW_OP_lit3 DW_OP_minus = 0x3a 0x33 0x1c
        $result = $this->expr->evaluate("\x3a\x33\x1c", []);
        $this->assertSame(7, $result);
    }

    public function testMul(): void
    {
        // DW_OP_lit6 DW_OP_lit7 DW_OP_mul = 0x36 0x37 0x1e
        $result = $this->expr->evaluate("\x36\x37\x1e", []);
        $this->assertSame(42, $result);
    }

    public function testDup(): void
    {
        // DW_OP_lit5 DW_OP_dup DW_OP_plus = 0x35 0x12 0x22
        $result = $this->expr->evaluate("\x35\x12\x22", []);
        $this->assertSame(10, $result);
    }

    public function testSwap(): void
    {
        // DW_OP_lit3 DW_OP_lit7 DW_OP_swap DW_OP_minus = 0x33 0x37 0x16 0x1c
        // After swap: stack = [7, 3], minus: 7 - 3 = 4
        $result = $this->expr->evaluate("\x33\x37\x16\x1c", []);
        $this->assertSame(4, $result);
    }

    public function testAnd(): void
    {
        // DW_OP_constu(0xff) DW_OP_constu(0x0f) DW_OP_and
        // 0x10 0xff01 0x10 0x0f 0x1a
        $result = $this->expr->evaluate("\x10\xff\x01\x10\x0f\x1a", []);
        $this->assertSame(0x0f, $result);
    }

    public function testComparison(): void
    {
        // DW_OP_lit5 DW_OP_lit3 DW_OP_gt = 0x35 0x33 0x2b
        $result = $this->expr->evaluate("\x35\x33\x2b", []);
        $this->assertSame(1, $result);

        // DW_OP_lit3 DW_OP_lit5 DW_OP_gt
        $result = $this->expr->evaluate("\x33\x35\x2b", []);
        $this->assertSame(0, $result);
    }

    public function testNop(): void
    {
        // DW_OP_lit42 DW_OP_nop = 0x4a (lit10+32=42) wait, lit0=0x30..lit31=0x4f
        // lit12 = 0x3c, nop = 0x96
        $result = $this->expr->evaluate("\x3c\x96", []);
        $this->assertSame(12, $result);
    }

    public function testStackValue(): void
    {
        // DW_OP_lit7 DW_OP_stack_value = 0x37 0x9f
        $result = $this->expr->evaluate("\x37\x9f", []);
        $this->assertSame(7, $result);
    }

    public function testEmptyExpressionThrows(): void
    {
        $this->expectException(DwarfException::class);
        $this->expr->evaluate("", []);
    }
}
