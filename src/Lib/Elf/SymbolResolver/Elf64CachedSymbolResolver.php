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

namespace Reli\Lib\Elf\SymbolResolver;

use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use Reli\Lib\Integer\UInt64;

final class Elf64CachedSymbolResolver implements Elf64SymbolResolver
{
    public function __construct(
        private Elf64SymbolResolver $resolver,
        private Elf64SymbolCache $symbol_cache,
    ) {
    }

    public function resolve(string $symbol_name): Elf64SymbolTableEntry
    {
        if (!$this->symbol_cache->has($symbol_name)) {
            $this->symbol_cache->set(
                $symbol_name,
                $this->resolver->resolve($symbol_name),
            );
        }
        return $this->symbol_cache->get($symbol_name);
    }

    public function getDtDebugAddress(): ?int
    {
        return $this->resolver->getDtDebugAddress();
    }

    public function getBaseAddress(): UInt64
    {
        return $this->resolver->getBaseAddress();
    }
}
