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

namespace Reli\Lib\Dwarf;

use Reli\BaseTestCase;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;

class ModuleEhFrameCacheTest extends BaseTestCase
{
    public function testLoadPhpBinary(): void
    {
        $cache = new ModuleEhFrameCache();
        $fdes = $cache->getFdesForModule(PHP_BINARY);

        $this->assertNotNull($fdes);
        $this->assertGreaterThan(100, count($fdes));

        // FDEs should be sorted by initial location
        for ($i = 1; $i < count($fdes); $i++) {
            $this->assertGreaterThanOrEqual(
                $fdes[$i - 1]->initialLocation,
                $fdes[$i]->initialLocation,
                'FDEs should be sorted by initial location'
            );
        }
    }

    public function testFindFdeForAddress(): void
    {
        $cache = new ModuleEhFrameCache();
        $fdes = $cache->getFdesForModule(PHP_BINARY);
        $this->assertNotNull($fdes);

        // Pick the first FDE and try to find it
        $first_fde = $fdes[0];
        $found = $cache->findFdeForAddress(PHP_BINARY, $first_fde->initialLocation);
        $this->assertNotNull($found);
        $this->assertSame($first_fde->initialLocation, $found->initialLocation);
    }

    public function testFindFdeForAddressNotFound(): void
    {
        $cache = new ModuleEhFrameCache();
        $result = $cache->findFdeForAddress(PHP_BINARY, 0);
        $this->assertNull($result);
    }

    public function testNonExistentModuleReturnsNull(): void
    {
        $cache = new ModuleEhFrameCache();
        $result = $cache->getFdesForModule('/nonexistent/binary');
        $this->assertNull($result);
    }

    public function testCaching(): void
    {
        $cache = new ModuleEhFrameCache();

        $fdes1 = $cache->getFdesForModule(PHP_BINARY);
        $fdes2 = $cache->getFdesForModule(PHP_BINARY);

        // Should return same array (cached)
        $this->assertSame($fdes1, $fdes2);
    }

    private function makeMemoryMapForPhpBinary(): ProcessMemoryMap
    {
        $maps_raw = file_get_contents('/proc/self/maps');
        $parser = new \Reli\Lib\Process\MemoryMap\ProcessMemoryMapParser(
            new \Reli\Lib\String\LineFetcher()
        );
        return $parser->parse($maps_raw);
    }

    public function testDiskCacheRoundtrip(): void
    {
        $cache_dir = sys_get_temp_dir() . '/reli-test-ehframe-' . uniqid();
        $memoryMap = $this->makeMemoryMapForPhpBinary();

        try {
            // Cold: parse from binary, write to disk cache
            $binary_cache = new BinaryAnalysisCache($cache_dir);
            $cache1 = new ModuleEhFrameCache(
                binaryAnalysisCache: $binary_cache,
                memoryMap: $memoryMap,
            );
            $fdes_original = $cache1->getFdesForModule(PHP_BINARY);
            $this->assertNotNull($fdes_original);
            $this->assertGreaterThan(100, count($fdes_original));

            // Warm: load from disk cache (new instance, no in-memory cache)
            $binary_cache2 = new BinaryAnalysisCache($cache_dir);
            $cache2 = new ModuleEhFrameCache(
                binaryAnalysisCache: $binary_cache2,
                memoryMap: $memoryMap,
            );
            $fdes_cached = $cache2->getFdesForModule(PHP_BINARY);
            $this->assertNotNull($fdes_cached);

            // Same count
            $this->assertCount(count($fdes_original), $fdes_cached);

            // Spot-check: same initial locations and address ranges
            for ($i = 0; $i < min(10, count($fdes_original)); $i++) {
                $this->assertSame(
                    $fdes_original[$i]->initialLocation,
                    $fdes_cached[$i]->initialLocation,
                    "FDE[$i] initialLocation mismatch"
                );
                $this->assertSame(
                    $fdes_original[$i]->addressRange,
                    $fdes_cached[$i]->addressRange,
                    "FDE[$i] addressRange mismatch"
                );
                $this->assertSame(
                    $fdes_original[$i]->instructions,
                    $fdes_cached[$i]->instructions,
                    "FDE[$i] instructions mismatch"
                );
                $this->assertSame(
                    $fdes_original[$i]->cie->codeAlignmentFactor,
                    $fdes_cached[$i]->cie->codeAlignmentFactor,
                    "FDE[$i] CIE codeAlignmentFactor mismatch"
                );
                $this->assertSame(
                    $fdes_original[$i]->cie->dataAlignmentFactor,
                    $fdes_cached[$i]->cie->dataAlignmentFactor,
                    "FDE[$i] CIE dataAlignmentFactor mismatch"
                );
            }

            // FDE lookup should work the same
            $target_addr = $fdes_original[5]->initialLocation + 1;
            $found_original = $cache1->findFdeForAddress(PHP_BINARY, $target_addr);
            $found_cached = $cache2->findFdeForAddress(PHP_BINARY, $target_addr);
            $this->assertNotNull($found_original);
            $this->assertNotNull($found_cached);
            $this->assertSame($found_original->initialLocation, $found_cached->initialLocation);
        } finally {
            exec('rm -rf ' . escapeshellarg($cache_dir));
        }
    }

    public function testCieSharing(): void
    {
        $cache_dir = sys_get_temp_dir() . '/reli-test-ehframe-cie-' . uniqid();
        $memoryMap = $this->makeMemoryMapForPhpBinary();

        try {
            // Write to cache
            $binary_cache = new BinaryAnalysisCache($cache_dir);
            $cache1 = new ModuleEhFrameCache(
                binaryAnalysisCache: $binary_cache,
                memoryMap: $memoryMap,
            );
            $fdes_original = $cache1->getFdesForModule(PHP_BINARY);
            $this->assertNotNull($fdes_original);

            // After deserialization, FDEs sharing the same CIE should still share
            $binary_cache2 = new BinaryAnalysisCache($cache_dir);
            $cache2 = new ModuleEhFrameCache(
                binaryAnalysisCache: $binary_cache2,
                memoryMap: $memoryMap,
            );
            $fdes_cached = $cache2->getFdesForModule(PHP_BINARY);
            $this->assertNotNull($fdes_cached);

            // Find two FDEs that share the same CIE in the original
            $shared_cie_fdes = [];
            foreach ($fdes_original as $i => $fde) {
                $cie_id = spl_object_id($fde->cie);
                $shared_cie_fdes[$cie_id][] = $i;
            }
            foreach ($shared_cie_fdes as $indices) {
                if (count($indices) >= 2) {
                    // In the cached version, these should also share the same CIE instance
                    $this->assertSame(
                        spl_object_id($fdes_cached[$indices[0]]->cie),
                        spl_object_id($fdes_cached[$indices[1]]->cie),
                        'FDEs should share CIE instance after deserialization'
                    );
                    break;
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($cache_dir));
        }
    }
}
