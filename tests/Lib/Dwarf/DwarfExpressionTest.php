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

    public function testDiv(): void
    {
        // DW_OP_lit10 DW_OP_lit2 DW_OP_div = 0x3a 0x32 0x1b
        $result = $this->expr->evaluate("\x3a\x32\x1b", []);
        $this->assertSame(5, $result);
    }

    public function testMod(): void
    {
        // DW_OP_lit10 DW_OP_lit3 DW_OP_mod = 0x3a 0x33 0x1d
        $result = $this->expr->evaluate("\x3a\x33\x1d", []);
        $this->assertSame(1, $result);
    }

    public function testNeg(): void
    {
        // DW_OP_lit5 DW_OP_neg = 0x35 0x1f
        $result = $this->expr->evaluate("\x35\x1f", []);
        $this->assertSame(-5, $result);
    }

    public function testAbs(): void
    {
        // DW_OP_consts(-5) DW_OP_abs = 0x11 0x7b 0x19
        $result = $this->expr->evaluate("\x11\x7b\x19", []);
        $this->assertSame(5, $result);
    }

    public function testNot(): void
    {
        // DW_OP_lit0 DW_OP_not → ~0 = -1
        $result = $this->expr->evaluate("\x30\x20", []);
        $this->assertSame(-1, $result);
    }

    public function testXor(): void
    {
        // DW_OP_constu(0xff) DW_OP_constu(0xf0) DW_OP_xor → 0x0f
        $result = $this->expr->evaluate(
            "\x10\xff\x01\x10\xf0\x01\x27",
            [],
        );
        $this->assertSame(0x0f, $result);
    }

    public function testOr(): void
    {
        // DW_OP_constu(0xf0) DW_OP_constu(0x0f) DW_OP_or → 0xff
        $result = $this->expr->evaluate(
            "\x10\xf0\x01\x10\x0f\x21",
            [],
        );
        $this->assertSame(0xff, $result);
    }

    public function testShl(): void
    {
        // DW_OP_lit1 DW_OP_lit4 DW_OP_shl → 16
        $result = $this->expr->evaluate("\x31\x34\x24", []);
        $this->assertSame(16, $result);
    }

    public function testShr(): void
    {
        // DW_OP_constu(16) DW_OP_lit2 DW_OP_shr → 4
        $result = $this->expr->evaluate("\x10\x10\x32\x25", []);
        $this->assertSame(4, $result);
    }

    public function testShra(): void
    {
        // DW_OP_consts(-16) DW_OP_lit2 DW_OP_shra → -4
        $result = $this->expr->evaluate("\x11\x70\x32\x26", []);
        $this->assertSame(-4, $result);
    }

    public function testDrop(): void
    {
        // DW_OP_lit5 DW_OP_lit3 DW_OP_drop → 5
        $result = $this->expr->evaluate("\x35\x33\x13", []);
        $this->assertSame(5, $result);
    }

    public function testPick(): void
    {
        // DW_OP_lit1 DW_OP_lit2 DW_OP_lit3 DW_OP_pick(2) → 1
        // 0x31 0x32 0x33 0x15 0x02
        $result = $this->expr->evaluate("\x31\x32\x33\x15\x02", []);
        $this->assertSame(1, $result);
    }

    public function testOver(): void
    {
        // DW_OP_lit7 DW_OP_lit9 DW_OP_over → 7
        // stack: [7, 9, 7], top = 7
        $result = $this->expr->evaluate("\x37\x39\x14", []);
        $this->assertSame(7, $result);
    }

    public function testRot(): void
    {
        // DW_OP_lit1 DW_OP_lit2 DW_OP_lit3 DW_OP_rot → top=2
        // Before: [1, 2, 3], After: [3, 1, 2], top=2
        $result = $this->expr->evaluate("\x31\x32\x33\x17", []);
        $this->assertSame(2, $result);
    }

    public function testEq(): void
    {
        $this->assertSame(1, $this->expr->evaluate("\x35\x35\x29", [])); // 5==5
        $this->assertSame(0, $this->expr->evaluate("\x35\x36\x29", [])); // 5==6
    }

    public function testNe(): void
    {
        $this->assertSame(0, $this->expr->evaluate("\x35\x35\x2e", [])); // 5!=5
        $this->assertSame(1, $this->expr->evaluate("\x35\x36\x2e", [])); // 5!=6
    }

    public function testLt(): void
    {
        $this->assertSame(1, $this->expr->evaluate("\x33\x35\x2d", [])); // 3<5
        $this->assertSame(0, $this->expr->evaluate("\x35\x33\x2d", [])); // 5<3
    }

    public function testLe(): void
    {
        $this->assertSame(1, $this->expr->evaluate("\x35\x35\x2c", [])); // 5<=5
        $this->assertSame(0, $this->expr->evaluate("\x36\x35\x2c", [])); // 6<=5
    }

    public function testGe(): void
    {
        $this->assertSame(1, $this->expr->evaluate("\x35\x35\x2a", [])); // 5>=5
        $this->assertSame(0, $this->expr->evaluate("\x34\x35\x2a", [])); // 4>=5
    }

    public function testBregx(): void
    {
        // DW_OP_bregx reg=10 offset=4 → 0x92 0x0a 0x04
        $result = $this->expr->evaluate("\x92\x0a\x04", [10 => 0x100]);
        $this->assertSame(0x104, $result);
    }

    public function testConst1u(): void
    {
        // DW_OP_const1u 0x42 → 0x08 0x42
        $result = $this->expr->evaluate("\x08\x42", []);
        $this->assertSame(0x42, $result);
    }

    public function testConst1s(): void
    {
        // DW_OP_const1s -1 (0xff) → 0x09 0xff
        $result = $this->expr->evaluate("\x09\xff", []);
        $this->assertSame(-1, $result);
    }

    public function testConst2u(): void
    {
        // DW_OP_const2u 0x1234 → 0x0a 0x34 0x12
        $result = $this->expr->evaluate("\x0a\x34\x12", []);
        $this->assertSame(0x1234, $result);
    }

    public function testConst2s(): void
    {
        // DW_OP_const2s -1 (0xffff) → 0x0b 0xff 0xff
        $result = $this->expr->evaluate("\x0b\xff\xff", []);
        $this->assertSame(-1, $result);
    }

    public function testConst4u(): void
    {
        // DW_OP_const4u 0x12345678 → 0x0c 0x78 0x56 0x34 0x12
        $result = $this->expr->evaluate("\x0c\x78\x56\x34\x12", []);
        $this->assertSame(0x12345678, $result);
    }

    public function testConst4s(): void
    {
        // DW_OP_const4s -1 → 0x0d 0xff 0xff 0xff 0xff
        $result = $this->expr->evaluate("\x0d\xff\xff\xff\xff", []);
        $this->assertSame(-1, $result);
    }

    public function testConst8u(): void
    {
        // DW_OP_const8u 0x100 → 0x0e + 8 bytes LE
        $result = $this->expr->evaluate(
            "\x0e\x00\x01\x00\x00\x00\x00\x00\x00",
            [],
        );
        $this->assertSame(256, $result);
    }

    public function testAddr(): void
    {
        // DW_OP_addr 0x1000 → 0x03 + 8 bytes LE
        $result = $this->expr->evaluate(
            "\x03\x00\x10\x00\x00\x00\x00\x00\x00",
            [],
        );
        $this->assertSame(0x1000, $result);
    }

    public function testDerefWithMemoryReader(): void
    {
        $memory_reader = \Mockery::mock(
            \Reli\Lib\Process\MemoryReader\MemoryReaderInterface::class
        );
        // DW_OP_constu(0x2000) DW_OP_deref → read 8 bytes from 0x2000
        $eight_bytes = \FFI::new('unsigned char[8]');
        // Little-endian 0x42
        $eight_bytes[0] = 0x42;
        for ($i = 1; $i < 8; $i++) {
            $eight_bytes[$i] = 0;
        }
        $memory_reader->expects('read')
            ->with(123, 0x2000, 8)
            ->andReturn($eight_bytes);

        $result = $this->expr->evaluate(
            "\x10\x80\x40\x06", // constu(0x2000) deref
            [],
            $memory_reader,
            123,
        );
        $this->assertSame(0x42, $result);
    }

    public function testDerefWithoutMemoryReaderThrows(): void
    {
        $this->expectException(DwarfException::class);
        // DW_OP_constu(0x1000) DW_OP_deref
        $this->expr->evaluate("\x10\x80\x20\x06", []);
    }

    public function testDerefSize(): void
    {
        $memory_reader = \Mockery::mock(
            \Reli\Lib\Process\MemoryReader\MemoryReaderInterface::class
        );
        $four_bytes = \FFI::new('unsigned char[4]');
        $four_bytes[0] = 0x78;
        $four_bytes[1] = 0x56;
        $four_bytes[2] = 0x34;
        $four_bytes[3] = 0x12;
        $memory_reader->expects('read')
            ->with(1, 0x3000, 4)
            ->andReturn($four_bytes);

        // DW_OP_constu(0x3000) DW_OP_deref_size(4)
        $result = $this->expr->evaluate(
            "\x10\x80\x60\x94\x04",
            [],
            $memory_reader,
            1,
        );
        $this->assertSame(0x12345678, $result);
    }

    public function testUnsupportedOpThrows(): void
    {
        $this->expectException(DwarfException::class);
        // 0x01 is not a valid DW_OP (it would be DW_OP_addr range but...)
        // Use 0x97 which is undefined
        $this->expr->evaluate("\x97", []);
    }
}
