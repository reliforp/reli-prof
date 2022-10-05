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

use ArrayAccess;
use LogicException;
use PHPUnit\Framework\TestCase;

class ByteReaderDisableWriteAccessTraitTest extends TestCase
{
    public function testOffsetSet()
    {
        $this->expectException(LogicException::class);
        $instance = new class implements ArrayAccess {
            use ByteReaderDisableWriteAccessTrait;

            public function offsetGet($offset)
            {
                return 0xDEADBEAF;
            }

            public function offsetExists($offset)
            {
                false;
            }
        };
        $instance[0] = 1;
    }

    public function testOffsetUnset()
    {
        $this->expectException(LogicException::class);
        $instance = new class implements ArrayAccess {
            use ByteReaderDisableWriteAccessTrait;

            public function offsetGet($offset)
            {
                return 0xDEADBEAF;
            }

            public function offsetExists($offset)
            {
                false;
            }
        };
        unset($instance[0]);
    }
}
