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

class CooldownManagerTest extends TestCase
{
    public function testFirstFireAlwaysAllowed(): void
    {
        $mgr = new CooldownManager(60.0, 2.0, 3600.0, 100, 0);
        $this->assertTrue($mgr->canFire('test', 1000.0));
    }

    public function testCooldownBlocksSecondFire(): void
    {
        $mgr = new CooldownManager(60.0, 2.0, 3600.0, 100, 0);
        $mgr->recordFire('test', 1000.0);

        $this->assertFalse($mgr->canFire('test', 1030.0)); // 30s < 60s
        $this->assertTrue($mgr->canFire('test', 1060.0));   // 60s == 60s
    }

    public function testExponentialBackoff(): void
    {
        $mgr = new CooldownManager(10.0, 2.0, 1000.0, 100, 0);

        // 1st fire
        $mgr->recordFire('test', 100.0);
        // cooldown = 10s, next at 110

        // 2nd fire
        $this->assertTrue($mgr->canFire('test', 110.0));
        $mgr->recordFire('test', 110.0);
        // cooldown = 10 * 2^1 = 20s, next at 130

        $this->assertFalse($mgr->canFire('test', 120.0));
        $this->assertTrue($mgr->canFire('test', 130.0));
        $mgr->recordFire('test', 130.0);
        // cooldown = 10 * 2^2 = 40s, next at 170

        $this->assertFalse($mgr->canFire('test', 160.0));
        $this->assertTrue($mgr->canFire('test', 170.0));
    }

    public function testBackoffMaxCap(): void
    {
        $mgr = new CooldownManager(10.0, 10.0, 50.0, 100, 0);

        $mgr->recordFire('test', 100.0);
        // cooldown 10
        $mgr->recordFire('test', 200.0);
        // cooldown 10 * 10^1 = 100 -> capped at 50
        $mgr->recordFire('test', 300.0);
        // cooldown still capped at 50

        $this->assertFalse($mgr->canFire('test', 340.0));
        $this->assertTrue($mgr->canFire('test', 350.0));
    }

    public function testRecordClearResetsBackoff(): void
    {
        $mgr = new CooldownManager(10.0, 2.0, 1000.0, 100, 0);

        $mgr->recordFire('test', 100.0);
        $mgr->recordFire('test', 200.0);
        // consecutive_fires=2, cooldown=20

        $mgr->recordClear('test');
        // reset: consecutive_fires=0, cooldown=10

        $this->assertTrue($mgr->canFire('test', 210.0));
        $mgr->recordFire('test', 210.0);
        // Should be back to base cooldown
        $this->assertFalse($mgr->canFire('test', 215.0));
        $this->assertTrue($mgr->canFire('test', 220.0));
    }

    public function testHourlyRateLimit(): void
    {
        $mgr = new CooldownManager(0.0, 1.0, 3600.0, 2, 0);

        $mgr->recordFire('a', 1000.0);
        $mgr->recordFire('b', 1001.0);

        // 2 fires in the hour, limit is 2
        $this->assertFalse($mgr->canFire('c', 1002.0));

        // After an hour, window slides
        $this->assertTrue($mgr->canFire('c', 4601.0));
    }

    public function testMaxTriggersLimit(): void
    {
        $mgr = new CooldownManager(0.0, 1.0, 3600.0, 100, 3);

        $mgr->recordFire('a', 100.0);
        $mgr->recordFire('a', 101.0);
        $mgr->recordFire('a', 102.0);

        $this->assertTrue($mgr->hasReachedMaxTriggers());
        $this->assertFalse($mgr->canFire('a', 103.0));
    }

    public function testIndependentTriggerCooldowns(): void
    {
        $mgr = new CooldownManager(10.0, 2.0, 3600.0, 100, 0);

        $mgr->recordFire('memory-usage', 100.0);
        $mgr->recordFire('watch-function', 100.0);

        // memory-usage in cooldown, watch-function in cooldown
        $this->assertFalse($mgr->canFire('memory-usage', 105.0));
        $this->assertFalse($mgr->canFire('watch-function', 105.0));

        // Both should be available after cooldown
        $this->assertTrue($mgr->canFire('memory-usage', 110.0));
        $this->assertTrue($mgr->canFire('watch-function', 110.0));
    }

    public function testGetSkipReason(): void
    {
        $mgr = new CooldownManager(60.0, 2.0, 3600.0, 100, 0);
        $this->assertNull($mgr->getSkipReason('test', 100.0));

        $mgr->recordFire('test', 100.0);
        $reason = $mgr->getSkipReason('test', 130.0);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('cooldown', $reason);
    }

    public function testGetTotalFires(): void
    {
        $mgr = new CooldownManager(0.0, 1.0, 3600.0, 100, 0);
        $this->assertSame(0, $mgr->getTotalFires());

        $mgr->recordFire('a', 100.0);
        $mgr->recordFire('b', 101.0);
        $this->assertSame(2, $mgr->getTotalFires());
    }
}
