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

use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Class StringByteReaderTest
 * @package PhpProfiler\Lib\Binary
 */
class StringByteReaderTest extends TestCase
{
    public function testRead()
    {
        $reader = new StringByteReader('abc');
        $this->assertSame('a', $reader[0]);
        $this->assertSame('b', $reader[1]);
        $this->assertSame('c', $reader[2]);
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
