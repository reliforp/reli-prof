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

class RawInt64Test extends TestCase
{
    public function testGetCTypeName(): void
    {
        $cdata = \FFI::cdef()->new('int64_t');
        $rawint64 = new RawInt64(
            new CastedCData(
                $cdata,
                $cdata,
            ),
            new Pointer(
                RawInt64::class,
                123,
                8,
            ),
        );
        $type_reader = new ZendTypeReader(ZendTypeReader::V82);
        $this->assertIsInt($type_reader->sizeOf($rawint64->getCTypeName()));
    }

    public function testFromCastedCData(): void
    {
        $cdata = \FFI::cdef()->new('int64_t');
        $cdata->cdata = 123;
        $casted_cdata = new CastedCData(
            $cdata,
            $cdata,
        );

        $rawint64 = RawInt64::fromCastedCData(
            $casted_cdata,
            new Pointer(
                RawInt64::class,
                128,
                8,
            ),
        );

        $this->assertSame(123, $rawint64->value);
    }

    public function testGetPointer(): void
    {
        $cdata = \FFI::cdef()->new('int64_t');
        $rawint64 = new RawInt64(
            new CastedCData(
                $cdata,
                $cdata,
            ),
            new Pointer(
                RawInt64::class,
                123,
                8,
            ),
        );
        $this->assertSame(123, $rawint64->getPointer()->address);
        $this->assertSame(8, $rawint64->getPointer()->size);
    }
}
