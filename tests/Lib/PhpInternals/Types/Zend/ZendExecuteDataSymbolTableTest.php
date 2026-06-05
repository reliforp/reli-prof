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

class ZendExecuteDataSymbolTableTest extends TestCase
{
    private const ZEND_CALL_HAS_SYMBOL_TABLE = 1 << 20; // 7.4+/8.x effective bit

    /**
     * Build a zend_execute_data with the given This.u1.type_info call_info and
     * symbol_table pointer poked into a zeroed buffer.
     *
     * The caller owns $reader: the casted CData is a view into its FFI scope.
     */
    private function makeExecuteData(
        ZendTypeReader $reader,
        string $version,
        int $call_info,
        int $symbol_table_ptr,
    ): ZendExecuteData {
        $size = $reader->sizeOf('zend_execute_data');
        [$this_offset] = $reader->getOffsetAndSizeOfMember('zend_execute_data', 'This');
        [$st_offset] = $reader->getOffsetAndSizeOfMember('zend_execute_data', 'symbol_table');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");

        // This.u1.type_info: the 4 bytes after the 8-byte zval value.
        $type_info = pack('V', $call_info);
        for ($b = 0; $b < 4; $b++) {
            $raw[$this_offset + 8 + $b] = \ord($type_info[$b]);
        }
        // symbol_table pointer (8 bytes).
        $st = pack('P', $symbol_table_ptr);
        for ($b = 0; $b < 8; $b++) {
            $raw[$st_offset + $b] = \ord($st[$b]);
        }

        $casted = $reader->readAs('zend_execute_data', $raw);
        return ZendExecuteData::fromCastedCDataWithResolver(
            $casted,
            new Pointer(ZendExecuteData::class, 0x1000, $size),
            new VersionedPointedTypeResolver($version),
        );
    }

    public function testFlagVersionUsesTypeInfoBitNotPointer(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V82);

        // flag set -> true regardless of the (here null) pointer
        $with_flag = $this->makeExecuteData($reader, ZendTypeReader::V82, self::ZEND_CALL_HAS_SYMBOL_TABLE, 0);
        $this->assertTrue($with_flag->hasSymbolTable($reader));

        // A non-null symbol_table pointer WITHOUT the flag must NOT count on
        // 7.1+: the slot is reused from EG(symtable_cache), which is the whole
        // reason the flag exists.
        $stale_pointer = $this->makeExecuteData($reader, ZendTypeReader::V82, 0, 0x7f0000001000);
        $this->assertFalse($stale_pointer->hasSymbolTable($reader));
    }

    public function testPre71FallsBackToSymbolTablePointer(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V70);

        // 7.0 has no flag; a non-null symbol_table pointer is the indicator.
        $with_pointer = $this->makeExecuteData($reader, ZendTypeReader::V70, 0, 0x7f0000001000);
        $this->assertTrue($with_pointer->hasSymbolTable($reader));

        // No symbol table -> null pointer -> false.
        $without = $this->makeExecuteData($reader, ZendTypeReader::V70, 0, 0);
        $this->assertFalse($without->hasSymbolTable($reader));
    }
}
