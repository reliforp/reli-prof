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

namespace PhpProfiler\Lib\ByteStream;

use FFI;
use PHPUnit\Framework\TestCase;

class CDataByteReaderTest extends TestCase
{
    public function testRead()
    {
        /** @var \FFI\CArray<int> $cdata_array */
        $cdata_array = FFI::new('unsigned char[3]');
        $cdata_array[0] = 1;
        $cdata_array[1] = 2;
        $cdata_array[2] = 3;
        $reader = new CDataByteReader($cdata_array);
        $this->assertSame(1, $reader[0]);
        $this->assertSame(2, $reader[1]);
        $this->assertSame(3, $reader[2]);
    }

    public function testWrite()
    {
        /** @var \FFI\CArray<int> $cdata_array */
        $cdata_array = FFI::new('unsigned char[3]');
        $reader = new CDataByteReader($cdata_array);
        $this->expectException(\LogicException::class);
        $reader[0] = 0;
    }

    public function testOffsetExists()
    {
        /** @var \FFI\CArray<int> $cdata_array */
        $cdata_array = FFI::new('unsigned char[3]');
        $cdata_array[0] = 1;
        $reader = new CDataByteReader($cdata_array);
        $this->assertTrue(isset($reader[0]));
        $this->assertFalse(isset($reader[4]));
    }

    public function testCreateSliceAsString()
    {
        /** @var \FFI\CArray<int> $cdata_array */
        $cdata_array = FFI::new('unsigned char[4]');
        $cdata_array[0] = ord('a');
        $cdata_array[1] = ord('b');
        $cdata_array[2] = ord('c');
        $cdata_array[3] = ord('d');
        $reader = new CDataByteReader($cdata_array);
        $this->assertSame('abc', $reader->createSliceAsString(0, 3));
    }
}
