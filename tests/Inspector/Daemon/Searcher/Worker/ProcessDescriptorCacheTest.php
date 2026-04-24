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

use Reli\BaseTestCase;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Lib\PhpInternals\ZendTypeReader;

class ProcessDescriptorCacheTest extends BaseTestCase
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

    public function testPendingIsIndependentOfValidCache(): void
    {
        $cache = new ProcessDescriptorCache();
        $this->assertNull($cache->get(10));
        $this->assertNull($cache->getPending(10));

        $pending_desc = new TargetProcessDescriptor(10, 0xdead, 0xbeef, ZendTypeReader::V80);
        $cache->setPending($pending_desc);

        // Pending must not leak into the valid lookup
        $this->assertNull($cache->get(10));
        $this->assertEquals($pending_desc, $cache->getPending(10));

        // Promotion to valid clears the pending entry
        $cache->set($pending_desc);
        $this->assertEquals($pending_desc, $cache->get(10));
        $this->assertNull($cache->getPending(10));
    }

    public function testSetInvalidClearsPending(): void
    {
        $cache = new ProcessDescriptorCache();
        $cache->setPending(new TargetProcessDescriptor(7, 0x1, 0x2, ZendTypeReader::V80));
        $cache->setInvalid(7);
        $this->assertSame(TargetProcessDescriptor::getInvalid(), $cache->get(7));
        $this->assertNull($cache->getPending(7));
    }

    public function testRemoveDisappearedClearsBothCaches(): void
    {
        $cache = new ProcessDescriptorCache();
        $cache->set(new TargetProcessDescriptor(1, 0, 0, ZendTypeReader::V80));
        $cache->setPending(new TargetProcessDescriptor(2, 0, 0, ZendTypeReader::V80));
        $cache->setPending(new TargetProcessDescriptor(3, 0, 0, ZendTypeReader::V80));
        $cache->set(new TargetProcessDescriptor(4, 0, 0, ZendTypeReader::V80));

        $cache->removeDisappeared(1, 3);

        $this->assertNotNull($cache->get(1));
        $this->assertNull($cache->getPending(2));
        $this->assertEquals(
            new TargetProcessDescriptor(3, 0, 0, ZendTypeReader::V80),
            $cache->getPending(3),
        );
        $this->assertNull($cache->get(4));
    }
}
