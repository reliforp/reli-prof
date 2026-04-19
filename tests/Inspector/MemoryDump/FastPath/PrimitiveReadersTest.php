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

namespace Reli\Inspector\MemoryDump\FastPath;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrimitiveReadersTest extends TestCase
{
    // --- u8 ---

    #[Test]
    public function u8ReadsZero(): void
    {
        $buf = "\x00";
        $this->assertSame(0, PrimitiveReaders::u8($buf, 0));
    }

    #[Test]
    public function u8ReadsMaxValue(): void
    {
        $buf = "\xFF";
        $this->assertSame(255, PrimitiveReaders::u8($buf, 0));
    }

    #[Test]
    public function u8ReadsAtOffset(): void
    {
        $buf = "\x00\x00\xAB";
        $this->assertSame(0xAB, PrimitiveReaders::u8($buf, 2));
    }

    // --- u16le ---

    #[Test]
    public function u16leReadsZero(): void
    {
        $buf = "\x00\x00";
        $this->assertSame(0, PrimitiveReaders::u16le($buf, 0));
    }

    #[Test]
    public function u16leReadsMaxValue(): void
    {
        $buf = "\xFF\xFF";
        $this->assertSame(0xFFFF, PrimitiveReaders::u16le($buf, 0));
    }

    #[Test]
    public function u16leReadsLittleEndian(): void
    {
        // 0x0201 stored as [0x01, 0x02] in LE
        $buf = "\x01\x02";
        $this->assertSame(0x0201, PrimitiveReaders::u16le($buf, 0));
    }

    #[Test]
    public function u16leReadsAtOffset(): void
    {
        $buf = "\xFF\x34\x12";
        $this->assertSame(0x1234, PrimitiveReaders::u16le($buf, 1));
    }

    // --- u32le ---

    #[Test]
    public function u32leReadsZero(): void
    {
        $buf = "\x00\x00\x00\x00";
        $this->assertSame(0, PrimitiveReaders::u32le($buf, 0));
    }

    #[Test]
    public function u32leReadsKnownValue(): void
    {
        // 0x04030201 in LE: [0x01, 0x02, 0x03, 0x04]
        $buf = "\x01\x02\x03\x04";
        $this->assertSame(0x04030201, PrimitiveReaders::u32le($buf, 0));
    }

    #[Test]
    public function u32leReadsMaxValue(): void
    {
        $buf = "\xFF\xFF\xFF\xFF";
        // On 64-bit PHP, u32le returns 0xFFFFFFFF as a positive int
        $this->assertSame(0xFFFFFFFF, PrimitiveReaders::u32le($buf, 0));
    }

    #[Test]
    public function u32leReadsAtOffset(): void
    {
        $buf = "\x00\x78\x56\x34\x12";
        $this->assertSame(0x12345678, PrimitiveReaders::u32le($buf, 1));
    }

    // --- u64le ---

    #[Test]
    public function u64leReadsZero(): void
    {
        $buf = "\x00\x00\x00\x00\x00\x00\x00\x00";
        $this->assertSame(0, PrimitiveReaders::u64le($buf, 0));
    }

    #[Test]
    public function u64leReadsKnownValue(): void
    {
        $buf = pack('P', 0x0102030405060708);
        $this->assertSame(0x0102030405060708, PrimitiveReaders::u64le($buf, 0));
    }

    #[Test]
    public function u64leReadsAtOffset(): void
    {
        $prefix = "\xAA\xBB";
        $value = pack('P', 42);
        $buf = $prefix . $value;
        $this->assertSame(42, PrimitiveReaders::u64le($buf, 2));
    }

    // --- ptr ---

    #[Test]
    public function ptrIsSemanticallyEquivalentToU64le(): void
    {
        $buf = pack('P', 0x7F00DEADBEEF);
        $this->assertSame(
            PrimitiveReaders::u64le($buf, 0),
            PrimitiveReaders::ptr($buf, 0),
        );
    }

    #[Test]
    public function ptrReadsZeroPointer(): void
    {
        $buf = "\x00\x00\x00\x00\x00\x00\x00\x00";
        $this->assertSame(0, PrimitiveReaders::ptr($buf, 0));
    }

    // --- i32le ---

    #[Test]
    public function i32leReadsZero(): void
    {
        $buf = "\x00\x00\x00\x00";
        $this->assertSame(0, PrimitiveReaders::i32le($buf, 0));
    }

    #[Test]
    public function i32leReadsPositiveValue(): void
    {
        $buf = pack('l', 12345);
        $this->assertSame(12345, PrimitiveReaders::i32le($buf, 0));
    }

    #[Test]
    public function i32leReadsNegativeValue(): void
    {
        $buf = pack('l', -1);
        $this->assertSame(-1, PrimitiveReaders::i32le($buf, 0));
    }

    #[Test]
    public function i32leReadsMinValue(): void
    {
        // INT32_MIN = -2147483648
        $buf = pack('l', -2147483648);
        $this->assertSame(-2147483648, PrimitiveReaders::i32le($buf, 0));
    }

    #[Test]
    public function i32leReadsMaxValue(): void
    {
        // INT32_MAX = 2147483647
        $buf = pack('l', 2147483647);
        $this->assertSame(2147483647, PrimitiveReaders::i32le($buf, 0));
    }

    #[Test]
    public function i32leReadsAtOffset(): void
    {
        $prefix = "\x00\x00\x00";
        $buf = $prefix . pack('l', -42);
        $this->assertSame(-42, PrimitiveReaders::i32le($buf, 3));
    }

    // --- slice ---

    #[Test]
    public function sliceExtractsSubstring(): void
    {
        $buf = "Hello, World!";
        $this->assertSame("World", PrimitiveReaders::slice($buf, 7, 5));
    }

    #[Test]
    public function sliceExtractsBinaryData(): void
    {
        $buf = "\x00\x01\x02\x03\x04\x05";
        $this->assertSame("\x02\x03\x04", PrimitiveReaders::slice($buf, 2, 3));
    }

    #[Test]
    public function sliceFromBeginning(): void
    {
        $buf = "\xAA\xBB\xCC";
        $this->assertSame("\xAA\xBB", PrimitiveReaders::slice($buf, 0, 2));
    }

    #[Test]
    public function sliceZeroLength(): void
    {
        $buf = "anything";
        $this->assertSame("", PrimitiveReaders::slice($buf, 3, 0));
    }

    // --- Combined: reading multiple values from a packed buffer ---

    #[Test]
    public function multipleReadsFromPackedStruct(): void
    {
        // Simulate a packed struct: u8 flags, u16le id, u32le size, u64le address
        $buf = pack('C', 0x42)          // u8: 0x42
            . pack('v', 0x1234)         // u16le: 0x1234
            . pack('V', 0xDEADBEEF)     // u32le: 0xDEADBEEF
            . pack('P', 0x7FFF00001000) // u64le: pointer
        ;

        $this->assertSame(0x42, PrimitiveReaders::u8($buf, 0));
        $this->assertSame(0x1234, PrimitiveReaders::u16le($buf, 1));
        $this->assertSame(0xDEADBEEF, PrimitiveReaders::u32le($buf, 3));
        $this->assertSame(0x7FFF00001000, PrimitiveReaders::u64le($buf, 7));
    }
}
