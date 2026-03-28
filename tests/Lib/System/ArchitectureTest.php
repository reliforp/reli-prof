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

namespace Reli\Lib\System;

use Reli\BaseTestCase;

class ArchitectureTest extends BaseTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function testFromX86_64(): void
    {
        $this->assertSame(Architecture::X86_64, Architecture::from('x86_64'));
    }

    public function testFromAarch64(): void
    {
        $this->assertSame(Architecture::AARCH64, Architecture::from('aarch64'));
    }

    public function testFromInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        Architecture::from('mips');
    }

    public function testDetectReturnsValidArchitecture(): void
    {
        $arch = Architecture::detect();
        $this->assertContains($arch, [Architecture::X86_64, Architecture::AARCH64]);
    }
}
