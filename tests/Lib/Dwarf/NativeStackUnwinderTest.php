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

use Mockery;
use Reli\BaseTestCase;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

class NativeStackUnwinderTest extends BaseTestCase
{
    private function makeMap(array $entries): ProcessMemoryMap
    {
        $attr = new ProcessMemoryAttribute(true, false, true, false);
        $areas = [];
        foreach ($entries as [$begin, $end, $offset, $name]) {
            $areas[] = new ProcessMemoryArea(
                $begin,
                $end,
                $offset,
                $attr,
                '00:00',
                0,
                $name,
            );
        }
        return new ProcessMemoryMap($areas);
    }

    public function testUnwindEmptyMap(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $eh_cache = new ModuleEhFrameCache();

        $unwinder = new NativeStackUnwinder($eh_cache, $memory_reader);

        $regs = new RegisterState();
        $regs->set(RegisterState::RIP, 0x1000);
        $regs->set(RegisterState::RSP, 0x7fff0000);
        $regs->set(RegisterState::RBP, 0);

        $map = $this->makeMap([]);

        $trace = $unwinder->unwind(
            1,
            $regs,
            $map,
            fn(int $addr): ?array => null,
            10,
        );

        // With empty map, should get 1 frame (the RIP) and then stop
        $this->assertGreaterThanOrEqual(1, count($trace->frames));
        $this->assertSame(0x1000, $trace->frames[0]->ip);
        $this->assertSame('??', $trace->frames[0]->moduleName);
    }

    public function testUnwindZeroRip(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $eh_cache = new ModuleEhFrameCache();

        $unwinder = new NativeStackUnwinder($eh_cache, $memory_reader);

        $regs = new RegisterState();
        $regs->set(RegisterState::RIP, 0);
        $regs->set(RegisterState::RSP, 0x7fff0000);

        $map = $this->makeMap([]);

        $trace = $unwinder->unwind(1, $regs, $map, fn(int $addr): ?array => null);

        $this->assertCount(0, $trace->frames);
    }

    public function testUnwindAnonymousMapping(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $eh_cache = new ModuleEhFrameCache();

        $unwinder = new NativeStackUnwinder($eh_cache, $memory_reader);

        $regs = new RegisterState();
        $regs->set(RegisterState::RIP, 0x7f0000050000);
        $regs->set(RegisterState::RSP, 0x7fff0000);
        $regs->set(RegisterState::RBP, 0);

        // Anonymous mapping (empty name)
        $map = $this->makeMap([
            ['7f0000000000', '7f0000100000', '00000000', ''],
        ]);

        $trace = $unwinder->unwind(
            1,
            $regs,
            $map,
            fn(int $addr): ?array => ['JIT$test', 0x50],
            5,
        );

        $this->assertGreaterThanOrEqual(1, count($trace->frames));
        $this->assertSame('[jit]', $trace->frames[0]->moduleName);
        $this->assertSame('JIT$test', $trace->frames[0]->symbolName);
    }

    public function testSymbolResolutionDeferred(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $eh_cache = new ModuleEhFrameCache();

        $unwinder = new NativeStackUnwinder($eh_cache, $memory_reader);

        $regs = new RegisterState();
        $regs->set(RegisterState::RIP, 0x7f0000050000);
        $regs->set(RegisterState::RSP, 0x7fff0000);

        $map = $this->makeMap([
            ['7f0000000000', '7f0000100000', '00000000', ''],
        ]);

        $resolve_count = 0;
        $trace = $unwinder->unwind(
            1,
            $regs,
            $map,
            function (int $addr) use (&$resolve_count): ?array {
                $resolve_count++;
                return ['func', 0];
            },
            5,
        );

        // Symbol resolver should be called for each frame
        $this->assertSame(count($trace->frames), $resolve_count);
    }

    public function testMaxFramesLimit(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $eh_cache = new ModuleEhFrameCache();

        $unwinder = new NativeStackUnwinder($eh_cache, $memory_reader);

        $regs = new RegisterState();
        $regs->set(RegisterState::RIP, 0x7f0000050000);
        $regs->set(RegisterState::RSP, 0x7fff0000);
        $regs->set(RegisterState::RBP, 0);

        $map = $this->makeMap([
            ['7f0000000000', '7f0000100000', '00000000', ''],
        ]);

        // maxFrames = 1 should limit to exactly 1 frame
        $trace = $unwinder->unwind(
            1,
            $regs,
            $map,
            fn(int $addr): ?array => null,
            1,
        );

        $this->assertCount(1, $trace->frames);
    }

    public function testIsFileCaching(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $eh_cache = new ModuleEhFrameCache();

        $unwinder = new NativeStackUnwinder($eh_cache, $memory_reader);

        $regs = new RegisterState();
        $regs->set(RegisterState::RIP, hexdec('560000001000'));
        $regs->set(RegisterState::RSP, 0x7fff0000);
        $regs->set(RegisterState::RBP, 0);

        // Use a path that doesn't exist as a file
        $map = $this->makeMap([
            ['560000000000', '560000100000', '00000000', '/dev/zero (deleted)'],
        ]);

        $trace = $unwinder->unwind(
            1,
            $regs,
            $map,
            fn(int $addr): ?array => null,
            1,
        );

        // /dev/zero (deleted) is not a regular file → treated as JIT
        $this->assertSame('[jit]', $trace->frames[0]->moduleName);
    }
}
