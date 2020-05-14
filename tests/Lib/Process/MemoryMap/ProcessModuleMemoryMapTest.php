<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Process\MemoryMap;

use PHPUnit\Framework\TestCase;

class ProcessModuleMemoryMapTest extends TestCase
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

    /**
     * @param int $expected
     * @param int $offset
     * @dataProvider addressAndOffsetProvider
     */
    public function testGetMemoryAddressFromOffset(int $expected, int $offset)
    {
        $process_module_memoru_map = new ProcessModuleMemoryMap([
            $this->createProcessMemoryArea('0x10000000', '0x20000000', '0x00000000'),
            $this->createProcessMemoryArea('0x30000000', '0x40000000', '0x10000000'),
        ]);
        $this->assertSame($expected, $process_module_memoru_map->getMemoryAddressFromOffset($offset));
    }

    public function addressAndOffsetProvider(): array
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
            'test'
        );
    }
}
