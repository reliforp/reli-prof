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

namespace PhpProfiler\Inspector\Daemon\Dispatcher;

use PHPUnit\Framework\TestCase;

class TargetProcessDescriptorTest extends TestCase
{
    public function testGetInvalid()
    {
        $this->assertTrue(
            TargetProcessDescriptor::getInvalid() === TargetProcessDescriptor::getInvalid()
        );
        $this->assertSame(0, TargetProcessDescriptor::getInvalid()->pid);
    }
}
