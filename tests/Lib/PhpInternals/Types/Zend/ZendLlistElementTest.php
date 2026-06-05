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

class ZendLlistElementTest extends TestCase
{
    public function testExposesNextLinkAddress(): void
    {
        // caller owns $reader: the casted CData views its FFI scope.
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        $size = $reader->sizeOf('zend_llist_element');
        [$next_off] = $reader->getOffsetAndSizeOfMember('zend_llist_element', 'next');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");
        $packed = pack('P', 0x7f0000def000);
        for ($b = 0; $b < 8; $b++) {
            $raw[$next_off + $b] = \ord($packed[$b]);
        }

        $casted = $reader->readAs('zend_llist_element', $raw);
        $element = ZendLlistElement::fromCastedCData(
            $casted,
            new Pointer(ZendLlistElement::class, 0x1000, $size),
        );

        $this->assertSame(0x7f0000def000, $element->next);
    }

    public function testTailElementHasZeroNext(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        $size = $reader->sizeOf('zend_llist_element');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");

        $casted = $reader->readAs('zend_llist_element', $raw);
        $element = ZendLlistElement::fromCastedCData(
            $casted,
            new Pointer(ZendLlistElement::class, 0x1000, $size),
        );

        $this->assertSame(0, $element->next);
    }
}
