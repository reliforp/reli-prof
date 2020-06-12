<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Loop\LoopMiddleware;

use PHPUnit\Framework\TestCase;

class CallableLoopTest extends TestCase
{
    public function testInvoke(): void
    {
        $side_effect = false;
        $loop = new CallableMiddleware(function () use (&$side_effect) {
            $side_effect = true;
            return true;
        });
        $this->assertSame(true, $loop->invoke());
        $this->assertSame(true, $side_effect);
    }
}
