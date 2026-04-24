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

namespace Reli\Lib\Process\ProcFileSystem;

use Reli\BaseTestCase;

class ThreadNameReaderTest extends BaseTestCase
{
    public function testReadReturnsCurrentProcessComm(): void
    {
        $reader = new ThreadNameReader();
        $pid = getmypid();
        $this->assertNotFalse($pid);

        $expected = trim((string)file_get_contents("/proc/{$pid}/comm"));
        $this->assertSame($expected, $reader->read($pid));
    }

    public function testReadReturnsNullForNonexistentTid(): void
    {
        $reader = new ThreadNameReader();
        $this->assertNull($reader->read(0));
    }
}
