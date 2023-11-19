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

class ProcessMemoryMapTest extends BaseTestCase
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
                '00:01',
                1,
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
                '00:02',
                2,
                'test_area_2'
            ),
        ]);
        $area1 = $memory_map->findByNameRegex('.*1');
        $area2 = $memory_map->findByNameRegex('.*2');
        $area_both = $memory_map->findByNameRegex('test.*');

        $this->assertCount(1, $area1);
        $this->assertCount(1, $area2);
        $this->assertCount(2, $area_both);

        $this->assertSame('0x00000000', $area1[0]->begin);
        $this->assertSame('0x20000000', $area2[0]->begin);
        $this->assertSame('0x00000000', $area_both[0]->begin);
        $this->assertSame('0x20000000', $area_both[1]->begin);
    }
}
