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

namespace Reli\Lib\PhpInternals;

use PHPUnit\Framework\Attributes\Test;
use Reli\BaseTestCase;
use Reli\Lib\PhpInternals\Types\C\RawInt64;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

class ResolveMapPtrTest extends BaseTestCase
{
    /**
     * PHP 7.4 has no ZEND_MAP_PTR_BIASED_BASE.
     * map_ptr_base is the raw base address, so the LSB
     * flag in the offset must be masked before adding.
     */
    #[Test]
    public function testV74MasksLsbFromOffset(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V74);

        $base = 0x7f0000100000;       // unbiased base
        $real_offset = 0x200;          // true offset
        $encoded = $real_offset | 1;   // MAP_PTR encoded (0x201)

        $expected_address = $base + $real_offset; // 0x7f0000100200
        $stored_value = 0xdeadbeef;

        $dereferencer = $this->createStubDereferencer(
            $expected_address,
            $stored_value,
        );

        $result = $reader->resolveMapPtr(
            $base,
            $encoded,
            $dereferencer,
        );
        $this->assertSame($stored_value, $result);
    }

    /**
     * PHP 8.0 introduced biased base (commit 27815959 in php-src).
     * map_ptr_base = real_base - 1, so no masking needed.
     */
    #[Test]
    public function testV80UsesBiasedBaseWithoutMask(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V80);

        $real_base = 0x7f0000100000;
        $biased_base = $real_base - 1;
        $real_offset = 0x400;
        $encoded = $real_offset | 1;

        $expected_address = $real_base + $real_offset;
        $stored_value = 0xcafebabe;

        $dereferencer = $this->createStubDereferencer(
            $expected_address,
            $stored_value,
        );

        $result = $reader->resolveMapPtr(
            $biased_base,
            $encoded,
            $dereferencer,
        );
        $this->assertSame($stored_value, $result);
    }

    /**
     * PHP 8.1+ uses ZEND_MAP_PTR_BIASED_BASE (map_ptr_base =
     * real_base - 1), so LSB must NOT be masked — the -1 bias
     * in the base cancels out the |1 in the offset.
     */
    #[Test]
    public function testV81UsesBiasedBaseWithoutMask(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V81);

        $real_base = 0x7f0000100000;
        $biased_base = $real_base - 1;  // BIASED_BASE
        $real_offset = 0x200;
        $encoded = $real_offset | 1;    // 0x201

        // biased_base + encoded = (real_base-1) + (offset|1)
        //                       = real_base + offset
        $expected_address = $real_base + $real_offset;
        $stored_value = 0x12345678;

        $dereferencer = $this->createStubDereferencer(
            $expected_address,
            $stored_value,
        );

        $result = $reader->resolveMapPtr(
            $biased_base,
            $encoded,
            $dereferencer,
        );
        $this->assertSame($stored_value, $result);
    }

    #[Test]
    public function testV84UsesBiasedBaseWithoutMask(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V84);

        $real_base = 0x7f0000200000;
        $biased_base = $real_base - 1;
        $real_offset = 0x800;
        $encoded = $real_offset | 1;

        $expected_address = $real_base + $real_offset;
        $stored_value = 0xaabbccdd;

        $dereferencer = $this->createStubDereferencer(
            $expected_address,
            $stored_value,
        );

        $result = $reader->resolveMapPtr(
            $biased_base,
            $encoded,
            $dereferencer,
        );
        $this->assertSame($stored_value, $result);
    }

    /**
     * base=0, LSB=0: direct pointer, no resolution needed.
     */
    #[Test]
    public function testBaseZeroLsbZeroReturnsDirectly(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V74);
        $dereferencer = \Mockery::mock(Dereferencer::class);

        $result = $reader->resolveMapPtr(
            0,
            0x12345678,
            $dereferencer,
        );
        $this->assertSame(0x12345678, $result);
    }

    /**
     * PHP 7.4 with base=0 and LSB=1.
     * PTR2OFFSET with base=0: (ptr - 0) | 1 = ptr | 1
     * OFFSET2PTR with base=0: 0 + (ptr|1) - 1 = ptr
     * resolveMapPtr must NOT early-return for base=0 when LSB=1.
     */
    #[Test]
    public function testV74BaseZeroLsbOneStillResolves(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V74);

        $slot_addr = 0x55a000100200; // aligned MAP_PTR slot
        $encoded = $slot_addr | 1;   // PTR2OFFSET(slot) with base=0
        $stored_value = 0xdeadbeef;  // value stored at the slot

        // base + (encoded & ~1) = 0 + slot_addr = slot_addr
        $dereferencer = $this->createStubDereferencer(
            $slot_addr,
            $stored_value,
        );

        $result = $reader->resolveMapPtr(
            0,
            $encoded,
            $dereferencer,
        );
        $this->assertSame($stored_value, $result);
    }

    #[Test]
    public function testLsbZeroFallsThroughToDoubleDeref(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V80);

        $direct_ptr = 0x7f0000300000; // LSB=0 (aligned)
        $table_address = 0x7f0000400000;

        $dereferencer = $this->createStubDereferencer(
            $direct_ptr,
            $table_address,
        );

        $result = $reader->resolveMapPtr(
            0x7f0000100000, // non-zero base
            $direct_ptr,
            $dereferencer,
        );
        $this->assertSame($table_address, $result);
    }

    private function createStubDereferencer(
        int $expected_address,
        int $return_value,
    ): Dereferencer {
        $test = $this;
        return new class (
            $expected_address,
            $return_value,
            $test,
        ) implements Dereferencer {
            public function __construct(
                private int $expected_address,
                private int $return_value,
                private ResolveMapPtrTest $test,
            ) {
            }

            public function deref(Pointer $pointer): mixed
            {
                $this->test->assertSame(
                    $this->expected_address,
                    $pointer->address,
                    sprintf(
                        'Expected deref at 0x%x, got 0x%x',
                        $this->expected_address,
                        $pointer->address,
                    ),
                );
                return new class ($this->return_value) {
                    public int $value;
                    public function __construct(int $v)
                    {
                        $this->value = $v;
                    }
                };
            }
        };
    }
}
