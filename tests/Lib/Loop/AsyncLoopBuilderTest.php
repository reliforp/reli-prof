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

namespace Reli\Lib\Loop;

use Reli\BaseTestCase;
use Reli\Lib\Loop\AsyncLoopMiddleware\CallableMiddlewareAsync;

class AsyncLoopBuilderTest extends BaseTestCase
{
    public function testBuild(): void
    {
        $builder = new AsyncLoopBuilder();
        $new_builder = $builder->addProcess(CallableMiddlewareAsync::class, [
            function () {
                yield 1;
                yield 2;
                yield 3;
            }
        ]);
        $loop = $new_builder->build();
        $result = $loop->invoke();
        $this->assertSame(1, $result->current());
        $result->next();
        $this->assertSame(2, $result->current());
        $result->next();
        $this->assertSame(3, $result->current());
        $result->next();
        $this->assertSame(1, $result->current());
        $result->next();
        $this->assertSame(2, $result->current());
        $result->next();
        $this->assertSame(3, $result->current());

        $this->assertNotSame($builder, $new_builder);
    }
}
