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

namespace Reli\Lib\PhpInternals\Types\Zend;

class ZendMmBinsInfo
{
    private const SIZES = [
        0 => 8,
        1 => 16,
        2 => 24,
        3 => 32,
        4 => 40,
        5 => 48,
        6 => 56,
        7 => 64,
        8 => 80,
        9 => 96,
        10 => 112,
        11 => 128,
        12 => 160,
        13 => 192,
        14 => 224,
        15 => 256,
        16 => 320,
        17 => 384,
        18 => 448,
        19 => 512,
        20 => 640,
        21 => 768,
        22 => 896,
        23 => 1024,
        24 => 1280,
        25 => 1536,
        26 => 1792,
        27 => 2048,
        28 => 2560,
        29 => 3072,
    ];

    private const COUNTS = [
        0 => 512,
        1 => 256,
        2 => 170,
        3 => 128,
        4 => 102,
        5 => 85,
        6 => 73,
        7 => 64,
        8 => 51,
        9 => 42,
        10 => 36,
        11 => 32,
        12 => 25,
        13 => 21,
        14 => 18,
        15 => 16,
        16 => 64,
        17 => 32,
        18 => 9,
        19 => 8,
        20 => 32,
        21 => 16,
        22 => 9,
        23 => 8,
        24 => 16,
        25 => 8,
        26 => 16,
        27 => 8,
        28 => 8,
        29 => 4,
    ];

    private const PAGES = [
        0 => 1,
        1 => 1,
        2 => 1,
        3 => 1,
        4 => 1,
        5 => 1,
        6 => 1,
        7 => 1,
        8 => 1,
        9 => 1,
        10 => 1,
        11 => 1,
        12 => 1,
        13 => 1,
        14 => 1,
        15 => 1,
        16 => 5,
        17 => 3,
        18 => 1,
        19 => 1,
        20 => 5,
        21 => 3,
        22 => 2,
        23 => 2,
        24 => 5,
        25 => 3,
        26 => 7,
        27 => 4,
        28 => 5,
        29 => 3,
    ];

    public static function getSize(int $bin_num): int
    {
        return self::SIZES[$bin_num];
    }

    public static function getCount(int $bin_num): int
    {
        return self::COUNTS[$bin_num];
    }

    public static function getPages(int $bin_num): int
    {
        return self::PAGES[$bin_num];
    }

    public static function sizeToBinNum(int $size): int
    {
        $bin_num = 0;
        while (self::SIZES[$bin_num] < $size) {
            ++$bin_num;
            if ($bin_num >= 30) {
                throw new \RuntimeException('invalid size');
            }
        }

        return $bin_num;
    }
}
