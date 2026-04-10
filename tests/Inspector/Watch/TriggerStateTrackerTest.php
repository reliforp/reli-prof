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

class TriggerStateTrackerTest extends TestCase
{
    public function testFirstActiveIsEnter(): void
    {
        $tracker = new TriggerStateTracker();
        $this->assertSame(TriggerPhase::ENTER, $tracker->update('t1', true));
    }

    public function testFirstInactiveIsNull(): void
    {
        $tracker = new TriggerStateTracker();
        $this->assertNull($tracker->update('t1', false));
    }

    public function testActiveToActiveIsActive(): void
    {
        $tracker = new TriggerStateTracker();
        $tracker->update('t1', true); // ENTER
        $this->assertSame(TriggerPhase::ACTIVE, $tracker->update('t1', true));
    }

    public function testActiveToInactiveIsExit(): void
    {
        $tracker = new TriggerStateTracker();
        $tracker->update('t1', true); // ENTER
        $this->assertSame(TriggerPhase::EXIT, $tracker->update('t1', false));
    }

    public function testInactiveToInactiveIsNull(): void
    {
        $tracker = new TriggerStateTracker();
        $tracker->update('t1', false);
        $this->assertNull($tracker->update('t1', false));
    }

    public function testFullLifecycle(): void
    {
        $tracker = new TriggerStateTracker();

        // inactive → active = ENTER
        $this->assertSame(TriggerPhase::ENTER, $tracker->update('t1', true));
        // active → active = ACTIVE
        $this->assertSame(TriggerPhase::ACTIVE, $tracker->update('t1', true));
        $this->assertSame(TriggerPhase::ACTIVE, $tracker->update('t1', true));
        // active → inactive = EXIT
        $this->assertSame(TriggerPhase::EXIT, $tracker->update('t1', false));
        // inactive → inactive = null
        $this->assertNull($tracker->update('t1', false));
        // inactive → active = ENTER again
        $this->assertSame(TriggerPhase::ENTER, $tracker->update('t1', true));
    }

    public function testIndependentTriggers(): void
    {
        $tracker = new TriggerStateTracker();

        $this->assertSame(TriggerPhase::ENTER, $tracker->update('t1', true));
        $this->assertNull($tracker->update('t2', false));
        $this->assertSame(TriggerPhase::ENTER, $tracker->update('t2', true));
        $this->assertSame(TriggerPhase::ACTIVE, $tracker->update('t1', true));
    }

    public function testIsActive(): void
    {
        $tracker = new TriggerStateTracker();

        $this->assertFalse($tracker->isActive('t1'));
        $tracker->update('t1', true);
        $this->assertTrue($tracker->isActive('t1'));
        $tracker->update('t1', false);
        $this->assertFalse($tracker->isActive('t1'));
    }

    public function testHasAnyActive(): void
    {
        $tracker = new TriggerStateTracker();

        $this->assertFalse($tracker->hasAnyActive());
        $tracker->update('t1', true);
        $this->assertTrue($tracker->hasAnyActive());
        $tracker->update('t1', false);
        $this->assertFalse($tracker->hasAnyActive());

        $tracker->update('t1', true);
        $tracker->update('t2', true);
        $this->assertTrue($tracker->hasAnyActive());
        $tracker->update('t1', false);
        $this->assertTrue($tracker->hasAnyActive()); // t2 still active
        $tracker->update('t2', false);
        $this->assertFalse($tracker->hasAnyActive());
    }
}
