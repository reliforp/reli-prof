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

class RegisterStateTest extends BaseTestCase
{
    public function testSetAndGet(): void
    {
        $state = new RegisterState();
        $state->set(RegisterState::RAX, 42);
        $this->assertSame(42, $state->get(RegisterState::RAX));
    }

    public function testGetUnsetReturnsZero(): void
    {
        $state = new RegisterState();
        $this->assertSame(0, $state->get(RegisterState::RBX));
    }

    public function testConvenienceMethods(): void
    {
        $state = new RegisterState();
        $state->set(RegisterState::RIP, 0x400000);
        $state->set(RegisterState::RSP, 0x7fff0000);
        $state->set(RegisterState::RBP, 0x7fff0010);

        $this->assertSame(0x400000, $state->getRip());
        $this->assertSame(0x7fff0000, $state->getRsp());
        $this->assertSame(0x7fff0010, $state->getRbp());
    }

    public function testToArray(): void
    {
        $state = new RegisterState();
        $state->set(RegisterState::RAX, 1);
        $state->set(RegisterState::RBX, 2);

        $arr = $state->toArray();
        $this->assertSame(1, $arr[RegisterState::RAX]);
        $this->assertSame(2, $arr[RegisterState::RBX]);
    }

    public function testDwarfRegisterMapping(): void
    {
        // Verify DWARF register number mapping
        $this->assertSame(0, RegisterState::RAX);
        $this->assertSame(1, RegisterState::RDX);
        $this->assertSame(2, RegisterState::RCX);
        $this->assertSame(3, RegisterState::RBX);
        $this->assertSame(4, RegisterState::RSI);
        $this->assertSame(5, RegisterState::RDI);
        $this->assertSame(6, RegisterState::RBP);
        $this->assertSame(7, RegisterState::RSP);
        $this->assertSame(16, RegisterState::RIP);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function testDefaultArchitectureIsX86_64(): void
    {
        $state = new RegisterState();
        $this->assertSame(DwarfArchitecture::X86_64, $state->getArchitecture());
    }

    public function testAarch64Architecture(): void
    {
        $state = new RegisterState(DwarfArchitecture::AARCH64);
        $this->assertSame(DwarfArchitecture::AARCH64, $state->getArchitecture());
    }

    public function testAarch64ConvenienceMethods(): void
    {
        $state = new RegisterState(DwarfArchitecture::AARCH64);
        // AArch64 DWARF: PC=32, SP=31, FP(X29)=29
        $state->set(32, 0x400000);  // PC
        $state->set(31, 0x7fff0000);  // SP
        $state->set(29, 0x7fff0010);  // FP (X29)

        $this->assertSame(0x400000, $state->getRip());
        $this->assertSame(0x7fff0000, $state->getRsp());
        $this->assertSame(0x7fff0010, $state->getRbp());
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function testX86_64ConvenienceMethodsUnchanged(): void
    {
        // Ensure x86_64 convenience methods still use the correct registers
        $state = new RegisterState(DwarfArchitecture::X86_64);
        $state->set(16, 0x400000);  // RIP
        $state->set(7, 0x7fff0000);  // RSP
        $state->set(6, 0x7fff0010);  // RBP

        $this->assertSame(0x400000, $state->getRip());
        $this->assertSame(0x7fff0000, $state->getRsp());
        $this->assertSame(0x7fff0010, $state->getRbp());
    }
}
