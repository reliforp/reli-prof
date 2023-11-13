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

namespace Reli\Lib\PhpInternals\Types\C;

use PHPUnit\Framework\TestCase;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;

class PointerArrayTest extends TestCase
{
    public function testGetCTypeName(): void
    {
        $cdata = \FFI::cdef()->new('intptr_t[1]');
        $pointer_array = new PointerArray(
            new CastedCData(
                $cdata,
                $cdata,
            ),
            new Pointer(
                PointerArray::class,
                123,
                456,
            ),
        );
        $type_reader = new ZendTypeReader(ZendTypeReader::V82);
        $this->assertIsInt($type_reader->sizeOf($pointer_array->getCTypeName()));
    }

    public function testFromCastedCData(): void
    {
        $cdata = \FFI::cdef()->new('intptr_t[1]');

        $pointer_array1 = PointerArray::fromCastedCData(
            new CastedCData(
                $cdata,
                $cdata,
            ),
            new Pointer(
                PointerArray::class,
                123,
                8,
            ),
        );

        $this->assertSame(1, $pointer_array1->countElements());
    }
}
