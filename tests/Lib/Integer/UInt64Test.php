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

namespace Reli\Lib\Integer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UInt64Test extends TestCase
{
    #[DataProvider('toIntDataProvider')]
    public function testToInt(int $hi, int $lo, int $expected)
    {
        $uint64 = new UInt64($hi, $lo);
        $this->assertSame($expected, $uint64->toInt());
    }

    public static function toIntDataProvider()
    {
        return [
            [0, 0, 0],
            [0, 1, 1],
            [1, 0, 0x1_0000_0000],
            [1, 1, 0x1_0000_0001],
            [0, 0xffff_ffff, 0xffff_ffff],
            [0xffff_ffff, 0, -4294967296],
            [0xffff_ffff, 0xffff_ffff, -1],
        ];
    }

    #[DataProvider('toStringDataProvider')]
    public function testToString(int $hi, int $lo, string $expected)
    {
        $uint64 = new UInt64($hi, $lo);
        $this->assertSame($expected, (string)$uint64);
    }

    public static function toStringDataProvider()
    {
        return [
            [0, 0, '0'],
            [0, 1, '1'],
            [1, 0, '4294967296'],
            [1, 1, '4294967297'],
            [0, 0xffff_ffff, '4294967295'],
            [0xffff_ffff, 0, '18446744069414584320'],
            [0xffff_ffff, 0xffff_ffff, '18446744073709551615'],
        ];
    }

    #[DataProvider('checkBitSetDataProvider')]
    public function testCheckBitSet(int $hi, int $lo, int $bit, bool $expected)
    {
        $uint64 = new UInt64($hi, $lo);
        $this->assertSame($expected, $uint64->checkBitSet($bit));
    }

    public static function checkBitSetDataProvider()
    {
        return [
            [0, 0, 0, false],
            [0, 0, 1, false],
            [0, 1, 0, true],
            [0, 1, 1, false],
            [1, 0, 0, false],
            [1, 0, 1, false],
            [1, 1, 0, true],
            [1, 1, 1, false],
            [0, 2, 0, false],
            [0, 2, 1, true],
            [2, 0, 32, false],
            [2, 0, 33, true],
            [0, 0xffff_ffff, 0, true],
            [0, 0xffff_ffff, 1, true],
            [0, 0xffff_ffff, 2, true],
            [0, 0xffff_ffff, 3, true],
            [0, 0xffff_ffff, 4, true],
            [0, 0xffff_ffff, 5, true],
            [0, 0xffff_ffff, 6, true],
            [0, 0xffff_ffff, 7, true],
            [0, 0xffff_ffff, 8, true],
            [0, 0xffff_ffff, 9, true],
            [0, 0xffff_ffff, 10, true],
            [0, 0xffff_ffff, 11, true],
            [0, 0xffff_ffff, 12, true],
            [0, 0xffff_ffff, 13, true],
            [0, 0xffff_ffff, 14, true],
            [0, 0xffff_ffff, 15, true],
            [0, 0xffff_ffff, 16, true],
            [0, 0xffff_ffff, 17, true],
            [0, 0xffff_ffff, 18, true],
            [0, 0xffff_ffff, 19, true],
            [0, 0xffff_ffff, 20, true],
            [0, 0xffff_ffff, 21, true],
            [0, 0xffff_ffff, 22, true],
            [0, 0xffff_ffff, 23, true],
            [0, 0xffff_ffff, 24, true],
            [0, 0xffff_ffff, 25, true],
            [0, 0xffff_ffff, 26, true],
            [0, 0xffff_ffff, 27, true],
            [0, 0xffff_ffff, 28, true],
            [0, 0xffff_ffff, 29, true],
            [0, 0xffff_ffff, 30, true],
            [0, 0xffff_ffff, 31, true],
            [0, 0xffff_ffff, 32, false],
            [0, 0xffff_ffff, 33, false],
            [0, 0xffff_ffff, 34, false],
            [0, 0xffff_ffff, 35, false],
            [0, 0xffff_ffff, 36, false],
            [0, 0xffff_ffff, 37, false],
            [0, 0xffff_ffff, 38, false],
            [0, 0xffff_ffff, 39, false],
            [0, 0xffff_ffff, 40, false],
            [0, 0xffff_ffff, 41, false],
            [0, 0xffff_ffff, 42, false],
            [0, 0xffff_ffff, 43, false],
            [0, 0xffff_ffff, 44, false],
            [0, 0xffff_ffff, 45, false],
            [0, 0xffff_ffff, 46, false],
            [0, 0xffff_ffff, 47, false],
            [0, 0xffff_ffff, 48, false],
            [0, 0xffff_ffff, 49, false],
            [0, 0xffff_ffff, 50, false],
            [0, 0xffff_ffff, 51, false],
            [0, 0xffff_ffff, 52, false],
            [0, 0xffff_ffff, 53, false],
            [0, 0xffff_ffff, 54, false],
            [0, 0xffff_ffff, 55, false],
            [0, 0xffff_ffff, 56, false],
            [0, 0xffff_ffff, 57, false],
            [0, 0xffff_ffff, 58, false],
            [0, 0xffff_ffff, 59, false],
            [0, 0xffff_ffff, 60, false],
            [0, 0xffff_ffff, 61, false],
            [0, 0xffff_ffff, 62, false],
            [0, 0xffff_ffff, 63, false],
            [0, 0xffff_ffff, 64, false],
            [0xffff_ffff, 0, 0, false],
            [0xffff_ffff, 0, 1, false],
            [0xffff_ffff, 0, 2, false],
            [0xffff_ffff, 0, 3, false],
            [0xffff_ffff, 0, 4, false],
            [0xffff_ffff, 0, 5, false],
            [0xffff_ffff, 0, 6, false],
            [0xffff_ffff, 0, 7, false],
            [0xffff_ffff, 0, 8, false],
            [0xffff_ffff, 0, 9, false],
            [0xffff_ffff, 0, 10, false],
            [0xffff_ffff, 0, 11, false],
            [0xffff_ffff, 0, 12, false],
            [0xffff_ffff, 0, 13, false],
            [0xffff_ffff, 0, 14, false],
            [0xffff_ffff, 0, 15, false],
            [0xffff_ffff, 0, 16, false],
            [0xffff_ffff, 0, 17, false],
            [0xffff_ffff, 0, 18, false],
            [0xffff_ffff, 0, 19, false],
            [0xffff_ffff, 0, 20, false],
            [0xffff_ffff, 0, 21, false],
            [0xffff_ffff, 0, 22, false],
            [0xffff_ffff, 0, 23, false],
            [0xffff_ffff, 0, 24, false],
            [0xffff_ffff, 0, 25, false],
            [0xffff_ffff, 0, 26, false],
            [0xffff_ffff, 0, 27, false],
            [0xffff_ffff, 0, 28, false],
            [0xffff_ffff, 0, 29, false],
            [0xffff_ffff, 0, 30, false],
            [0xffff_ffff, 0, 31, false],
            [0xffff_ffff, 0, 32, true],
            [0xffff_ffff, 0, 33, true],
            [0xffff_ffff, 0, 34, true],
            [0xffff_ffff, 0, 35, true],
            [0xffff_ffff, 0, 36, true],
            [0xffff_ffff, 0, 37, true],
            [0xffff_ffff, 0, 38, true],
            [0xffff_ffff, 0, 39, true],
            [0xffff_ffff, 0, 40, true],
            [0xffff_ffff, 0, 41, true],
            [0xffff_ffff, 0, 42, true],
            [0xffff_ffff, 0, 43, true],
            [0xffff_ffff, 0, 44, true],
            [0xffff_ffff, 0, 45, true],
            [0xffff_ffff, 0, 46, true],
            [0xffff_ffff, 0, 47, true],
            [0xffff_ffff, 0, 48, true],
            [0xffff_ffff, 0, 49, true],
            [0xffff_ffff, 0, 50, true],
            [0xffff_ffff, 0, 51, true],
            [0xffff_ffff, 0, 52, true],
            [0xffff_ffff, 0, 53, true],
            [0xffff_ffff, 0, 54, true],
            [0xffff_ffff, 0, 55, true],
            [0xffff_ffff, 0, 56, true],
            [0xffff_ffff, 0, 57, true],
            [0xffff_ffff, 0, 58, true],
            [0xffff_ffff, 0, 59, true],
            [0xffff_ffff, 0, 60, true],
            [0xffff_ffff, 0, 61, true],
            [0xffff_ffff, 0, 62, true],
            [0xffff_ffff, 0, 63, true],
            [0xffff_ffff, 0, 64, false],
            [0xffff_ffff, 0xffff_ffff, 0, true],
            [0xffff_ffff, 0xffff_ffff, 1, true],
            [0xffff_ffff, 0xffff_ffff, 2, true],
            [0xffff_ffff, 0xffff_ffff, 3, true],
            [0xffff_ffff, 0xffff_ffff, 4, true],
            [0xffff_ffff, 0xffff_ffff, 5, true],
            [0xffff_ffff, 0xffff_ffff, 6, true],
            [0xffff_ffff, 0xffff_ffff, 7, true],
            [0xffff_ffff, 0xffff_ffff, 8, true],
            [0xffff_ffff, 0xffff_ffff, 9, true],
            [0xffff_ffff, 0xffff_ffff, 10, true],
            [0xffff_ffff, 0xffff_ffff, 11, true],
            [0xffff_ffff, 0xffff_ffff, 12, true],
            [0xffff_ffff, 0xffff_ffff, 13, true],
            [0xffff_ffff, 0xffff_ffff, 14, true],
            [0xffff_ffff, 0xffff_ffff, 15, true],
            [0xffff_ffff, 0xffff_ffff, 16, true],
            [0xffff_ffff, 0xffff_ffff, 17, true],
            [0xffff_ffff, 0xffff_ffff, 18, true],
            [0xffff_ffff, 0xffff_ffff, 19, true],
            [0xffff_ffff, 0xffff_ffff, 20, true],
            [0xffff_ffff, 0xffff_ffff, 21, true],
            [0xffff_ffff, 0xffff_ffff, 22, true],
            [0xffff_ffff, 0xffff_ffff, 23, true],
            [0xffff_ffff, 0xffff_ffff, 24, true],
            [0xffff_ffff, 0xffff_ffff, 25, true],
            [0xffff_ffff, 0xffff_ffff, 26, true],
            [0xffff_ffff, 0xffff_ffff, 27, true],
            [0xffff_ffff, 0xffff_ffff, 28, true],
            [0xffff_ffff, 0xffff_ffff, 29, true],
            [0xffff_ffff, 0xffff_ffff, 30, true],
            [0xffff_ffff, 0xffff_ffff, 31, true],
            [0xffff_ffff, 0xffff_ffff, 32, true],
            [0xffff_ffff, 0xffff_ffff, 33, true],
            [0xffff_ffff, 0xffff_ffff, 34, true],
            [0xffff_ffff, 0xffff_ffff, 35, true],
            [0xffff_ffff, 0xffff_ffff, 36, true],
            [0xffff_ffff, 0xffff_ffff, 37, true],
            [0xffff_ffff, 0xffff_ffff, 38, true],
            [0xffff_ffff, 0xffff_ffff, 39, true],
            [0xffff_ffff, 0xffff_ffff, 40, true],
            [0xffff_ffff, 0xffff_ffff, 41, true],
            [0xffff_ffff, 0xffff_ffff, 42, true],
            [0xffff_ffff, 0xffff_ffff, 43, true],
            [0xffff_ffff, 0xffff_ffff, 44, true],
            [0xffff_ffff, 0xffff_ffff, 45, true],
            [0xffff_ffff, 0xffff_ffff, 46, true],
            [0xffff_ffff, 0xffff_ffff, 47, true],
            [0xffff_ffff, 0xffff_ffff, 48, true],
            [0xffff_ffff, 0xffff_ffff, 49, true],
            [0xffff_ffff, 0xffff_ffff, 50, true],
            [0xffff_ffff, 0xffff_ffff, 51, true],
            [0xffff_ffff, 0xffff_ffff, 52, true],
            [0xffff_ffff, 0xffff_ffff, 53, true],
            [0xffff_ffff, 0xffff_ffff, 54, true],
            [0xffff_ffff, 0xffff_ffff, 55, true],
            [0xffff_ffff, 0xffff_ffff, 56, true],
            [0xffff_ffff, 0xffff_ffff, 57, true],
            [0xffff_ffff, 0xffff_ffff, 58, true],
            [0xffff_ffff, 0xffff_ffff, 59, true],
            [0xffff_ffff, 0xffff_ffff, 60, true],
            [0xffff_ffff, 0xffff_ffff, 61, true],
            [0xffff_ffff, 0xffff_ffff, 62, true],
            [0xffff_ffff, 0xffff_ffff, 63, true],
            [0xffff_ffff, 0xffff_ffff, 64, false],
        ];
    }
}
