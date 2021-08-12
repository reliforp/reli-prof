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

namespace PhpProfiler\Lib\Defer;

use PHPUnit\Framework\TestCase;

class DeferTest extends TestCase
{
    public function testDefer()
    {
        $result = [];
        $f = function ($i) use (&$result) {
            $result[] = $i;
        };

        (function () use ($f, &$result) {
            defer($_, fn () => $f(1));
            defer($_, fn () => $f(2));
            defer($_, fn () => $f(3));
            $this->assertSame([], $result);
        })();

        $this->assertSame([3, 2, 1], $result);
    }
}
