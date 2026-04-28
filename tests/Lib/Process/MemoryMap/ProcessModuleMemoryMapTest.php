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

use PHPUnit\Framework\Attributes\DataProvider;
use Reli\BaseTestCase;

class ProcessModuleMemoryMapTest extends BaseTestCase
{
    public function testGetBaseAddress()
    {
        $process_module_memoru_map = new ProcessModuleMemoryMap([
            $this->createProcessMemoryArea('0x10000000', '0x20000000', '0x00000000'),
            $this->createProcessMemoryArea('0x30000000', '0x40000000', '0x10000000'),
        ]);
        $this->assertSame(0x10000000, $process_module_memoru_map->getBaseAddress());
        $process_module_memoru_map = new ProcessModuleMemoryMap([
            $this->createProcessMemoryArea('0x30000000', '0x40000000', '0x10000000'),
            $this->createProcessMemoryArea('0x10000000', '0x20000000', '0x00000000'),
        ]);
        $this->assertSame(0x10000000, $process_module_memoru_map->getBaseAddress());
    }

    #[DataProvider('addressAndOffsetProvider')]
    public function testGetMemoryAddressFromOffset(int $expected, int $offset)
    {
        $process_module_memoru_map = new ProcessModuleMemoryMap([
            $this->createProcessMemoryArea('0x10000000', '0x20000000', '0x00000000'),
            $this->createProcessMemoryArea('0x30000000', '0x40000000', '0x10000000'),
        ]);
        $this->assertSame($expected, $process_module_memoru_map->getMemoryAddressFromOffset($offset));
    }

    public static function addressAndOffsetProvider(): array
    {
        return [
            [0x10000000, 0x00000000],
            [0x10000001, 0x00000001],
            [0x10000002, 0x00000002],
            [0x1ffffffe, 0x0ffffffe],
            [0x1fffffff, 0x0fffffff],
            [0x30000000, 0x10000000],
            [0x30000001, 0x10000001],
            [0x30000002, 0x10000002],
            [0x3ffffffe, 0x1ffffffe],
            [0x3fffffff, 0x1fffffff],
            [0x40000000, 0x20000000],
        ];
    }

    private function createProcessMemoryArea(string $begin, string $end, string $file_offset): ProcessMemoryArea
    {
        return new ProcessMemoryArea(
            $begin,
            $end,
            $file_offset,
            new ProcessMemoryAttribute(
                false,
                true,
                false,
                true
            ),
            '00:01',
            1,
            'test'
        );
    }

    public function testEmptyMemoryAreasThrowsCatchableException(): void
    {
        // When the regex used to filter /proc/{pid}/maps matches no module
        // (e.g. user passing --php-regex='libphp\.so$' against a CLI binary,
        // or daemon searcher feeding a non-PHP candidate that a too-broad
        // --target-regex picked up), every accessor that has to look at a
        // memory area must throw a single catchable exception rather than
        // emit "Undefined array key 0" / "Attempt to read property on null"
        // warnings followed by a TypeError, OR silently fall back to a
        // PHP_INT_MAX base address. ProcessDescriptorRetriever::
        // getProcessDescriptor already catch (\Throwable) and skips the
        // candidate, so this shape preserves the implicit "non-PHP
        // candidate" filter while dropping the noisy warning chain.
        $map = new ProcessModuleMemoryMap([]);
        $accessors = [
            'getDeviceId' => [],
            'getInodeNumber' => [],
            'getModuleName' => [],
            'getBaseAddress' => [],
            'getMemoryAddressFromOffset' => [0],
        ];
        foreach ($accessors as $method => $args) {
            try {
                $map->$method(...$args);
                $this->fail("$method must throw on an empty map");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('no memory areas', $e->getMessage());
            }
        }
    }

    public function testIsInRangeIsSafeOnEmptyMap(): void
    {
        // isInRange is a pure scan, so it can answer without looking at any
        // particular area. Empty map means "no address is in range".
        $map = new ProcessModuleMemoryMap([]);
        $this->assertFalse($map->isInRange(0x10000000));
    }

    public function testIsInRange()
    {
        $process_module_memory_map = new ProcessModuleMemoryMap(
            [
                $this->createProcessMemoryArea('0x10000000', '0x20000000', '0x00000000'),
                $this->createProcessMemoryArea('0x30000000', '0x40000000', '0x10000000'),
            ]
        );
        $this->assertFalse($process_module_memory_map->isInRange(0x00000000));
        $this->assertFalse($process_module_memory_map->isInRange(0x00000001));
        $this->assertFalse($process_module_memory_map->isInRange(0x0fffffff));
        $this->assertTrue($process_module_memory_map->isInRange(0x10000000));
        $this->assertTrue($process_module_memory_map->isInRange(0x10000001));
        $this->assertTrue($process_module_memory_map->isInRange(0x1fffffff));
        $this->assertTrue($process_module_memory_map->isInRange(0x20000000));
        $this->assertFalse($process_module_memory_map->isInRange(0x20000001));
        $this->assertFalse($process_module_memory_map->isInRange(0x2fffffff));
        $this->assertTrue($process_module_memory_map->isInRange(0x30000000));
        $this->assertTrue($process_module_memory_map->isInRange(0x30000001));
        $this->assertTrue($process_module_memory_map->isInRange(0x3fffffff));
        $this->assertTrue($process_module_memory_map->isInRange(0x40000000));
        $this->assertFalse($process_module_memory_map->isInRange(0x40000001));
    }
}
