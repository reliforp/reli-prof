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

namespace Reli\Lib\Loop\LoopMiddleware;

use ReflectionClass;
use Reli\BaseTestCase;

class KeyboardCancelLoopTest extends BaseTestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testReturnFalseIfCancelKeyPressed(): void
    {
        $reflection = new ReflectionClass(KeyboardCancelMiddleware::class);
        $keyboard_cancel_loop = $reflection->newInstanceWithoutConstructor();
        $keyboard_input_stream = fopen('php://memory', 'rw');
        (function () use ($keyboard_input_stream) {
            /** @var KeyboardCancelMiddleware $this */
            $this->chain = new CallableMiddleware(fn () => true);
            $this->cancel_key = 'q';
            $this->keyboard_input = $keyboard_input_stream;
        })->bindTo($keyboard_cancel_loop, $keyboard_cancel_loop)();
        $this->assertTrue($keyboard_cancel_loop->invoke());
        fwrite($keyboard_input_stream, 'q');
        rewind($keyboard_input_stream);
        $this->assertFalse($keyboard_cancel_loop->invoke());
    }
}
