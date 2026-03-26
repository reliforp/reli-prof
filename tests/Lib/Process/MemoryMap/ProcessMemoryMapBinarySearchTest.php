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

namespace Reli\Lib\Process\MemoryMap;

use Reli\BaseTestCase;

class ProcessMemoryMapBinarySearchTest extends BaseTestCase
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

    public function testFindByAddressExact(): void
    {
        $map = $this->makeMap([
            ['1000', '2000', 'a'],
            ['3000', '4000', 'b'],
        ]);

        $result = $map->findByAddress(0x1500);
        $this->assertCount(1, $result);
        $this->assertSame('a', $result[0]->name);
    }

    public function testFindByAddressNotFound(): void
    {
        $map = $this->makeMap([
            ['1000', '2000', 'a'],
            ['3000', '4000', 'b'],
        ]);

        $result = $map->findByAddress(0x2500);
        $this->assertCount(0, $result);
    }

    public function testFindByAddressBeforeAll(): void
    {
        $map = $this->makeMap([
            ['1000', '2000', 'a'],
        ]);

        $result = $map->findByAddress(0x500);
        $this->assertCount(0, $result);
    }

    public function testFindByAddressAfterAll(): void
    {
        $map = $this->makeMap([
            ['1000', '2000', 'a'],
        ]);

        $result = $map->findByAddress(0x5000);
        $this->assertCount(0, $result);
    }

    public function testFindByAddressBoundary(): void
    {
        $map = $this->makeMap([
            ['1000', '2000', 'a'],
            ['2000', '3000', 'b'],
        ]);

        // At boundary: both should match (end inclusive in isInRange)
        $result = $map->findByAddress(0x2000);
        $this->assertCount(2, $result);
        // Order should be ascending by begin
        $this->assertSame('a', $result[0]->name);
        $this->assertSame('b', $result[1]->name);
    }

    public function testFindByAddressAtStart(): void
    {
        $map = $this->makeMap([
            ['1000', '2000', 'a'],
        ]);

        $result = $map->findByAddress(0x1000);
        $this->assertCount(1, $result);
        $this->assertSame('a', $result[0]->name);
    }

    public function testFindByAddressAtEnd(): void
    {
        $map = $this->makeMap([
            ['1000', '2000', 'a'],
        ]);

        $result = $map->findByAddress(0x2000);
        $this->assertCount(1, $result);
    }

    public function testFindByAddressManyRegions(): void
    {
        $entries = [];
        for ($i = 0; $i < 100; $i++) {
            $begin = dechex(0x7f0000000000 + $i * 0x10000);
            $end = dechex(0x7f0000000000 + ($i + 1) * 0x10000 - 1);
            $entries[] = [$begin, $end, "lib{$i}.so"];
        }
        $map = $this->makeMap($entries);

        // Middle region
        $result = $map->findByAddress(0x7f0000320000);
        $this->assertCount(1, $result);
        $this->assertSame('lib50.so', $result[0]->name);

        // First region
        $result = $map->findByAddress(0x7f0000000100);
        $this->assertCount(1, $result);
        $this->assertSame('lib0.so', $result[0]->name);

        // Last region
        $result = $map->findByAddress(0x7f0000630100);
        $this->assertCount(1, $result);
        $this->assertSame('lib99.so', $result[0]->name);
    }

    public function testHexToSignedIntWithPrefix(): void
    {
        $map = $this->makeMap([
            ['0x1000', '0x2000', 'test'],
        ]);

        $result = $map->findByAddress(0x1500);
        $this->assertCount(1, $result);
        $this->assertSame('test', $result[0]->name);
    }

    public function testHighAddresses(): void
    {
        // vsyscall-like high address
        $map = $this->makeMap([
            ['7f0000000000', '7f0000100000', 'normal'],
            ['ffffffffff600000', 'ffffffffff601000', '[vsyscall]'],
        ]);

        $result = $map->findByAddress(0x7f0000050000);
        $this->assertCount(1, $result);
        $this->assertSame('normal', $result[0]->name);
    }

    public function testEmptyMap(): void
    {
        $map = $this->makeMap([]);
        $result = $map->findByAddress(0x1000);
        $this->assertCount(0, $result);
    }
}
