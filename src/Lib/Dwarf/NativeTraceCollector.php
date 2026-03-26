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
    private ?int $cachedPid = null;

    public function __construct(
        private Ptrace $ptrace,
        private MemoryReaderInterface $memoryReader,
        private ProcessMemoryMapReader $memoryMapReader,
        private ProcessMemoryMapParser $memoryMapParser,
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
        $this->ehFrameCache = new ModuleEhFrameCache();
        $this->unwinder = new NativeStackUnwinder($this->ehFrameCache, $this->memoryReader);
        $this->symbolResolver = new NativeSymbolResolver($this->memoryMap);
        $this->cachedPid = $pid;
    }

    private function readRegisters(int $pid): ?RegisterState
    {
        try {
            // Allocate user_regs_struct via FFI
            $ffi = \FFI::cdef('
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

            $regs = $ffi->new('struct user_regs_struct');
            if ($regs === null) {
                return null;
            }

            $result = $this->ptrace->ptrace(
                PtraceRequest::PTRACE_GETREGS,
                $pid,
                null,
                $regs,
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
