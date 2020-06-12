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

namespace PhpProfiler\Lib\Loop;

use PhpProfiler\Lib\Loop\LoopMiddleware\CallableMiddleware;
use PHPUnit\Framework\TestCase;

class LoopTest extends TestCase
{
    public function testInvoke()
    {
        $counter = 0;
        $loop = new Loop(
            new CallableMiddleware(
                function () use (&$counter) {
                    $counter++;
                    if ($counter >= 3) {
                        return false;
                    }
                    return true;
                }
            )
        );
        $loop->invoke();
        $this->assertSame(3, $counter);
    }
}
