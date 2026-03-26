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
use Reli\Lib\ByteStream\StringByteReader;

class Leb128Test extends BaseTestCase
{
    public function testDecodeUnsignedSingleByte(): void
    {
        // 0x00 = 0
        $data = new StringByteReader("\x00");
        [$value, $consumed] = Leb128::decodeUnsigned($data, 0);
        $this->assertSame(0, $value);
        $this->assertSame(1, $consumed);
    }

    public function testDecodeUnsignedSmallValue(): void
    {
        // 0x7f = 127
        $data = new StringByteReader("\x7f");
        [$value, $consumed] = Leb128::decodeUnsigned($data, 0);
        $this->assertSame(127, $value);
        $this->assertSame(1, $consumed);
    }

    public function testDecodeUnsignedMultiByte(): void
    {
        // 128 = 0x80 0x01
        $data = new StringByteReader("\x80\x01");
        [$value, $consumed] = Leb128::decodeUnsigned($data, 0);
        $this->assertSame(128, $value);
        $this->assertSame(2, $consumed);
    }

    public function testDecodeUnsigned624485(): void
    {
        // 624485 = 0xe5 0x8e 0x26
        $data = new StringByteReader("\xe5\x8e\x26");
        [$value, $consumed] = Leb128::decodeUnsigned($data, 0);
        $this->assertSame(624485, $value);
        $this->assertSame(3, $consumed);
    }

    public function testDecodeUnsignedWithOffset(): void
    {
        $data = new StringByteReader("\xff\xff\xe5\x8e\x26");
        [$value, $consumed] = Leb128::decodeUnsigned($data, 2);
        $this->assertSame(624485, $value);
        $this->assertSame(3, $consumed);
    }

    public function testDecodeSignedZero(): void
    {
        $data = new StringByteReader("\x00");
        [$value, $consumed] = Leb128::decodeSigned($data, 0);
        $this->assertSame(0, $value);
        $this->assertSame(1, $consumed);
    }

    public function testDecodeSignedPositive(): void
    {
        // +1 = 0x01
        $data = new StringByteReader("\x01");
        [$value, $consumed] = Leb128::decodeSigned($data, 0);
        $this->assertSame(1, $value);
        $this->assertSame(1, $consumed);
    }

    public function testDecodeSignedNegativeOne(): void
    {
        // -1 = 0x7f
        $data = new StringByteReader("\x7f");
        [$value, $consumed] = Leb128::decodeSigned($data, 0);
        $this->assertSame(-1, $value);
        $this->assertSame(1, $consumed);
    }

    public function testDecodeSignedNegativeMultiByte(): void
    {
        // -128 = 0x80 0x7f
        $data = new StringByteReader("\x80\x7f");
        [$value, $consumed] = Leb128::decodeSigned($data, 0);
        $this->assertSame(-128, $value);
        $this->assertSame(2, $consumed);
    }

    public function testDecodeSignedPositiveMultiByte(): void
    {
        // 128 = 0x80 0x01
        $data = new StringByteReader("\x80\x01");
        [$value, $consumed] = Leb128::decodeSigned($data, 0);
        $this->assertSame(128, $value);
        $this->assertSame(2, $consumed);
    }

    public function testDecodeSignedNegative123456(): void
    {
        // -123456 = 0xc0 0xbb 0x78
        $data = new StringByteReader("\xc0\xbb\x78");
        [$value, $consumed] = Leb128::decodeSigned($data, 0);
        $this->assertSame(-123456, $value);
        $this->assertSame(3, $consumed);
    }
}
