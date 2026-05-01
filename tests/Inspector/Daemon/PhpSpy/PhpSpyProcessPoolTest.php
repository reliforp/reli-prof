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

namespace Reli\Inspector\Daemon\PhpSpy;

use Reli\BaseTestCase;
use Reli\Lib\PhpSpy\PhpSpyFinder;

class PhpSpyProcessPoolTest extends BaseTestCase
{
    public function testInitialState(): void
    {
        $finder = new PhpSpyFinder();
        $pool = new PhpSpyProcessPool($finder);

        $this->assertSame(0, $pool->count());
        $this->assertSame([], $pool->getActivePids());
        $this->assertFalse($pool->hasProcess(1));
    }

    public function testDetachNonExistentPidDoesNotThrow(): void
    {
        $finder = new PhpSpyFinder();
        $pool = new PhpSpyProcessPool($finder);

        $pool->detach(9999);
        $this->assertSame(0, $pool->count());
    }

    public function testStopAllOnEmptyPoolDoesNotThrow(): void
    {
        $finder = new PhpSpyFinder();
        $pool = new PhpSpyProcessPool($finder);

        $pool->stopAll();
        $this->assertSame(0, $pool->count());
    }

    public function testPassthroughAllOnEmptyPool(): void
    {
        $finder = new PhpSpyFinder();
        $pool = new PhpSpyProcessPool($finder);

        $stream = fopen('php://memory', 'w');
        assert($stream !== false);
        $pool->passthroughAll($stream);
        fclose($stream);

        $this->assertSame(0, $pool->count());
    }

    public function testConsumeAllOnEmptyPool(): void
    {
        $finder = new PhpSpyFinder();
        $pool = new PhpSpyProcessPool($finder);

        $called = false;
        $pool->consumeAll(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
        $this->assertSame(0, $pool->count());
    }

    public function testFlushParsersOnEmptyPool(): void
    {
        $finder = new PhpSpyFinder();
        $pool = new PhpSpyProcessPool($finder);

        $called = false;
        $pool->flushParsers(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }
}
