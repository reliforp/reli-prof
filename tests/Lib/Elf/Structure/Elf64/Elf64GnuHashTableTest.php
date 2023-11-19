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

class Elf64GnuHashTableTest extends BaseTestCase
{
    public function testHash()
    {
        $this->assertSame(0x00001505, Elf64GnuHashTable::hash(''));
        $this->assertSame(0x156b2bb8, Elf64GnuHashTable::hash('printf'));
        $this->assertSame(0x7c967e3f, Elf64GnuHashTable::hash('exit'));
        $this->assertSame(0xbac212a0, Elf64GnuHashTable::hash('syscall'));
    }
}
