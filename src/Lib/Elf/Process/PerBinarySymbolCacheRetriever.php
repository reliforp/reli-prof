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

namespace Reli\Lib\Elf\Process;

use Reli\Lib\Elf\SymbolResolver\Elf64SymbolCache;

final class PerBinarySymbolCacheRetriever
{
    /** @var array<string, Elf64SymbolCache> */
    private array $cache = [];

    public function get(BinaryFingerprint $binary_fingerprint): Elf64SymbolCache
    {
        if (!isset($this->cache[(string)$binary_fingerprint])) {
            $this->cache[(string)$binary_fingerprint] = new Elf64SymbolCache();
        }
        return $this->cache[(string)$binary_fingerprint];
    }
}
