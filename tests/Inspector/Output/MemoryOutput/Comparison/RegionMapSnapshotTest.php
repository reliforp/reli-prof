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

namespace Reli\Inspector\Output\MemoryOutput\Comparison;

use Reli\BaseTestCase;

class RegionMapSnapshotTest extends BaseTestCase
{
    public function testDecodesValidRows(): void
    {
        $json = json_encode([
            ['kind' => 'chunk', 'address' => 0x7f0000000000, 'size' => 2 * 1024 * 1024],
            ['kind' => 'huge', 'address' => 0x7f1000000000, 'size' => 728 * 1024],
        ]);
        $decoded = RegionMapSnapshot::decode($json);
        $this->assertNotNull($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame('chunk', $decoded[0]['kind']);
        $this->assertSame(0x7f0000000000, $decoded[0]['address']);
        $this->assertSame(2 * 1024 * 1024, $decoded[0]['size']);
    }

    public function testReturnsNullForInvalidJson(): void
    {
        $this->assertNull(RegionMapSnapshot::decode(''));
        $this->assertNull(RegionMapSnapshot::decode('{not json'));
    }

    public function testSkipsMalformedRows(): void
    {
        $json = json_encode([
            ['kind' => 'chunk', 'address' => 100, 'size' => 200],
            ['kind' => 'no_size', 'address' => 300],
            'string row',
            ['address' => 400, 'size' => 500], // missing kind
        ]);
        $decoded = RegionMapSnapshot::decode($json);
        $this->assertNotNull($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('chunk', $decoded[0]['kind']);
    }
}
