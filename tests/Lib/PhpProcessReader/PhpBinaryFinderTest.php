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

namespace Reli\Lib\PhpProcessReader;

use Reli\BaseTestCase;

class PhpBinaryFinderTest extends BaseTestCase
{
    public function testFindByProcessId()
    {
        $finder = new PhpBinaryFinder();
        $path = $finder->findByProcessId(getmypid());
        $this->assertStringContainsString('php', $path);
    }
}
