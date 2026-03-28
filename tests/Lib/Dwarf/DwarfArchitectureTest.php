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
use Reli\Lib\System\Architecture;

class DwarfArchitectureTest extends BaseTestCase
{
    public function testX86RegisterNumbers(): void
    {
        $arch = DwarfArchitecture::X86_64;
        $this->assertSame(16, $arch->ipRegister());
        $this->assertSame(7, $arch->spRegister());
        $this->assertSame(6, $arch->fpRegister());
        $this->assertSame([3, 6, 12, 13, 14, 15], $arch->calleeSavedRegisters());
    }

    public function testAarch64RegisterNumbers(): void
    {
        $arch = DwarfArchitecture::AARCH64;
        $this->assertSame(32, $arch->ipRegister());
        $this->assertSame(31, $arch->spRegister());
        $this->assertSame(29, $arch->fpRegister());
        $this->assertSame(
            [19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30],
            $arch->calleeSavedRegisters()
        );
    }

    public function testFromArchitecture(): void
    {
        $this->assertSame(
            DwarfArchitecture::X86_64,
            DwarfArchitecture::fromArchitecture(Architecture::X86_64)
        );
        $this->assertSame(
            DwarfArchitecture::AARCH64,
            DwarfArchitecture::fromArchitecture(Architecture::AARCH64)
        );
    }
}
