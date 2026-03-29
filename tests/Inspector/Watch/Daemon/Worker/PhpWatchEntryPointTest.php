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

namespace Reli\Inspector\Watch\Daemon\Worker;

use PHPUnit\Framework\TestCase;
use Reli\Lib\Amphp\WorkerEntryPointInterface;

class PhpWatchEntryPointTest extends TestCase
{
    public function testImplementsWorkerEntryPoint(): void
    {
        $rc = new \ReflectionClass(PhpWatchEntryPoint::class);
        $this->assertTrue(
            $rc->implementsInterface(WorkerEntryPointInterface::class),
        );
    }

    public function testHasRunMethod(): void
    {
        $rc = new \ReflectionClass(PhpWatchEntryPoint::class);
        $this->assertTrue($rc->hasMethod('run'));
        $method = $rc->getMethod('run');
        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string)$method->getReturnType());
    }

    public function testConstructorDependencies(): void
    {
        $rc = new \ReflectionClass(PhpWatchEntryPoint::class);
        $constructor = $rc->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();

        $param_names = array_map(
            fn (\ReflectionParameter $p) => $p->getName(),
            $params,
        );
        $this->assertContains('heap_stats_reader', $param_names);
        $this->assertContains('call_trace_reader', $param_names);
        $this->assertContains('protocol', $param_names);
    }
}
