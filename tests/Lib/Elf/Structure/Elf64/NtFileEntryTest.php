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

namespace Reli\Lib\Elf\Structure\Elf64;

use Reli\BaseTestCase;
use Reli\Lib\Integer\UInt64;

class NtFileEntryTest extends BaseTestCase
{
    public function testIsInRangeTreatsEndAsExclusive(): void
    {
        $entry = new NtFileEntry(
            '/lib/libfoo.so',
            UInt64::fromInt(0x1000),
            UInt64::fromInt(0x2000),
            UInt64::fromInt(0),
        );

        $this->assertTrue($entry->isInRange(UInt64::fromInt(0x1000)));
        $this->assertTrue($entry->isInRange(UInt64::fromInt(0x1fff)));
        // The exclusive `end` is the regression-pin: when two libraries are
        // mapped back-to-back this address belongs to the *next* entry, not
        // this one.
        $this->assertFalse($entry->isInRange(UInt64::fromInt(0x2000)));
        $this->assertFalse($entry->isInRange(UInt64::fromInt(0x0fff)));
    }
}
