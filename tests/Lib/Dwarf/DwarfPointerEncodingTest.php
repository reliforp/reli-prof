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
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;

class DwarfPointerEncodingTest extends BaseTestCase
{
    private LittleEndianReader $reader;

    protected function setUp(): void
    {
        $this->reader = new LittleEndianReader();
    }

    public function testOmit(): void
    {
        $data = new StringByteReader("\x00");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_OMIT,
            0,
            $this->reader,
        );
        $this->assertSame(0, $value);
        $this->assertSame(0, $consumed);
    }

    public function testAbsptr(): void
    {
        // 8 bytes little-endian: 0x0000000000001234
        $data = new StringByteReader("\x34\x12\x00\x00\x00\x00\x00\x00");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_ABSPTR,
            0,
            $this->reader,
        );
        $this->assertSame(0x1234, $value);
        $this->assertSame(8, $consumed);
    }

    public function testUdata4(): void
    {
        $data = new StringByteReader("\x78\x56\x34\x12");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_UDATA4,
            0,
            $this->reader,
        );
        $this->assertSame(0x12345678, $value);
        $this->assertSame(4, $consumed);
    }

    public function testUdata2(): void
    {
        $data = new StringByteReader("\x34\x12");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_UDATA2,
            0,
            $this->reader,
        );
        $this->assertSame(0x1234, $value);
        $this->assertSame(2, $consumed);
    }

    public function testSdata4(): void
    {
        // -1 as sdata4 = 0xffffffff
        $data = new StringByteReader("\xff\xff\xff\xff");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_SDATA4,
            0,
            $this->reader,
        );
        $this->assertSame(-1, $value);
        $this->assertSame(4, $consumed);
    }

    public function testSdata4Negative(): void
    {
        // -100 as sdata4 = 0xffffff9c
        $data = new StringByteReader("\x9c\xff\xff\xff");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_SDATA4,
            0,
            $this->reader,
        );
        $this->assertSame(-100, $value);
        $this->assertSame(4, $consumed);
    }

    public function testPcrelSdata4(): void
    {
        // pcrel + sdata4: value = raw + pcRelBase
        // raw = -0x100 (0xffffff00), pcRelBase = 0x5000
        $data = new StringByteReader("\x00\xff\xff\xff");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_PCREL | DwarfPointerEncoding::DW_EH_PE_SDATA4,
            0x5000,
            $this->reader,
        );
        $this->assertSame(0x5000 - 0x100, $value);
        $this->assertSame(4, $consumed);
    }

    public function testUleb128(): void
    {
        // ULEB128: 624485 = 0xe5 0x8e 0x26
        $data = new StringByteReader("\xe5\x8e\x26");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_ULEB128,
            0,
            $this->reader,
        );
        $this->assertSame(624485, $value);
        $this->assertSame(3, $consumed);
    }

    public function testSleb128(): void
    {
        // SLEB128: -1 = 0x7f
        $data = new StringByteReader("\x7f");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_SLEB128,
            0,
            $this->reader,
        );
        $this->assertSame(-1, $value);
        $this->assertSame(1, $consumed);
    }

    public function testUnsupportedFormatThrows(): void
    {
        $data = new StringByteReader("\x00");
        $this->expectException(DwarfException::class);
        DwarfPointerEncoding::decode(
            $data,
            0,
            0x07, // invalid format
            0,
            $this->reader,
        );
    }

    public function testSdata2(): void
    {
        // -1 as sdata2 = 0xffff
        $data = new StringByteReader("\xff\xff");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_SDATA2,
            0,
            $this->reader,
        );
        $this->assertSame(-1, $value);
        $this->assertSame(2, $consumed);
    }

    public function testPcrelUdata4(): void
    {
        // pcrel + udata4: value = 0x100 + 0x2000 = 0x2100
        $data = new StringByteReader("\x00\x01\x00\x00");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            0,
            DwarfPointerEncoding::DW_EH_PE_PCREL | DwarfPointerEncoding::DW_EH_PE_UDATA4,
            0x2000,
            $this->reader,
        );
        $this->assertSame(0x2100, $value);
        $this->assertSame(4, $consumed);
    }

    public function testWithOffset(): void
    {
        $data = new StringByteReader("\xff\xff\x34\x12\x00\x00");
        [$value, $consumed] = DwarfPointerEncoding::decode(
            $data,
            2, // skip first 2 bytes
            DwarfPointerEncoding::DW_EH_PE_UDATA4,
            0,
            $this->reader,
        );
        $this->assertSame(0x1234, $value);
        $this->assertSame(4, $consumed);
    }
}
