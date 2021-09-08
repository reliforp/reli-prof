<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\ByteStream;

use LogicException;
use PHPUnit\Framework\TestCase;

class StringByteReaderTest extends TestCase
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
