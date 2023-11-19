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

use Exception;
use LogicException;
use Reli\BaseTestCase;
use Reli\Lib\Loop\LoopMiddleware\CallableMiddleware;
use Reli\Lib\Loop\LoopMiddleware\RetryOnExceptionMiddleware;

class LoopBuilderTest extends BaseTestCase
{
    public function testBuild(): void
    {
        $call_counter = 0;
        $execute_counter = 0;
        $builder = new LoopBuilder();
        $loop = $builder->addProcess(RetryOnExceptionMiddleware::class, [1, [Exception::class]])
            ->addProcess(
                CallableMiddleware::class,
                [
                    function () use (&$call_counter, &$execute_counter): bool {
                        if (++$call_counter === 1) {
                            throw new Exception();
                        }
                        if (++$execute_counter === 3) {
                            return false;
                        }
                        return true;
                    }
                ]
            )
            ->build();
        $loop->invoke();
        $this->assertSame(4, $call_counter);
        $this->assertSame(3, $execute_counter);
    }

    public function testThrowIfNotLoopProcess(): void
    {
        $builder = new LoopBuilder();
        $this->expectException(LogicException::class);
        $builder->addProcess('abcde', []);
    }

    public function testThrowIfNoLoopProcess(): void
    {
        $builder = new LoopBuilder();
        $this->expectException(LogicException::class);
        $builder->build();
    }
}
