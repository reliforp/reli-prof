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

class ModuleEhFrameCacheTest extends BaseTestCase
{
    public function testLoadPhpBinary(): void
    {
        $cache = new ModuleEhFrameCache();
        $fdes = $cache->getFdesForModule(PHP_BINARY);

        $this->assertNotNull($fdes);
        $this->assertGreaterThan(100, count($fdes));

        // FDEs should be sorted by initial location
        for ($i = 1; $i < count($fdes); $i++) {
            $this->assertGreaterThanOrEqual(
                $fdes[$i - 1]->initialLocation,
                $fdes[$i]->initialLocation,
                'FDEs should be sorted by initial location'
            );
        }
    }

    public function testFindFdeForAddress(): void
    {
        $cache = new ModuleEhFrameCache();
        $fdes = $cache->getFdesForModule(PHP_BINARY);
        $this->assertNotNull($fdes);

        // Pick the first FDE and try to find it
        $first_fde = $fdes[0];
        $found = $cache->findFdeForAddress(PHP_BINARY, $first_fde->initialLocation);
        $this->assertNotNull($found);
        $this->assertSame($first_fde->initialLocation, $found->initialLocation);
    }

    public function testFindFdeForAddressNotFound(): void
    {
        $cache = new ModuleEhFrameCache();
        $result = $cache->findFdeForAddress(PHP_BINARY, 0);
        $this->assertNull($result);
    }

    public function testNonExistentModuleReturnsNull(): void
    {
        $cache = new ModuleEhFrameCache();
        $result = $cache->getFdesForModule('/nonexistent/binary');
        $this->assertNull($result);
    }

    public function testCaching(): void
    {
        $cache = new ModuleEhFrameCache();

        $fdes1 = $cache->getFdesForModule(PHP_BINARY);
        $fdes2 = $cache->getFdesForModule(PHP_BINARY);

        // Should return same array (cached)
        $this->assertSame($fdes1, $fdes2);
    }
}
