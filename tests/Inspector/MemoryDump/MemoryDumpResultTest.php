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

namespace Reli\Inspector\MemoryDump;

use PHPUnit\Framework\TestCase;

class MemoryDumpResultTest extends TestCase
{
    public function testConstruction(): void
    {
        $result = new MemoryDumpResult(
            output_path: '/tmp/dump.bin',
            region_count: 42,
            total_bytes: 1024 * 1024,
        );
        $this->assertSame('/tmp/dump.bin', $result->output_path);
        $this->assertSame(42, $result->region_count);
        $this->assertSame(1024 * 1024, $result->total_bytes);
    }
}
