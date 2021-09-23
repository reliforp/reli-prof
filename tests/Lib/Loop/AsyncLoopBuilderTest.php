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

use PhpProfiler\Lib\Loop\AsyncLoopMiddleware\CallableMiddlewareAsync;
use PHPUnit\Framework\TestCase;

class AsyncLoopBuilderTest extends TestCase
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
