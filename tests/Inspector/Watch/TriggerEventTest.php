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

namespace Reli\Inspector\Watch;

use PHPUnit\Framework\TestCase;

class TriggerEventTest extends TestCase
{
    public function testConstruction(): void
    {
        $event = new TriggerEvent(
            trigger_name: 'memory-limit',
            description: 'mem=256M>128M',
            timestamp: 1234567890.5,
            value: 268435456.0,
        );

        $this->assertSame('memory-limit', $event->trigger_name);
        $this->assertSame('mem=256M>128M', $event->description);
        $this->assertSame(1234567890.5, $event->timestamp);
        $this->assertSame(268435456.0, $event->value);
    }

    public function testNullValue(): void
    {
        $event = new TriggerEvent(
            trigger_name: 'on-exception',
            description: 'exception in flight',
            timestamp: 100.0,
        );

        $this->assertNull($event->value);
    }
}
