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

namespace Reli\Inspector\Daemon\Searcher\Worker;

use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Lib\PhpInternals\ZendTypeReader;
use PHPUnit\Framework\TestCase;

class ProcessDescriptorCacheTest extends TestCase
{
    public function testGetSet(): void
    {
        $process_descriptor_cache = new ProcessDescriptorCache();
        $this->assertNull($process_descriptor_cache->get(42));
        $process_descriptor_cache->set(
            new TargetProcessDescriptor(42, 0, 0, ZendTypeReader::V80)
        );
        $this->assertEquals(
            new TargetProcessDescriptor(42, 0, 0, ZendTypeReader::V80),
            $process_descriptor_cache->get(42)
        );
    }

    public function testRemoveDisappeared()
    {
        $process_descriptor_cache = new ProcessDescriptorCache();
        $process_descriptor_cache->set(
            new TargetProcessDescriptor(1, 0, 0, ZendTypeReader::V80)
        );
        $process_descriptor_cache->set(
            new TargetProcessDescriptor(2, 0, 0, ZendTypeReader::V80)
        );
        $process_descriptor_cache->set(
            new TargetProcessDescriptor(3, 0, 0, ZendTypeReader::V80)
        );
        $process_descriptor_cache->removeDisappeared(1, 3);
        $this->assertEquals(
            new TargetProcessDescriptor(1, 0, 0, ZendTypeReader::V80),
            $process_descriptor_cache->get(1)
        );
        $this->assertEquals(
            new TargetProcessDescriptor(3, 0, 0, ZendTypeReader::V80),
            $process_descriptor_cache->get(3)
        );
        $this->assertNull($process_descriptor_cache->get(2));
    }
}
