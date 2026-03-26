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

class FdeTest extends BaseTestCase
{
    public function testContainsAddress(): void
    {
        $cie = new Cie(1, -8, 16, '', 'zR', 0, null, null, null);
        $fde = new Fde(0x1000, 0x100, '', $cie);

        $this->assertTrue($fde->containsAddress(0x1000));
        $this->assertTrue($fde->containsAddress(0x1050));
        $this->assertTrue($fde->containsAddress(0x10ff));
        $this->assertFalse($fde->containsAddress(0x0fff));
        $this->assertFalse($fde->containsAddress(0x1100));
    }
}
