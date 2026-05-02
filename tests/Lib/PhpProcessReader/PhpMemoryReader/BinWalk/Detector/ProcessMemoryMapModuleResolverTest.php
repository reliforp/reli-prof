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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector;

use Reli\BaseTestCase;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;

class ProcessMemoryMapModuleResolverTest extends BaseTestCase
{
    public function testResolvesNamedExecutableModule(): void
    {
        $resolver = new ProcessMemoryMapModuleResolver(new ProcessMemoryMap([
            $this->area('0x7f0000000000', '0x7f0000200000', '/usr/lib/libuv.so.1', execute: true),
        ]));
        $this->assertSame('libuv.so.1', $resolver->moduleBasenameFor(0x7f0000001000));
        $this->assertTrue($resolver->isExecutable(0x7f0000001000));
    }

    public function testSkipsAnonymousMappingNames(): void
    {
        // [heap], [stack], [vdso] etc. are not "modules" — the resolver
        // should ignore them so the detector falls into the anon-exec
        // branch (MEDIUM rather than HIGH).
        $resolver = new ProcessMemoryMapModuleResolver(new ProcessMemoryMap([
            $this->area('0x7f0000000000', '0x7f0000200000', '[heap]', execute: true),
        ]));
        $this->assertNull($resolver->moduleBasenameFor(0x7f0000001000));
        $this->assertTrue($resolver->isExecutable(0x7f0000001000));
    }

    public function testReturnsNullWhenAddressNotMapped(): void
    {
        $resolver = new ProcessMemoryMapModuleResolver(new ProcessMemoryMap([
            $this->area('0x7f0000000000', '0x7f0000200000', '/usr/lib/libuv.so.1', execute: true),
        ]));
        $this->assertNull($resolver->moduleBasenameFor(0x7f0000300000));
        $this->assertFalse($resolver->isExecutable(0x7f0000300000));
    }

    public function testIsExecutableFalseForRoMapping(): void
    {
        $resolver = new ProcessMemoryMapModuleResolver(new ProcessMemoryMap([
            $this->area('0x7f0000000000', '0x7f0000200000', '/usr/lib/libfoo.so', execute: false),
        ]));
        $this->assertFalse($resolver->isExecutable(0x7f0000001000));
    }

    private function area(
        string $begin,
        string $end,
        string $name,
        bool $execute = true,
    ): ProcessMemoryArea {
        return new ProcessMemoryArea(
            begin: $begin,
            end: $end,
            file_offset: '0',
            attribute: new ProcessMemoryAttribute(
                read: true,
                write: false,
                execute: $execute,
                protected: false,
            ),
            device_id: '00:00',
            inode_num: 0,
            name: $name,
        );
    }
}
