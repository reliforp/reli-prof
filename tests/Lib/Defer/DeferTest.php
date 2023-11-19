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

namespace Reli\Lib\Defer;

use Reli\BaseTestCase;

class DeferTest extends BaseTestCase
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
