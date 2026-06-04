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
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;

class ZendExecuteDataClosureTest extends TestCase
{
    private const ZEND_CALL_CLOSURE = 1 << 22;

    /** Build a zend_execute_data whose This.u1.type_info holds $call_info. */
    private function makeExecuteData(ZendTypeReader $reader, int $call_info): ZendExecuteData
    {
        $size = $reader->sizeOf('zend_execute_data');
        [$this_offset] = $reader->getOffsetAndSizeOfMember('zend_execute_data', 'This');
        // This is a zval; its u1.type_info is the 4 bytes after the 8-byte value.
        $type_info_offset = $this_offset + 8;
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");
        $packed = pack('V', $call_info);
        for ($b = 0; $b < 4; $b++) {
            $raw[$type_info_offset + $b] = \ord($packed[$b]);
        }
        $casted = $reader->readAs('zend_execute_data', $raw);
        return ZendExecuteData::fromCastedCDataWithResolver(
            $casted,
            new Pointer(ZendExecuteData::class, 0x1000, $size),
            new VersionedPointedTypeResolver(ZendTypeReader::V82),
        );
    }

    public function testIsClosureCallWhenFlagSet(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        // flag set alongside unrelated call-info bits — still a closure call.
        $execute_data = $this->makeExecuteData($reader, self::ZEND_CALL_CLOSURE | (1 << 20));
        $this->assertTrue($execute_data->isClosureCall());
    }

    public function testNotClosureCallWhenFlagClear(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        // neighbouring bits set, but not ZEND_CALL_CLOSURE.
        $execute_data = $this->makeExecuteData($reader, (1 << 21) | (1 << 23));
        $this->assertFalse($execute_data->isClosureCall());
    }
}
