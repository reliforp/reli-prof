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
 * Picks the right AddressMap implementation for the current runtime.
 *
 * Prefers JitAtomicAddressMap when the platform supports it
 * (Linux x86_64 + FFI + executable mmap allowed). Falls back to
 * PhpArrayAddressMap on:
 *   - non-x86_64 architectures (until aarch64 codegen lands)
 *   - environments without ext-ffi
 *   - sandboxes that deny PROT_EXEC anonymous mmap (some hardened
 *     containers, PaX kernels, certain CI runners)
 */
final class AddressMapFactory
{
    public static function create(int $jit_capacity = 1 << 20): AddressMap
    {
        if (AtomicCodeRegion::isSupported()) {
            try {
                return new JitAtomicAddressMap($jit_capacity);
            } catch (\RuntimeException) {
                // mmap PROT_EXEC denied or similar setup failure;
                // fall through to the universal fallback below.
            }
        }
        return new PhpArrayAddressMap();
    }
}
