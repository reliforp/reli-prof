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

use Reli\BaseTestCase;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;

class PointerArrayTest extends BaseTestCase
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
        $casted_cdata = new CastedCData(
            $cdata,
            $cdata,
        );

        $pointer_array1 = PointerArray::fromCastedCData(
            $casted_cdata,
            new Pointer(
                PointerArray::class,
                123,
                8,
            ),
        );
        $pointer_array2 = PointerArray::fromCastedCData(
            $casted_cdata,
            new Pointer(
                PointerArray::class,
                123,
                64,
            ),
        );

        $this->assertSame(1, $pointer_array1->countElements());
        $this->assertSame(8, $pointer_array2->countElements());
    }

    public function testCanGetOriginalPointer(): void
    {
        $cdata = \FFI::cdef()->new('intptr_t[1]');
        $casted_cdata = new CastedCData(
            $cdata,
            $cdata,
        );

        $pointer_array1 = PointerArray::fromCastedCData(
            $casted_cdata,
            new Pointer(
                PointerArray::class,
                123,
                8,
            ),
        );
        $pointer = $pointer_array1->getPointer();
        $this->assertSame(123, $pointer->address);
        $this->assertSame(8, $pointer->size);
    }

    public function testIsInRange(): void
    {
        $cdata = \FFI::cdef()->new('intptr_t[1]');
        $casted_cdata = new CastedCData(
            $cdata,
            $cdata,
        );

        $pointer_array1 = PointerArray::fromCastedCData(
            $casted_cdata,
            new Pointer(
                PointerArray::class,
                123,
                8,
            ),
        );
        $this->assertTrue($pointer_array1->isInRange(0));
        $this->assertFalse($pointer_array1->isInRange(1));
    }

    public function testGetIteratorOfPointersTo(): void
    {
        $cdata = \FFI::cdef()->new('intptr_t[2]');
        $casted_cdata = new CastedCData(
            $cdata,
            $cdata,
        );
        $cdata[0] = 123;
        $cdata[1] = 456;

        $pointer_array1 = PointerArray::fromCastedCData(
            $casted_cdata,
            new Pointer(
                PointerArray::class,
                123,
                16,
            ),
        );
        $type_reader = new ZendTypeReader(ZendTypeReader::V82);
        $iterator = $pointer_array1->getIteratorOfPointersTo(
            RawDouble::class,
            $type_reader,
        );
        $array = iterator_to_array($iterator);
        $this->assertCount(2, $array);
    }

    public function testCreatePointerToArray(): void
    {
        $pointer_array = PointerArray::createPointerToArray(
            128,
            2,
        );
        $this->assertSame(128, $pointer_array->address);
        $this->assertSame(16, $pointer_array->size);
    }
}
