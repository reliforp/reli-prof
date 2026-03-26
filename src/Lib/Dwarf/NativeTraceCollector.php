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

use Reli\Lib\Elf\Process\ProcessSymbolReaderInterface;
use Reli\Lib\File\NativeFileReader;
use Reli\Lib\Libc\Sys\Ptrace\Ptrace;
use Reli\Lib\Libc\Sys\Ptrace\PtraceRequest;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapParser;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapReader;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class NativeTraceCollector
{
    private ?ModuleEhFrameCache $ehFrameCache = null;
    private ?NativeStackUnwinder $unwinder = null;
    private ?NativeSymbolResolver $symbolResolver = null;
    private ?ProcessMemoryMap $memoryMap = null;
    private ?JitCodeReader $jitCodeReader = null;
    private ?PerfMapSymbolResolver $perfMapResolver = null;
    private ?int $cachedPid = null;
    private ?\FFI $regsFfi = null;
    private ?\FFI\CData $regsBuffer = null;

    public function __construct(
        private Ptrace $ptrace,
        private MemoryReaderInterface $memoryReader,
        private ProcessMemoryMapReader $memoryMapReader,
        private ProcessMemoryMapParser $memoryMapParser,
        private ?ProcessSymbolReaderInterface $phpSymbolReader = null,
    ) {
    }

    public function collect(int $pid): ?NativeTrace
    {
        try {
            // Ensure we have cached data for this pid
            if ($this->cachedPid !== $pid) {
                $this->initForPid($pid);
            }

            // Read registers via ptrace GETREGS
            $registers = $this->readRegisters($pid);
            if ($registers === null) {
                return null;
            }

            assert($this->unwinder !== null);
            assert($this->memoryMap !== null);
            assert($this->symbolResolver !== null);

            $symbol_resolver = $this->symbolResolver;

            return $this->unwinder->unwind(
                $pid,
                $registers,
                $this->memoryMap,
                fn(int $address): ?array => $symbol_resolver->resolve($address),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function refreshMemoryMap(int $pid): void
    {
        $this->initForPid($pid);
    }

    private function initForPid(int $pid): void
    {
        $maps_string = $this->memoryMapReader->read($pid);
        $this->memoryMap = $this->memoryMapParser->parse($maps_string);
        $process_root = "/proc/{$pid}/root";
        $file_reader = new NativeFileReader();
        $this->ehFrameCache = new ModuleEhFrameCache(
            processRoot: $process_root,
            fileReader: $file_reader,
        );
        $this->perfMapResolver = new PerfMapSymbolResolver($pid);

        // Try to load JIT debug info via __jit_debug_descriptor
        $this->jitCodeReader = $this->tryLoadJitDebugInfo($pid);

        $this->unwinder = new NativeStackUnwinder(
            $this->ehFrameCache,
            $this->memoryReader,
            $this->jitCodeReader,
            $process_root,
        );
        $this->symbolResolver = new NativeSymbolResolver(
            $this->memoryMap,
            perfMapResolver: $this->perfMapResolver,
            processRoot: $process_root,
            fileReader: $file_reader,
        );
        $this->cachedPid = $pid;
    }

    private function tryLoadJitDebugInfo(int $pid): ?JitCodeReader
    {
        if ($this->phpSymbolReader === null) {
            return null;
        }

        try {
            // __jit_debug_descriptor is defined in ir_gdb.c (PHP JIT)
            $descriptor_address = $this->phpSymbolReader->resolveAddress('__jit_debug_descriptor');
            if ($descriptor_address === null) {
                return null;
            }

            $reader = new JitCodeReader($this->memoryReader);
            $reader->load($pid, $descriptor_address);

            if ($reader->hasFdes()) {
                return $reader;
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureRegsFfi(): void
    {
        if ($this->regsFfi === null) {
            $this->regsFfi = \FFI::cdef('
                struct user_regs_struct {
                    unsigned long r15;
                    unsigned long r14;
                    unsigned long r13;
                    unsigned long r12;
                    unsigned long bp;
                    unsigned long bx;
                    unsigned long r11;
                    unsigned long r10;
                    unsigned long r9;
                    unsigned long r8;
                    unsigned long ax;
                    unsigned long cx;
                    unsigned long dx;
                    unsigned long si;
                    unsigned long di;
                    unsigned long orig_ax;
                    unsigned long ip;
                    unsigned long cs;
                    unsigned long flags;
                    unsigned long sp;
                    unsigned long ss;
                    unsigned long fs_base;
                    unsigned long gs_base;
                    unsigned long ds;
                    unsigned long es;
                    unsigned long fs;
                    unsigned long gs;
                };
            ');
            $this->regsBuffer = $this->regsFfi->new('struct user_regs_struct');
        }
    }

    private function readRegisters(int $pid): ?RegisterState
    {
        try {
            $this->ensureRegsFfi();
            $regs = $this->regsBuffer;
            if ($regs === null) {
                return null;
            }

            $result = $this->ptrace->ptrace(
                PtraceRequest::PTRACE_GETREGS,
                $pid,
                null,
                \FFI::addr($regs),
            );

            if ($result === -1) {
                return null;
            }

            return RegisterState::fromPtraceRegs($regs);
        } catch (\Throwable) {
            return null;
        }
    }
}
