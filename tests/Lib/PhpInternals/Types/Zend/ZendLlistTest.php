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

namespace Reli\Lib\PhpInternals\Types\Zend;

use PHPUnit\Framework\TestCase;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;

class ZendLlistTest extends TestCase
{
    private function pokePointer(\FFI\CData $raw, int $offset, int $value): void
    {
        $packed = pack('P', $value);
        for ($b = 0; $b < 8; $b++) {
            $raw[$offset + $b] = \ord($packed[$b]);
        }
    }

    public function testExposesHeadElementAddress(): void
    {
        // caller owns $reader: the casted CData views its FFI scope.
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        $size = $reader->sizeOf('zend_llist');
        [$head_off] = $reader->getOffsetAndSizeOfMember('zend_llist', 'head');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");
        $this->pokePointer($raw, $head_off, 0x7f0000abc000);

        $casted = $reader->readAs('zend_llist', $raw);
        $llist = ZendLlist::fromCastedCData(
            $casted,
            new Pointer(ZendLlist::class, 0x1000, $size),
        );

        $this->assertSame(0x7f0000abc000, $llist->head);
    }

    public function testEmptyListHasZeroHead(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        $size = $reader->sizeOf('zend_llist');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");

        $casted = $reader->readAs('zend_llist', $raw);
        $llist = ZendLlist::fromCastedCData(
            $casted,
            new Pointer(ZendLlist::class, 0x1000, $size),
        );

        $this->assertSame(0, $llist->head);
    }
}
