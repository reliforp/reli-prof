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

namespace Reli\Inspector\Watch\Trigger;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\WatchContext;

class ExceptionDetectionTriggerTest extends TestCase
{
    public function testFiresWhenExceptionInFlight(): void
    {
        $trigger = new ExceptionDetectionTrigger();
        $context = $this->makeContext(true);

        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);
        $this->assertSame('on-exception', $event->trigger_name);
    }

    public function testDoesNotFireWhenNoException(): void
    {
        $trigger = new ExceptionDetectionTrigger();

        $this->assertNull($trigger->evaluate($this->makeContext(false)));
        $this->assertNull($trigger->evaluate($this->makeContext(null)));
    }

    public function testProperties(): void
    {
        $trigger = new ExceptionDetectionTrigger();
        $this->assertFalse($trigger->requiresCallTrace());
        $this->assertTrue($trigger->requiresDeepInspection());
    }

    private function makeContext(?bool $hasException): WatchContext
    {
        return new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: null,
            has_exception: $hasException,
            timestamp: microtime(true),
            previous: null,
        );
    }
}
