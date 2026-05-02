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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector;

use Reli\Lib\Dwarf\NativeSymbolResolver;

/**
 * {@see SymbolResolverInterface} backed by reli's call-trace
 * {@see NativeSymbolResolver}. Reuses the same ELF symbol-table
 * parser, per-binary cache, and base-address calculation that the
 * native trace path already paid the engineering cost for.
 *
 * Defensive: NativeSymbolResolver can throw on malformed binaries
 * or unreadable files; the adapter swallows those into null so a
 * single bad module doesn't abort the per-slot scan.
 */
final class NativeSymbolResolverAdapter implements SymbolResolverInterface
{
    public function __construct(
        private NativeSymbolResolver $resolver,
    ) {
    }

    #[\Override]
    public function resolve(int $address): ?array
    {
        try {
            return $this->resolver->resolve($address);
        } catch (\Throwable) {
            return null;
        }
    }
}
