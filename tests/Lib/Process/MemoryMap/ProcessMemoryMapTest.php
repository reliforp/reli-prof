<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Process\MemoryMap;

use PHPUnit\Framework\TestCase;

class ProcessMemoryMapTest extends TestCase
{
    public function testFindByNameRegex()
    {
        $memory_map = new ProcessMemoryMap([
            new ProcessMemoryArea(
                '0x00000000',
                '0x10000000',
                '0x00000000',
                new ProcessMemoryAttribute(
                    true,
                    true,
                    true,
                    false
                ),
                'test_area_1'
            ),
            new ProcessMemoryArea(
                '0x20000000',
                '0x30000000',
                '0x20000000',
                new ProcessMemoryAttribute(
                    true,
                    true,
                    true,
                    false
                ),
                'test_area_2'
            ),
        ]);
        $area1 = $memory_map->findByNameRegex('/.*1/');
        $area2 = $memory_map->findByNameRegex('/.*2/');
        $area_both = $memory_map->findByNameRegex('/test.*/');

        $this->assertCount(1, $area1);
        $this->assertCount(1, $area2);
        $this->assertCount(2, $area_both);

        $this->assertSame('0x00000000', $area1[0]->begin);
        $this->assertSame('0x20000000', $area2[0]->begin);
        $this->assertSame('0x00000000', $area_both[0]->begin);
        $this->assertSame('0x20000000', $area_both[1]->begin);
    }
}
