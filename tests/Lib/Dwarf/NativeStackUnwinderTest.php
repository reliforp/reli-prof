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
use Reli\Lib\FFI\FFIHelper;

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

    public function testUnwindWithRealEhFrame(): void
    {
        // Use the actual PHP binary's .eh_frame to test real DWARF unwinding
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $eh_cache = new ModuleEhFrameCache();

        // Pre-load the PHP binary's eh_frame
        $fdes = $eh_cache->getFdesForModule(PHP_BINARY);
        if ($fdes === null || $fdes === []) {
            $this->markTestSkipped('No .eh_frame in PHP binary');
        }

        $unwinder = new NativeStackUnwinder($eh_cache, $memory_reader);

        // Pick a known FDE and simulate being at its initial location
        $fde = $fdes[0];

        $regs = new RegisterState();
        // Simulate RIP at first FDE's start + some offset into the function
        $regs->set(RegisterState::RIP, $fde->initialLocation + 4);
        $regs->set(RegisterState::RSP, 0x7fff0000);
        $regs->set(RegisterState::RBP, 0x7fff0010);

        // The unwinder will try to read return address from CFA
        // Mock: return 0 (will stop unwinding)
        $zero_bytes = FFIHelper::new('unsigned char[8]');
        for ($i = 0; $i < 8; $i++) {
            $zero_bytes[$i] = 0;
        }
        $memory_reader->allows('read')->andReturn($zero_bytes);

        $map = $this->makeMap([
            [
                dechex($fde->initialLocation),
                dechex($fde->initialLocation + $fde->addressRange),
                '00000000',
                PHP_BINARY,
            ],
        ]);

        $trace = $unwinder->unwind(
            1,
            $regs,
            $map,
            fn(int $addr): ?array => null,
            10,
        );

        // Should get at least the first frame (the one we started at)
        $this->assertGreaterThanOrEqual(1, count($trace->frames));
        $this->assertSame(
            $fde->initialLocation + 4,
            $trace->frames[0]->ip,
        );
    }

    public function testUnwindWithRealPhpBinary(): void
    {
        // Integration test: use actual PHP binary .eh_frame and
        // verify the CIE/FDE decoding + row lookup works end-to-end
        $eh_cache = new ModuleEhFrameCache();
        $fdes = $eh_cache->getFdesForModule(PHP_BINARY);
        if ($fdes === null || count($fdes) < 10) {
            $this->markTestSkipped('Not enough FDEs in PHP binary');
        }

        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $zero_bytes = FFIHelper::new('unsigned char[8]');
        for ($i = 0; $i < 8; $i++) {
            $zero_bytes[$i] = 0;
        }
        $memory_reader->allows('read')->andReturn($zero_bytes);

        $unwinder = new NativeStackUnwinder($eh_cache, $memory_reader);

        // Test multiple FDEs to exercise CIE caching
        $tested = 0;
        foreach (array_slice($fdes, 0, 20) as $fde) {
            if ($fde->addressRange < 8) {
                continue;
            }

            $regs = new RegisterState();
            $regs->set(
                RegisterState::RIP,
                $fde->initialLocation + 4,
            );
            $regs->set(RegisterState::RSP, 0x7fff0000);

            $map = $this->makeMap([
                [
                    dechex($fde->initialLocation),
                    dechex(
                        $fde->initialLocation + $fde->addressRange
                    ),
                    '00000000',
                    PHP_BINARY,
                ],
            ]);

            $trace = $unwinder->unwind(
                1,
                $regs,
                $map,
                fn(int $addr): ?array => null,
                3,
            );

            $this->assertGreaterThanOrEqual(1, count($trace->frames));
            $tested++;
        }
        $this->assertGreaterThan(5, $tested, 'Should test several FDEs');
    }

    public function testFramePointerUnwindPath(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $eh_cache = new ModuleEhFrameCache();

        // Simulate frame pointer chain:
        // [rbp] = saved_rbp, [rbp+8] = return_address
        $saved_rbp_bytes = FFIHelper::new('unsigned char[8]');
        // saved_rbp = 0x7fff0100 (higher than current rbp)
        $saved_rbp_bytes[0] = 0x00;
        $saved_rbp_bytes[1] = 0x01;
        $saved_rbp_bytes[2] = 0xff;
        $saved_rbp_bytes[3] = 0x7f;
        for ($i = 4; $i < 8; $i++) {
            $saved_rbp_bytes[$i] = 0;
        }

        $ret_addr_bytes = FFIHelper::new('unsigned char[8]');
        // return address = 0x400100
        $ret_addr_bytes[0] = 0x00;
        $ret_addr_bytes[1] = 0x01;
        $ret_addr_bytes[2] = 0x40;
        for ($i = 3; $i < 8; $i++) {
            $ret_addr_bytes[$i] = 0;
        }

        $zero_bytes = FFIHelper::new('unsigned char[8]');
        for ($i = 0; $i < 8; $i++) {
            $zero_bytes[$i] = 0;
        }

        // First read: saved_rbp at [rbp]
        // Second read: return_address at [rbp+8]
        // Third read onwards: zero (stop)
        $memory_reader->expects('read')
            ->with(1, 0x7fff0050, 8)
            ->andReturn($saved_rbp_bytes);
        $memory_reader->expects('read')
            ->with(1, 0x7fff0058, 8)
            ->andReturn($ret_addr_bytes);
        $memory_reader->allows('read')
            ->andReturn($zero_bytes);

        $unwinder = new NativeStackUnwinder($eh_cache, $memory_reader);

        $regs = new RegisterState();
        // RIP in unmapped area → no FDE → frame pointer fallback
        $regs->set(RegisterState::RIP, 0x7f0000050000);
        $regs->set(RegisterState::RSP, 0x7fff0040);
        $regs->set(RegisterState::RBP, 0x7fff0050);

        $map = $this->makeMap([
            ['7f0000000000', '7f0000100000', '00000000', ''],
        ]);

        $trace = $unwinder->unwind(
            1,
            $regs,
            $map,
            fn(int $addr): ?array => null,
            5,
        );

        // Should have at least 2 frames: the initial one + frame pointer unwound
        $this->assertGreaterThanOrEqual(2, count($trace->frames));
        $this->assertSame(0x7f0000050000, $trace->frames[0]->ip);
        // Second frame should be the return address from FP unwinding
        $this->assertSame(0x400100, $trace->frames[1]->ip);
    }

    public function testProcessRootAffectsIsFile(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $eh_cache = new ModuleEhFrameCache();

        // With processRoot pointing to /proc/self/root, PHP binary
        // should be recognized as a file
        $unwinder = new NativeStackUnwinder(
            $eh_cache,
            $memory_reader,
            processRoot: '/proc/self/root',
        );

        $regs = new RegisterState();
        $regs->set(RegisterState::RIP, 0x560000001000);
        $regs->set(RegisterState::RSP, 0x7fff0000);
        $regs->set(RegisterState::RBP, 0);

        $map = $this->makeMap([
            [
                '560000000000',
                '560000100000',
                '00000000',
                '/usr/bin/php',
            ],
        ]);

        $trace = $unwinder->unwind(
            1,
            $regs,
            $map,
            fn(int $addr): ?array => null,
            1,
        );

        // /usr/bin/php via /proc/self/root should be a real file
        $this->assertNotSame('[jit]', $trace->frames[0]->moduleName);
    }
}
