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

namespace Reli\Lib\ByteStream;

use LogicException;
use Reli\BaseTestCase;

class StringByteReaderTest extends BaseTestCase
{
    public function testRead()
    {
        $reader = new StringByteReader('abc');
        $this->assertSame(0x61, $reader[0]);
        $this->assertSame(0x62, $reader[1]);
        $this->assertSame(0x63, $reader[2]);
    }

    public function testOffsetExists()
    {
        $reader = new StringByteReader('abc');
        $this->assertTrue(isset($reader[0]));
        $this->assertFalse(isset($reader[4]));
    }

    public function testCreateSliceAsString()
    {
        $reader = new StringByteReader('abc');
        $this->assertSame(
            'bc',
            $reader->createSliceAsString(1, 2)
        );
    }

    public function testWrite()
    {
        $reader = new StringByteReader('abc');
        $this->expectException(LogicException::class);
        $reader[0] = 1;
    }
}
