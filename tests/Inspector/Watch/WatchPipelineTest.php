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
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Action\LogAction;
use Reli\Inspector\Watch\Trigger\MemoryUsageTrigger;
use Reli\Inspector\Watch\Trigger\MemoryPeakTrigger;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Integration test for the watch pipeline:
 * triggers → cooldown → action execution with event merging.
 */
class WatchPipelineTest extends TestCase
{
    public function testSingleTriggerFiresAndExecutesAction(): void
    {
        $trigger = new MemoryUsageTrigger(100);
        $stream = fopen('php://memory', 'rw');
        $this->assertNotFalse($stream);
        $action = new LogAction($stream);

        $context = new WatchContext(
            pid: 42,
            heap_stats: new HeapStats(200, 200, 200, 0),
            call_trace: null,
            timestamp: 1000.0,
            previous: null,
        );

        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);

        $action->execute($event, new ProcessSpecifier(42), $context);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        $this->assertStringContainsString('memory-usage', $output);
        $this->assertStringContainsString('PID=42', $output);
    }

    public function testCooldownPreventsRefire(): void
    {
        $trigger = new MemoryUsageTrigger(100);
        $cooldown = new CooldownManager(
            60.0,
            2.0,
            3600.0,
            100,
            0,
        );

        $ctx1 = new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(200, 200, 200, 0),
            call_trace: null,
            timestamp: 1000.0,
            previous: null,
        );

        $event = $trigger->evaluate($ctx1);
        $this->assertNotNull($event);
        $this->assertTrue($cooldown->canFire('memory-usage', 1000.0));
        $cooldown->recordFire('memory-usage', 1000.0);

        // 30s later — still in cooldown
        $this->assertFalse(
            $cooldown->canFire('memory-usage', 1030.0),
        );

        // 60s later — can fire again
        $this->assertTrue(
            $cooldown->canFire('memory-usage', 1060.0),
        );
    }

    public function testMultipleTriggersFireAndMerge(): void
    {
        $t1 = new MemoryUsageTrigger(100);
        $t2 = new MemoryPeakTrigger();

        $prev = new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(50, 50, 50, 0),
            call_trace: null,
            timestamp: 999.0,
            previous: null,
        );
        $curr = new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(200, 200, 200, 0),
            call_trace: null,
            timestamp: 1000.0,
            previous: $prev,
        );

        $e1 = $t1->evaluate($curr);
        $e2 = $t2->evaluate($curr);
        $this->assertNotNull($e1);
        $this->assertNotNull($e2);

        // Merge events
        $merged = new TriggerEvent(
            trigger_name: $e1->trigger_name . '+' . $e2->trigger_name,
            description: $e1->description . '; ' . $e2->description,
            timestamp: $curr->timestamp,
        );

        $this->assertSame(
            'memory-usage+memory-peak-watch',
            $merged->trigger_name,
        );

        // Execute action once with merged event
        $stream = fopen('php://memory', 'rw');
        $this->assertNotFalse($stream);
        $action = new LogAction($stream);
        $action->execute(
            $merged,
            new ProcessSpecifier(1),
            $curr,
        );

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        $this->assertStringContainsString(
            'memory-usage+memory-peak-watch',
            $output,
        );
    }

    public function testDiskTrackerBlocksAction(): void
    {
        $tracker = new DiskUsageTracker(10); // 10 bytes max

        // Record a large file
        $tmp = tempnam(sys_get_temp_dir(), 'reli_test_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, str_repeat('x', 100));
        $tracker->recordFile($tmp);
        unlink($tmp);

        $this->assertFalse($tracker->canWrite());
    }

    public function testTriggerFactoryBuildsPipeline(): void
    {
        $factory = new TriggerFactory();
        $settings = new WatchSettings(
            poll_interval_ms: 1000,
            cooldown_seconds: 60,
            max_triggers: 0,
            max_triggers_per_hour: 10,
            max_dump_size_bytes: 1024 * 1024 * 1024,
            backoff_multiplier: 2.0,
            backoff_max_seconds: 3600,
            action_output_dir: '.',
            status_interval_seconds: 60,
            quiet: false,
            memory_usage_bytes: 1024,
            memory_growth_rate: null,
            memory_peak_watch: true,
            rss_usage_bytes: null,
            watch_function: null,
            trace_depth_limit: null,
            watch_var: [],
            actions: ['log'],
            action_exec_command: null,
            log_file: null,
            memory_output_format: null,
        );

        $triggers = $factory->build($settings);
        $this->assertCount(2, $triggers);

        $context = new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(2048, 2048, 2048, 0),
            call_trace: null,
            timestamp: microtime(true),
            previous: null,
        );

        // Memory limit should fire
        $event = $triggers[0]->evaluate($context);
        $this->assertNotNull($event);
        $this->assertSame('memory-usage', $event->trigger_name);

        // Memory peak should not fire (no previous)
        $event2 = $triggers[1]->evaluate($context);
        $this->assertNull($event2);
    }
}
