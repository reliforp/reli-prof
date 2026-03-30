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

namespace Reli\Inspector\Watch\Daemon\Searcher;

use PHPUnit\Framework\TestCase;
use Reli\Lib\PhpInternals\ZendTypeReader;

class WatchTargetDescriptorTest extends TestCase
{
    public function testConstruction(): void
    {
        $desc = new WatchTargetDescriptor(
            123,
            0x1000,
            0x2000,
            0x3000,
            ZendTypeReader::V84,
        );
        $this->assertSame(123, $desc->pid);
        $this->assertSame(0x1000, $desc->eg_address);
        $this->assertSame(0x2000, $desc->sg_address);
        $this->assertSame(0x3000, $desc->cg_address);
        $this->assertSame('v84', $desc->php_version);
    }

    public function testToBaseDescriptor(): void
    {
        $desc = new WatchTargetDescriptor(
            42,
            0x100,
            0x200,
            0x300,
            ZendTypeReader::V84,
        );
        $base = $desc->toBaseDescriptor();
        $this->assertSame(42, $base->pid);
        $this->assertSame(0x100, $base->eg_address);
        $this->assertSame(0x200, $base->sg_address);
        $this->assertSame('v84', $base->php_version);
    }

    public function testGetInvalid(): void
    {
        $invalid = WatchTargetDescriptor::getInvalid();
        $this->assertSame(0, $invalid->pid);
        $this->assertSame(0, $invalid->eg_address);
        $this->assertSame(0, $invalid->sg_address);
        $this->assertSame(0, $invalid->cg_address);
    }

    public function testGetInvalidIsSingleton(): void
    {
        $a = WatchTargetDescriptor::getInvalid();
        $b = WatchTargetDescriptor::getInvalid();
        $this->assertSame($a, $b);
    }
}
