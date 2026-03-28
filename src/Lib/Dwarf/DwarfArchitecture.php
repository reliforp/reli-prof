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

use Reli\Lib\System\Architecture;

enum DwarfArchitecture
{
    case X86_64;
    case AARCH64;

    public static function fromArchitecture(Architecture $arch): self
    {
        return match ($arch) {
            Architecture::X86_64 => self::X86_64,
            Architecture::AARCH64 => self::AARCH64,
        };
    }

    public static function detect(): self
    {
        return self::fromArchitecture(Architecture::detect());
    }

    /** DWARF register number for instruction pointer (RIP / PC) */
    public function ipRegister(): int
    {
        return match ($this) {
            self::X86_64 => 16,
            self::AARCH64 => 32,
        };
    }

    /** DWARF register number for stack pointer (RSP / SP) */
    public function spRegister(): int
    {
        return match ($this) {
            self::X86_64 => 7,
            self::AARCH64 => 31,
        };
    }

    /** DWARF register number for frame pointer (RBP / X29) */
    public function fpRegister(): int
    {
        return match ($this) {
            self::X86_64 => 6,
            self::AARCH64 => 29,
        };
    }

    /**
     * DWARF register numbers for callee-saved registers that should be restored during unwinding.
     * @return int[]
     */
    public function calleeSavedRegisters(): array
    {
        return match ($this) {
            self::X86_64 => [3, 6, 12, 13, 14, 15], // RBX, RBP, R12-R15
            self::AARCH64 => [19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30], // x19-x30
        };
    }
}
