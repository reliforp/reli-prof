<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Binary;

use FFI;
use PHPUnit\Framework\TestCase;

class CDataByteReaderTest extends TestCase
{
    public function testRead()
    {
        /** @var \FFI\CArray $cdata_array */
        $cdata_array = FFI::new('char[3]');
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
        /** @var \FFI\CArray $cdata_array */
        $cdata_array = FFI::new('char[3]');
        $reader = new CDataByteReader($cdata_array);
        $this->expectException(\LogicException::class);
        $reader[0] = 0;
    }
}
