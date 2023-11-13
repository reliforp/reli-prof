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

class RawDoubleTest extends TestCase
{
    public function testGetCTypeName(): void
    {
        $cdata = \FFI::cdef()->new('double');
        $raw_double = new RawDouble(
            new CastedCData(
                $cdata,
                $cdata,
            ),
            new Pointer(
                RawDouble::class,
                123,
                8,
            ),
        );
        $type_reader = new ZendTypeReader(ZendTypeReader::V82);
        $this->assertIsInt($type_reader->sizeOf($raw_double->getCTypeName()));
    }

    public function testFromCastedCData(): void
    {
        $cdata = \FFI::cdef()->new('double');
        $cdata->cdata = 123.456;
        $casted_cdata = new CastedCData(
            $cdata,
            $cdata,
        );

        $raw_double = RawDouble::fromCastedCData(
            $casted_cdata,
            new Pointer(
                RawDouble::class,
                128,
                8,
            ),
        );

        $this->assertSame(123.456, $raw_double->value);
    }

    public function testGetPointer(): void
    {
        $cdata = \FFI::cdef()->new('double');
        $raw_double = new RawDouble(
            new CastedCData(
                $cdata,
                $cdata,
            ),
            new Pointer(
                RawDouble::class,
                128,
                8,
            ),
        );
        $this->assertSame(128, $raw_double->getPointer()->address);
        $this->assertSame(8, $raw_double->getPointer()->size);
    }
}
