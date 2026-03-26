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

class NativeFrameTest extends BaseTestCase
{
    public function testGetDisplayNameWithSymbol(): void
    {
        $frame = new NativeFrame(0x1000, 'execute_ex', 'php', 0);
        $this->assertSame('execute_ex', $frame->getDisplayName());
    }

    public function testGetDisplayNameWithOffset(): void
    {
        $frame = new NativeFrame(0x1042, 'execute_ex', 'php', 0x42);
        $this->assertSame('execute_ex+0x42', $frame->getDisplayName());
    }

    public function testGetDisplayNameWithoutSymbol(): void
    {
        $frame = new NativeFrame(0x7f1234abcd, null, 'unknown', 0);
        $this->assertSame('0x7f1234abcd', $frame->getDisplayName());
    }
}
