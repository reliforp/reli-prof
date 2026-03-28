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

/**
 * Architecture-aware register state with DWARF register number mapping.
 *
 * DWARF register numbers for x86_64 (System V ABI):
 *  0: RAX,  1: RDX,  2: RCX,  3: RBX,  4: RSI,  5: RDI,
 *  6: RBP,  7: RSP,  8: R8,   9: R9,  10: R10, 11: R11,
 * 12: R12, 13: R13, 14: R14, 15: R15, 16: RIP (Return Address)
 *
 * DWARF register numbers for AArch64:
 *  0-30: X0-X30,  31: SP,  32: PC
 */
final class RegisterState
{
    // x86_64 DWARF register numbers
    public const RAX = 0;
    public const RDX = 1;
    public const RCX = 2;
    public const RBX = 3;
    public const RSI = 4;
    public const RDI = 5;
    public const RBP = 6;
    public const RSP = 7;
    public const R8  = 8;
    public const R9  = 9;
    public const R10 = 10;
    public const R11 = 11;
    public const R12 = 12;
    public const R13 = 13;
    public const R14 = 14;
    public const R15 = 15;
    public const RIP = 16;

    /** @var array<int, int> DWARF register number => value */
    private array $registers = [];

    public function __construct(
        private DwarfArchitecture $architecture = DwarfArchitecture::X86_64,
    ) {
    }

    public function getArchitecture(): DwarfArchitecture
    {
        return $this->architecture;
    }

    public function get(int $dwarfRegNum): int
    {
        return $this->registers[$dwarfRegNum] ?? 0;
    }

    public function set(int $dwarfRegNum, int $value): void
    {
        $this->registers[$dwarfRegNum] = $value;
    }

    public function getRip(): int
    {
        return $this->get($this->architecture->ipRegister());
    }

    public function getRsp(): int
    {
        return $this->get($this->architecture->spRegister());
    }

    public function getRbp(): int
    {
        return $this->get($this->architecture->fpRegister());
    }

    /**
     * @return array<int, int>
     */
    public function toArray(): array
    {
        return $this->registers;
    }

    /**
     * Create from x86_64 ptrace user_regs_struct FFI data.
     *
     * @psalm-suppress UndefinedPropertyFetch
     */
    public static function fromPtraceRegs(\FFI\CData $regs): self
    {
        $state = new self(DwarfArchitecture::X86_64);
        $state->set(self::R15, (int)$regs->r15);
        $state->set(self::R14, (int)$regs->r14);
        $state->set(self::R13, (int)$regs->r13);
        $state->set(self::R12, (int)$regs->r12);
        $state->set(self::RBP, (int)$regs->bp);
        $state->set(self::RBX, (int)$regs->bx);
        $state->set(self::R11, (int)$regs->r11);
        $state->set(self::R10, (int)$regs->r10);
        $state->set(self::R9, (int)$regs->r9);
        $state->set(self::R8, (int)$regs->r8);
        $state->set(self::RAX, (int)$regs->ax);
        $state->set(self::RCX, (int)$regs->cx);
        $state->set(self::RDX, (int)$regs->dx);
        $state->set(self::RSI, (int)$regs->si);
        $state->set(self::RDI, (int)$regs->di);
        $state->set(self::RIP, (int)$regs->ip);
        $state->set(self::RSP, (int)$regs->sp);
        return $state;
    }

    /**
     * Create from AArch64 ptrace user_pt_regs FFI data.
     *
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress MixedArrayAccess
     */
    public static function fromAarch64PtraceRegs(\FFI\CData $regs): self
    {
        $state = new self(DwarfArchitecture::AARCH64);
        for ($i = 0; $i <= 30; $i++) {
            $state->set($i, (int)$regs->regs[$i]);
        }
        $state->set(31, (int)$regs->sp);
        $state->set(32, (int)$regs->pc);
        return $state;
    }
}
