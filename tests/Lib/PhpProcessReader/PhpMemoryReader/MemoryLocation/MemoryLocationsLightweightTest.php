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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use Reli\BaseTestCase;
use Reli\Lib\Process\MemoryLocation;

class MemoryLocationsLightweightTest extends BaseTestCase
{
    public function testCreateLightweight(): void
    {
        $locations = MemoryLocations::createLightweight();
        $this->assertInstanceOf(MemoryLocations::class, $locations);
    }

    public function testHasReturnsFalseForUnknownAddress(): void
    {
        $locations = MemoryLocations::createLightweight();
        $this->assertFalse($locations->has(0x1000));
    }

    public function testAddAndHas(): void
    {
        $locations = MemoryLocations::createLightweight();
        $locations->add(new MemoryLocation(0x1000, 32));
        $this->assertTrue($locations->has(0x1000));
        $this->assertFalse($locations->has(0x2000));
    }

    public function testMultipleAdds(): void
    {
        $locations = MemoryLocations::createLightweight();
        $locations->add(new MemoryLocation(0x1000, 32));
        $locations->add(new MemoryLocation(0x2000, 64));
        $locations->add(new MemoryLocation(0x3000, 16));

        $this->assertTrue($locations->has(0x1000));
        $this->assertTrue($locations->has(0x2000));
        $this->assertTrue($locations->has(0x3000));
        $this->assertFalse($locations->has(0x4000));
    }

    public function testDuplicateAddDoesNotError(): void
    {
        $locations = MemoryLocations::createLightweight();
        $locations->add(new MemoryLocation(0x1000, 32));
        $locations->add(new MemoryLocation(0x1000, 64));
        $this->assertTrue($locations->has(0x1000));
    }

    public function testAddAliasInLightweightMode(): void
    {
        $locations = MemoryLocations::createLightweight();
        $location = new MemoryLocation(0x1000, 32);
        $locations->add($location);
        $locations->addAlias(0x1008, $location);

        $this->assertTrue($locations->has(0x1000));
        $this->assertTrue($locations->has(0x1008));
        $this->assertFalse($locations->has(0x2000));
    }

    public function testAddAliasInNormalMode(): void
    {
        $locations = new MemoryLocations();
        $location = new MemoryLocation(0x1000, 32);
        $locations->add($location);
        $locations->addAlias(0x1008, $location);

        $this->assertTrue($locations->has(0x1000));
        $this->assertTrue($locations->has(0x1008));
        $this->assertSame($location, $locations->get(0x1008));
    }
}
