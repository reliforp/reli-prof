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

namespace Reli\Lib\JitAtomic;

use FFI;
use FFI\CData;

/**
 * Owns a single mmap'd executable page containing position-independent
 * x86_64 machine code for atomic hashmap primitives.
 *
 * @psalm-suppress InvalidFunctionCall
 * @psalm-suppress UndefinedPropertyAssignment
 * @psalm-suppress UndefinedPropertyFetch
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress NullableReturnStatement
 * @psalm-suppress PossiblyNullPropertyAssignment
 * @psalm-suppress PossiblyNullPropertyAssignmentValue
 * @psalm-suppress PossiblyNullPropertyFetch
 * @psalm-suppress PossiblyInvalidArgument
 * @psalm-suppress PossiblyNullArrayAccess
 * @psalm-suppress PossiblyNullReference
 * @psalm-suppress InaccessibleMethod
 * @psalm-suppress RedundantPropertyInitializationCheck
 *
 * The bytes are produced offline by gcc -O2 from src/Lib/JitAtomic/codegen/loi2.c
 * and embedded as a string constant. No runtime compiler dependency.
 *
 * Layout in the page:
 *   offset 0x00: get_or_insert(uint64_t* buckets, uint64_t mask,
 *                              uint64_t key, uint64_t value)
 *   offset 0x70: lookup_only(uint64_t* buckets, uint64_t mask, uint64_t key)
 *
 * Both return a uint64_t whose high bit (1<<63) carries metadata:
 *   get_or_insert: high bit set = newly inserted, clr = key already present;
 *                  low 63 bits = the (provided or existing) value.
 *   lookup_only:   high bit set = key found, clr (==0) = not found;
 *                  low 63 bits = the value.
 *
 * Caller-side constraints:
 *   - key=0 is reserved as empty-slot sentinel; never insert key=0.
 *     (Real memory addresses are never NULL, so this is natural.)
 *   - value's high bit (1<<63) must be 0; reli node_ids satisfy this.
 *
 * Architecture: x86_64 only. Use AtomicCodeRegion::isSupported() to gate.
 */
final class AtomicCodeRegion
{
    /**
     * 193 bytes of position-independent x86_64 machine code.
     * Produced by:
     *   gcc -O2 -fno-pie -fno-asynchronous-unwind-tables -fcf-protection=none \
     *       -ffreestanding -nostdlib -c codegen/loi2.c
     *   objcopy -O binary --only-section=.text loi2.o loi2.bin
     */
    private const CODE_BYTES =
        "\x49\x89\xf8\x48\x89\xd7\x49\x89\xf1\x48\xba\x15"
        . "\x7c\x4a\x7f\xb9\x79\x37\x9e\x48\x0f\xaf\xd7\x48"
        . "\xc1\xea\x11\x48\x21\xf2\x48\x89\xd6\x48\xc1\xe6"
        . "\x04\x4c\x01\xc6\x48\x8b\x06\x48\x39\xc7\x74\x30"
        . "\x48\x85\xc0\x75\x1b\xf0\x48\x0f\xb1\x3e\x75\x0d"
        . "\x48\x89\xc8\x48\x89\x4e\x08\x48\x0f\xba\xe8\x3f"
        . "\xc3\x48\x39\xc7\x74\x12\x66\x90\x48\x83\xc2\x01"
        . "\x4c\x21\xca\xeb\xc5\x0f\x1f\x80\x00\x00\x00\x00"
        . "\x48\x8b\x46\x08\xc3\x66\x66\x2e\x0f\x1f\x84\x00"
        . "\x00\x00\x00\x00\x48\xb9\x15\x7c\x4a\x7f\xb9\x79"
        . "\x37\x9e\x48\x0f\xaf\xca\x48\xc1\xe9\x11\x48\x21"
        . "\xf1\xeb\x15\x66\x0f\x1f\x84\x00\x00\x00\x00\x00"
        . "\x48\x85\xc0\x74\x2b\x48\x83\xc1\x01\x48\x21\xf1"
        . "\x49\x89\xc8\x49\xc1\xe0\x04\x49\x01\xf8\x49\x8b"
        . "\x00\x48\x39\xc2\x75\xe2\x49\x8b\x40\x08\x48\x0f"
        . "\xba\xe8\x3f\xc3\x0f\x1f\x84\x00\x00\x00\x00\x00"
        . "\xc3";

    private const OFFSET_GET_OR_INSERT = 0x00;
    private const OFFSET_LOOKUP_ONLY   = 0x70;

    private const PAGE_SIZE     = 4096;
    private const PROT_READ     = 1;
    private const PROT_WRITE    = 2;
    private const PROT_EXEC     = 4;
    private const MAP_PRIVATE   = 0x02;
    private const MAP_ANONYMOUS = 0x20;
    private const MAP_FAILED_INT = -1;

    public const HIGH_BIT      = 1 << 63;
    public const VALUE_MASK    = PHP_INT_MAX;

    private static ?self $instance = null;

    private FFI $libc;
    private CData $page;
    private CData $getOrInsert;
    private CData $lookupOnly;

    private function __construct()
    {
        $this->libc = FFI::cdef(
            <<<'C'
            void* mmap(void*, size_t, int, int, int, long);
            int mprotect(void*, size_t, int);
            int munmap(void*, size_t);
            typedef uint64_t (*reli_loi_fn)(uint64_t*, uint64_t, uint64_t, uint64_t);
            typedef uint64_t (*reli_lookup_fn)(uint64_t*, uint64_t, uint64_t);
            union reli_addr_u { uintptr_t i; void* p; uint64_t* up64; uint8_t* up8; };
            C,
            'libc.so.6',
        );

        $page = $this->libc->mmap(
            null,
            self::PAGE_SIZE,
            self::PROT_READ | self::PROT_WRITE,
            self::MAP_PRIVATE | self::MAP_ANONYMOUS,
            -1,
            0,
        );
        if ($this->ptrToInt($page) === self::MAP_FAILED_INT) {
            throw new \RuntimeException('AtomicCodeRegion: mmap failed');
        }

        FFI::memcpy($page, self::CODE_BYTES, strlen(self::CODE_BYTES));

        $rc = $this->libc->mprotect(
            $page,
            self::PAGE_SIZE,
            self::PROT_READ | self::PROT_EXEC,
        );
        if ($rc !== 0) {
            $this->libc->munmap($page, self::PAGE_SIZE);
            throw new \RuntimeException('AtomicCodeRegion: mprotect to RX failed');
        }
        $this->page = $page;

        /** @var CData $bytes */
        $bytes = $this->libc->cast('uint8_t*', $page);
        $this->getOrInsert = $this->libc->cast(
            'reli_loi_fn',
            FFI::addr($bytes[self::OFFSET_GET_OR_INSERT]),
        );
        $this->lookupOnly = $this->libc->cast(
            'reli_lookup_fn',
            FFI::addr($bytes[self::OFFSET_LOOKUP_ONLY]),
        );
    }

    public function __destruct()
    {
        if (isset($this->page)) {
            $this->libc->munmap($this->page, self::PAGE_SIZE);
        }
    }

    /**
     * Whether the runtime supports loading the JIT atomic primitives.
     *
     * Currently x86_64 + Linux + FFI-enabled.
     * Other arches (aarch64) need their own machine-code blob.
     */
    public static function isSupported(): bool
    {
        if (!extension_loaded('ffi')) {
            return false;
        }
        $arch = php_uname('m');
        if ($arch !== 'x86_64') {
            return false;
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }
        return true;
    }

    /**
     * Lazy singleton: there is no benefit to multiple code regions
     * (the bytes are immutable), and the mmap'd page is small.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function getOrInsert(): CData
    {
        return $this->getOrInsert;
    }

    public function lookupOnly(): CData
    {
        return $this->lookupOnly;
    }

    public function ffi(): FFI
    {
        return $this->libc;
    }

    private function ptrToInt(CData $ptr): int
    {
        $u = $this->libc->new('union reli_addr_u');
        $u->p = $this->libc->cast('void*', $ptr);
        return $u->i;
    }
}
