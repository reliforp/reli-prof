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

class RawStringTest extends TestCase
{
    public function testGetCTypeName(): void
    {
        $cdata = \FFI::cdef()->new('char[8]');
        $raw_string = new RawString(
            new CastedCData(
                $cdata,
                $cdata,
            ),
            8,
            new Pointer(
                RawString::class,
                123,
                8,
            ),
        );
        $type_reader = new ZendTypeReader(ZendTypeReader::V82);
        $this->assertIsInt($type_reader->sizeOf($raw_string->getCTypeName()));
    }

    public function testFromCastedCData(): void
    {
        $cdata = \FFI::cdef()->new('char[3]');
        $cdata[0] = 'a';
        $cdata[1] = 'b';
        $cdata[2] = 'c';
        $casted_cdata = new CastedCData(
            $cdata,
            $cdata,
        );

        $raw_string = RawString::fromCastedCData(
            $casted_cdata,
            new Pointer(
                RawString::class,
                128,
                3,
            ),
        );

        $this->assertSame('abc', $raw_string->value);
    }

    public function testValueStopsAtEmbeddedNul(): void
    {
        // RawString is used both for zend_string::val (explicit len, may or
        // may not have a terminator within) and for fixed-size C buffers
        // whose content is NUL-terminated somewhere before the end. The
        // latter case must not leak trailing bytes after the terminator.
        $cdata = \FFI::cdef()->new('char[8]');
        $cdata[0] = 'a';
        $cdata[1] = 'b';
        $cdata[2] = 'c';
        $cdata[3] = "\0";
        $cdata[4] = 'X';
        $cdata[5] = 'X';
        $cdata[6] = 'X';
        $cdata[7] = 'X';

        $raw_string = RawString::fromCastedCData(
            new CastedCData($cdata, $cdata),
            new Pointer(RawString::class, 256, 8),
        );

        $this->assertSame('abc', $raw_string->value);
    }

    public function testGetPointer(): void
    {
        $cdata = \FFI::cdef()->new('char[1]');
        $raw_string = new RawString(
            new CastedCData(
                $cdata,
                $cdata,
            ),
            1,
            new Pointer(
                RawString::class,
                128,
                1,
            ),
        );
        $this->assertSame(128, $raw_string->getPointer()->address);
        $this->assertSame(1, $raw_string->getPointer()->size);
    }
}
