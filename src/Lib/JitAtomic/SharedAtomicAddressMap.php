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

/**
 * AddressMap whose backing buckets live in mmap(MAP_SHARED|MAP_ANONYMOUS)
 * memory, so the same map can be addressed from a forked child or any
 * other process/thread that received bucketsAddress() and the matching
 * code-page addresses.
 *
 * @psalm-suppress InvalidFunctionCall
 * @psalm-suppress UndefinedPropertyAssignment
 * @psalm-suppress UndefinedPropertyFetch
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedOperand
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress NullableReturnStatement
 * @psalm-suppress PossiblyNullPropertyAssignment
 * @psalm-suppress PossiblyNullPropertyFetch
 * @psalm-suppress PossiblyNullOperand
 * @psalm-suppress PossiblyInvalidArgument
 * @psalm-suppress MissingOverrideAttribute
 * @psalm-suppress PropertyNotSetInConstructor
 *
 * The implementation is intentionally NOT layered on top of
 * JitAtomicHashMap. JitAtomicHashMap holds CData properties as typed
 * `FFI\CData`, and dispatching a function pointer through a typed
 * property in a forked child reliably hangs (the call enters libffi and
 * never returns; reproduces with PHP 8.5.5 + ext-ffi 8.5.5). This class
 * keeps every CData strictly local in method bodies and stores only
 * integer addresses in properties, sidestepping the bug.
 *
 * Same key/value sentinels as JitAtomicHashMap:
 *   - key=0 reserved as empty-slot sentinel.
 *   - value high bit reserved by the JIT'd machine code.
 *
 * Use SharedAtomicAddressMap::createOwning() to allocate (host side),
 * then call descriptor() to get the integer-address tuple to pass to
 * children, who reconstruct via attach().
 */
final class SharedAtomicAddressMap implements AddressMap
{
    private const PROT_READ_WRITE = 3;
    private const PROT_READ_EXEC  = 5;
    private const MAP_SHARED      = 0x01;
    private const MAP_PRIVATE     = 0x02;
    private const MAP_ANONYMOUS   = 0x20;

    private const CDEF = <<<'C'
        void* mmap(void*, size_t, int, int, int, long);
        int mprotect(void*, size_t, int);
        int munmap(void*, size_t);
        typedef uint64_t (*reli_loi_fn)(uint64_t*, uint64_t, uint64_t, uint64_t);
        typedef uint64_t (*reli_lookup_fn)(uint64_t*, uint64_t, uint64_t);
        union reli_addr_u { uintptr_t i; void* p; uint64_t* up64; uint8_t* up8; };
        C;

    private int $bucketsAddr = 0;
    private int $codeAddr    = 0;
    private int $lookupAddr  = 0;
    private int $loiAddr     = 0;
    private int $bucketsBytes = 0;
    private int $capacity;
    private int $mask;
    /** Whether this instance owns the mmaps and should munmap on shutdown. */
    private bool $owning;

    private function __construct() {}

    /**
     * Host-side factory: allocates shared bucket region + JIT atomic code
     * page, returns an owning instance. Pass descriptor() to children.
     */
    public static function createOwning(int $capacity): self
    {
        if ($capacity <= 0 || ($capacity & ($capacity - 1)) !== 0) {
            throw new \InvalidArgumentException(
                "SharedAtomicAddressMap capacity must be a positive power of 2 (got {$capacity})",
            );
        }
        if (!AtomicCodeRegion::isSupported()) {
            throw new \RuntimeException(
                'SharedAtomicAddressMap requires Linux x86_64 + FFI; '
                . 'arch=' . php_uname('m'),
            );
        }

        $libc = \FFI::cdef(self::CDEF, 'libc.so.6');

        // Buckets: MAP_SHARED so forked children see same memory
        $bucketsBytes = 2 * $capacity * 8;
        $bucketsPage = $libc->mmap(
            null, $bucketsBytes, self::PROT_READ_WRITE,
            self::MAP_SHARED | self::MAP_ANONYMOUS, -1, 0,
        );
        $u = $libc->new('union reli_addr_u');
        $u->p = $libc->cast('void*', $bucketsPage);
        $bucketsAddr = $u->i;
        if ($bucketsAddr === -1) {
            throw new \RuntimeException('mmap MAP_SHARED for buckets failed');
        }

        // Code: MAP_PRIVATE; child gets COW copy, same VA, fine
        $codePage = $libc->mmap(
            null, 4096, self::PROT_READ_WRITE,
            self::MAP_PRIVATE | self::MAP_ANONYMOUS, -1, 0,
        );
        \FFI::memcpy($codePage, AtomicCodeRegion::CODE_BYTES, strlen(AtomicCodeRegion::CODE_BYTES));
        $libc->mprotect($codePage, 4096, self::PROT_READ_EXEC);
        $u->p = $libc->cast('void*', $codePage);
        $codeAddr = $u->i;
        $loiAddr    = $codeAddr + AtomicCodeRegion::OFFSET_GET_OR_INSERT;
        $lookupAddr = $codeAddr + AtomicCodeRegion::OFFSET_LOOKUP_ONLY;

        $self = new self();
        $self->bucketsAddr  = $bucketsAddr;
        $self->bucketsBytes = $bucketsBytes;
        $self->codeAddr     = $codeAddr;
        $self->loiAddr      = $loiAddr;
        $self->lookupAddr   = $lookupAddr;
        $self->capacity     = $capacity;
        $self->mask         = $capacity - 1;
        $self->owning       = true;
        return $self;
    }

    /**
     * Reconstruct a non-owning view from the integer descriptor produced
     * by createOwning()->descriptor(). Use from a forked child or any
     * peer that shares the parent's address space.
     *
     * @param array{buckets:int,loi:int,lookup:int,capacity:int} $desc
     */
    public static function attach(array $desc): self
    {
        $self = new self();
        $self->bucketsAddr = $desc['buckets'];
        $self->loiAddr     = $desc['loi'];
        $self->lookupAddr  = $desc['lookup'];
        $self->capacity    = $desc['capacity'];
        $self->mask        = $desc['capacity'] - 1;
        $self->owning      = false;
        return $self;
    }

    /** @return array{buckets:int,loi:int,lookup:int,capacity:int} */
    public function descriptor(): array
    {
        return [
            'buckets'  => $this->bucketsAddr,
            'loi'      => $this->loiAddr,
            'lookup'   => $this->lookupAddr,
            'capacity' => $this->capacity,
        ];
    }

    #[\Override]
    public function has(int $address): bool
    {
        $libc = \FFI::cdef(self::CDEF);
        $u1 = $libc->new('union reli_addr_u'); $u1->i = $this->bucketsAddr;
        $buckets = $libc->cast('uint64_t*', $u1->p);
        $u2 = $libc->new('union reli_addr_u'); $u2->i = $this->lookupAddr;
        $lookup = $libc->cast('reli_lookup_fn', $u2->p);
        return $lookup($buckets, $this->mask, $address) !== 0;
    }

    #[\Override]
    public function get(int $address): ?int
    {
        $libc = \FFI::cdef(self::CDEF);
        $u1 = $libc->new('union reli_addr_u'); $u1->i = $this->bucketsAddr;
        $buckets = $libc->cast('uint64_t*', $u1->p);
        $u2 = $libc->new('union reli_addr_u'); $u2->i = $this->lookupAddr;
        $lookup = $libc->cast('reli_lookup_fn', $u2->p);
        $r = $lookup($buckets, $this->mask, $address);
        return $r === 0 ? null : ($r & PHP_INT_MAX);
    }

    #[\Override]
    public function set(int $address, int $node_id): void
    {
        $libc = \FFI::cdef(self::CDEF);
        $u1 = $libc->new('union reli_addr_u'); $u1->i = $this->bucketsAddr;
        $buckets = $libc->cast('uint64_t*', $u1->p);
        $u2 = $libc->new('union reli_addr_u'); $u2->i = $this->loiAddr;
        $loi = $libc->cast('reli_loi_fn', $u2->p);
        $loi($buckets, $this->mask, $address, $node_id);
    }

    /** @return int|null  null when newly inserted, otherwise the prior value. */
    public function setIfAbsent(int $address, int $node_id): ?int
    {
        $libc = \FFI::cdef(self::CDEF);
        $u1 = $libc->new('union reli_addr_u'); $u1->i = $this->bucketsAddr;
        $buckets = $libc->cast('uint64_t*', $u1->p);
        $u2 = $libc->new('union reli_addr_u'); $u2->i = $this->loiAddr;
        $loi = $libc->cast('reli_loi_fn', $u2->p);
        $r = $loi($buckets, $this->mask, $address, $node_id);
        return ($r & PHP_INT_MIN) !== 0 ? null : ($r & PHP_INT_MAX);
    }
}
