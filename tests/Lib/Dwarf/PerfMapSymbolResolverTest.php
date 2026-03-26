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

class PerfMapSymbolResolverTest extends BaseTestCase
{
    private string $mapFile;

    protected function setUp(): void
    {
        $this->mapFile = '/tmp/perf-99999.map';
        file_put_contents($this->mapFile, implode("\n", [
            '7f0000001000 100 JIT$fibonacci',
            '7f0000001100 80 JIT$MyClass::doWork',
            '7f0000001180 40 JIT$helper',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->mapFile);
    }

    public function testResolveExactStart(): void
    {
        $resolver = new PerfMapSymbolResolver(99999);
        $result = $resolver->resolve(0x7f0000001000);
        $this->assertNotNull($result);
        $this->assertSame('JIT$fibonacci', $result[0]);
        $this->assertSame(0, $result[1]);
    }

    public function testResolveWithOffset(): void
    {
        $resolver = new PerfMapSymbolResolver(99999);
        $result = $resolver->resolve(0x7f0000001050);
        $this->assertNotNull($result);
        $this->assertSame('JIT$fibonacci', $result[0]);
        $this->assertSame(0x50, $result[1]);
    }

    public function testResolveSecondSymbol(): void
    {
        $resolver = new PerfMapSymbolResolver(99999);
        $result = $resolver->resolve(0x7f0000001120);
        $this->assertNotNull($result);
        $this->assertSame('JIT$MyClass::doWork', $result[0]);
        $this->assertSame(0x20, $result[1]);
    }

    public function testResolveOutOfRange(): void
    {
        $resolver = new PerfMapSymbolResolver(99999);
        // Past the end of JIT$helper (0x1180 + 0x40 = 0x11c0)
        $result = $resolver->resolve(0x7f0000002000);
        $this->assertNull($result);
    }

    public function testResolveBeforeFirstSymbol(): void
    {
        $resolver = new PerfMapSymbolResolver(99999);
        $result = $resolver->resolve(0x100);
        $this->assertNull($result);
    }

    public function testNoMapFile(): void
    {
        $resolver = new PerfMapSymbolResolver(88888); // non-existent pid
        $result = $resolver->resolve(0x7f0000001000);
        $this->assertNull($result);
    }

    public function testReload(): void
    {
        $resolver = new PerfMapSymbolResolver(99999);
        $result = $resolver->resolve(0x7f0000001000);
        $this->assertNotNull($result);

        // Update map file
        file_put_contents($this->mapFile, "7f0000002000 100 JIT\$newFunc\n");
        $resolver->reload();

        $result = $resolver->resolve(0x7f0000001000);
        $this->assertNull($result);

        $result = $resolver->resolve(0x7f0000002000);
        $this->assertNotNull($result);
        $this->assertSame('JIT$newFunc', $result[0]);
    }
}
