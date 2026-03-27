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

class NativeSymbolResolverTest extends BaseTestCase
{
    private function makeMap(array $entries): ProcessMemoryMap
    {
        $attr = new ProcessMemoryAttribute(true, false, true, false);
        $areas = [];
        foreach ($entries as [$begin, $end, $name]) {
            $areas[] = new ProcessMemoryArea(
                $begin,
                $end,
                '00000000',
                $attr,
                '00:00',
                0,
                $name,
            );
        }
        return new ProcessMemoryMap($areas);
    }

    public function testResolveEmptyMap(): void
    {
        $map = $this->makeMap([]);
        $resolver = new NativeSymbolResolver($map);
        $this->assertNull($resolver->resolve(0x1000));
    }

    public function testResolveAnonymousMapping(): void
    {
        $map = $this->makeMap([
            ['7f0000000000', '7f0000100000', ''],
        ]);
        $resolver = new NativeSymbolResolver($map);
        // Anonymous mapping with no perf map → null
        $this->assertNull($resolver->resolve(0x7f0000050000));
    }

    public function testResolveSpecialMapping(): void
    {
        $map = $this->makeMap([
            ['7f0000000000', '7f0000001000', '[vdso]'],
        ]);
        $resolver = new NativeSymbolResolver($map);
        $this->assertNull($resolver->resolve(0x7f0000000500));
    }

    public function testResolveWithPerfMap(): void
    {
        $map = $this->makeMap([
            ['7f0000000000', '7f0000100000', ''],
        ]);

        $perf_file = '/tmp/perf-77777.map';
        file_put_contents($perf_file, "7f0000050000 100 JIT\$test\n");

        try {
            $perf = new PerfMapSymbolResolver(77777);
            $resolver = new NativeSymbolResolver($map, perfMapResolver: $perf);
            $result = $resolver->resolve(0x7f0000050020);
            $this->assertNotNull($result);
            $this->assertSame('JIT$test', $result[0]);
            $this->assertSame(0x20, $result[1]);
        } finally {
            @unlink($perf_file);
        }
    }

    public function testResolveNonExistentFile(): void
    {
        $map = $this->makeMap([
            ['7f0000000000', '7f0000100000', '/nonexistent/lib.so'],
        ]);
        $resolver = new NativeSymbolResolver($map);
        // Falls through to perf map (null)
        $this->assertNull($resolver->resolve(0x7f0000050000));
    }

    public function testResolvePhpBinary(): void
    {
        $maps_raw = file_get_contents('/proc/self/maps');
        $parser = new \Reli\Lib\Process\MemoryMap\ProcessMemoryMapParser(
            new \Reli\Lib\String\LineFetcher()
        );
        $map = $parser->parse($maps_raw);

        $resolver = new NativeSymbolResolver($map);

        // Find a known function address - use the PHP binary's execute_ex
        // We can't easily get the runtime address, so just verify the
        // resolver doesn't crash on a real memory map
        $areas = $map->findByNameRegex('php');
        if ($areas !== []) {
            $addr = hexdec($areas[0]->begin) + 0x100;
            // May or may not resolve, but shouldn't crash
            $resolver->resolve($addr);
        }
        $this->assertTrue(true); // no crash
    }

    public function testDiskCacheRoundtrip(): void
    {
        $maps_raw = file_get_contents('/proc/self/maps');
        $parser = new \Reli\Lib\Process\MemoryMap\ProcessMemoryMapParser(
            new \Reli\Lib\String\LineFetcher()
        );
        $map = $parser->parse($maps_raw);

        $cache_dir = sys_get_temp_dir() . '/reli-test-natsym-' . uniqid();

        try {
            // Cold: resolve from binary, write to cache
            $cache1 = new BinaryAnalysisCache($cache_dir);
            $resolver1 = new NativeSymbolResolver($map, binaryAnalysisCache: $cache1);

            $areas = $map->findByNameRegex('php');
            if ($areas === []) {
                $this->markTestSkipped('No php binary in memory map');
            }
            $addr = hexdec($areas[0]->begin) + 0x100;
            $result1 = $resolver1->resolve($addr);

            // Warm: new resolver, should load from disk cache
            $cache2 = new BinaryAnalysisCache($cache_dir);
            $resolver2 = new NativeSymbolResolver($map, binaryAnalysisCache: $cache2);
            $result2 = $resolver2->resolve($addr);

            // Same result
            $this->assertSame($result1, $result2);
        } finally {
            exec('rm -rf ' . escapeshellarg($cache_dir));
        }
    }
}
