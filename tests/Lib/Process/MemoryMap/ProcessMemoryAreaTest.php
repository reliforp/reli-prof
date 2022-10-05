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

use PHPUnit\Framework\TestCase;

class ProcessMemoryAreaTest extends TestCase
{
    public function testIsInRange()
    {
        $process_memory_area = new ProcessMemoryArea(
            '0x10000000',
            '0x20000000',
            '0x00000000',
            new ProcessMemoryAttribute(
                true,
                false,
                true,
                false
            ),
            'test'
        );
        $this->assertFalse($process_memory_area->isInRange(0x00000000));
        $this->assertFalse($process_memory_area->isInRange(0x0fffffff));
        $this->assertTrue($process_memory_area->isInRange(0x10000000));
        $this->assertTrue($process_memory_area->isInRange(0x10000001));
        $this->assertTrue($process_memory_area->isInRange(0x1fffffff));
        $this->assertTrue($process_memory_area->isInRange(0x20000000));
        $this->assertFalse($process_memory_area->isInRange(0x20000001));
    }
}
