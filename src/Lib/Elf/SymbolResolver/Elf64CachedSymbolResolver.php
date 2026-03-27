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

use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprint;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use Reli\Lib\Integer\UInt64;

final class Elf64CachedSymbolResolver implements Elf64SymbolResolver
{
    private ?UInt64 $base_address_cache = null;
    private ?int $dt_debug_address_cache = null;
    private bool $dt_debug_resolved = false;
    private bool $base_address_resolved = false;

    public function __construct(
        private Elf64SymbolResolver $resolver,
        private Elf64SymbolCache $symbol_cache,
        private ?BinaryAnalysisCache $binary_analysis_cache = null,
        private ?BinaryFingerprint $fingerprint = null,
    ) {
    }

    #[\Override]
    public function resolve(string $symbol_name): Elf64SymbolTableEntry
    {
        if ($this->symbol_cache->has($symbol_name)) {
            return $this->symbol_cache->get($symbol_name);
        }

        $entry = $this->resolveFromFileCache($symbol_name);
        if ($entry !== null) {
            $this->symbol_cache->set($symbol_name, $entry);
            return $entry;
        }

        $entry = $this->resolver->resolve($symbol_name);
        $this->symbol_cache->set($symbol_name, $entry);
        $this->saveSymbolToFileCache($symbol_name, $entry);
        return $entry;
    }

    #[\Override]
    public function getDtDebugAddress(): ?int
    {
        if ($this->dt_debug_resolved) {
            return $this->dt_debug_address_cache;
        }

        $cached = $this->getResolverCache();
        if ($cached !== null && array_key_exists('dt_debug_address', $cached)) {
            $this->dt_debug_address_cache = is_int($cached['dt_debug_address'])
                ? $cached['dt_debug_address']
                : null;
            $this->dt_debug_resolved = true;
            return $this->dt_debug_address_cache;
        }

        $this->dt_debug_address_cache = $this->resolver->getDtDebugAddress();
        $this->dt_debug_resolved = true;
        $this->saveResolverMeta();
        return $this->dt_debug_address_cache;
    }

    #[\Override]
    public function getBaseAddress(): UInt64
    {
        if ($this->base_address_resolved) {
            return $this->base_address_cache ?? new UInt64(0, 0);
        }

        $cached = $this->getResolverCache();
        if (
            $cached !== null
            && isset($cached['base_address_hi'], $cached['base_address_lo'])
            && is_int($cached['base_address_hi'])
            && is_int($cached['base_address_lo'])
        ) {
            $this->base_address_cache = new UInt64($cached['base_address_hi'], $cached['base_address_lo']);
            $this->base_address_resolved = true;
            return $this->base_address_cache;
        }

        $this->base_address_cache = $this->resolver->getBaseAddress();
        $this->base_address_resolved = true;
        $this->saveResolverMeta();
        return $this->base_address_cache;
    }

    private function resolveFromFileCache(string $symbol_name): ?Elf64SymbolTableEntry
    {
        if ($this->binary_analysis_cache === null || $this->fingerprint === null) {
            return null;
        }
        $cached = $this->binary_analysis_cache->get($this->fingerprint, 'elf_symbols');
        if ($cached === null || !isset($cached[$symbol_name]) || !is_array($cached[$symbol_name])) {
            return null;
        }
        return $this->deserializeSymbol($cached[$symbol_name]);
    }

    private function saveSymbolToFileCache(string $symbol_name, Elf64SymbolTableEntry $entry): void
    {
        if ($this->binary_analysis_cache === null || $this->fingerprint === null) {
            return;
        }
        $cached = $this->binary_analysis_cache->get($this->fingerprint, 'elf_symbols') ?? [];
        $cached[$symbol_name] = $this->serializeSymbol($entry);
        $this->binary_analysis_cache->set($this->fingerprint, 'elf_symbols', $cached);
    }

    /** @return array<string, int> */
    private function serializeSymbol(Elf64SymbolTableEntry $entry): array
    {
        return [
            'st_name' => $entry->st_name,
            'st_info' => $entry->st_info,
            'st_other' => $entry->st_other,
            'st_shndx' => $entry->st_shndx,
            'st_value_hi' => $entry->st_value->hi,
            'st_value_lo' => $entry->st_value->lo,
            'st_size_hi' => $entry->st_size->hi,
            'st_size_lo' => $entry->st_size->lo,
        ];
    }

    /** @param array<array-key, mixed> $data */
    private function deserializeSymbol(array $data): ?Elf64SymbolTableEntry
    {
        if (
            !isset(
                $data['st_name'],
                $data['st_info'],
                $data['st_other'],
                $data['st_shndx'],
                $data['st_value_hi'],
                $data['st_value_lo'],
                $data['st_size_hi'],
                $data['st_size_lo'],
            )
            || !is_int($data['st_name'])
            || !is_int($data['st_info'])
            || !is_int($data['st_other'])
            || !is_int($data['st_shndx'])
            || !is_int($data['st_value_hi'])
            || !is_int($data['st_value_lo'])
            || !is_int($data['st_size_hi'])
            || !is_int($data['st_size_lo'])
        ) {
            return null;
        }
        return new Elf64SymbolTableEntry(
            $data['st_name'],
            $data['st_info'],
            $data['st_other'],
            $data['st_shndx'],
            new UInt64($data['st_value_hi'], $data['st_value_lo']),
            new UInt64($data['st_size_hi'], $data['st_size_lo']),
        );
    }

    /** @return array<array-key, mixed>|null */
    private function getResolverCache(): ?array
    {
        if ($this->binary_analysis_cache === null || $this->fingerprint === null) {
            return null;
        }
        return $this->binary_analysis_cache->get($this->fingerprint, 'elf_resolver');
    }

    private function saveResolverMeta(): void
    {
        if ($this->binary_analysis_cache === null || $this->fingerprint === null) {
            return;
        }
        $data = $this->binary_analysis_cache->get($this->fingerprint, 'elf_resolver') ?? [];
        if ($this->base_address_resolved && $this->base_address_cache !== null) {
            $data['base_address_hi'] = $this->base_address_cache->hi;
            $data['base_address_lo'] = $this->base_address_cache->lo;
        }
        if ($this->dt_debug_resolved) {
            $data['dt_debug_address'] = $this->dt_debug_address_cache;
        }
        $this->binary_analysis_cache->set($this->fingerprint, 'elf_resolver', $data);
    }
}
