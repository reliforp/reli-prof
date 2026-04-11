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

namespace Reli\Inspector\Output\MemoryOutput\Comparison;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\MemoryAnalysisResult;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\Process\MemoryLocation;

class ComparisonGeneratorTest extends BaseTestCase
{
    private string $baseline_path;
    private string $target_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseline_path = tempnam(sys_get_temp_dir(), 'reli_cmp_base_') . '.db';
        $this->target_path = tempnam(sys_get_temp_dir(), 'reli_cmp_target_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->baseline_path);
        @unlink($this->target_path);
        parent::tearDown();
    }

    public function testCompareSummaryDeltas(): void
    {
        $this->buildDb($this->baseline_path, [
            ['zend_mm_heap_usage' => 1000000, 'zend_mm_heap_total' => 2000000],
            ['memory_get_usage' => 1000000, 'memory_get_real_usage' => 2000000],
            ['heap_memory_analyzed_percentage' => 99.0],
        ]);
        $this->buildDb($this->target_path, [
            ['zend_mm_heap_usage' => 1500000, 'zend_mm_heap_total' => 2000000],
            ['memory_get_usage' => 1500000, 'memory_get_real_usage' => 2000000],
            ['heap_memory_analyzed_percentage' => 98.0],
        ]);

        $result = $this->compare();

        $this->assertInstanceOf(ComparisonResult::class, $result);
        $this->assertNotEmpty($result->summary_deltas);

        $usage_delta = $this->findDelta($result, 'memory_get_usage');
        $this->assertNotNull($usage_delta);
        $this->assertEquals(1000000, $usage_delta->baseline);
        $this->assertEquals(1500000, $usage_delta->target);
        $this->assertEquals(500000, $usage_delta->delta);
        $this->assertEquals(50.0, $usage_delta->delta_percent);
    }

    public function testCompareClassChanges(): void
    {
        $baseline_locations = [];
        for ($i = 0; $i < 100; $i++) {
            $baseline_locations[] = new ZendObjectMemoryLocation(
                0x1000 + $i * 0x100,
                256,
                1,
                7,
                'App\\User'
            );
        }

        $target_locations = [];
        for ($i = 0; $i < 200; $i++) {
            $target_locations[] = new ZendObjectMemoryLocation(
                0x1000 + $i * 0x100,
                256,
                1,
                7,
                'App\\User'
            );
        }
        // New class only in target
        for ($i = 0; $i < 50; $i++) {
            $target_locations[] = new ZendObjectMemoryLocation(
                0x50000 + $i * 0x100,
                128,
                1,
                7,
                'App\\NewClass'
            );
        }

        $this->buildDbWithLocations($this->baseline_path, $baseline_locations);
        $this->buildDbWithLocations($this->target_path, $target_locations);

        $result = $this->compare();

        // App\User should be in class_changes (count doubled)
        $user_change = null;
        foreach ($result->class_changes as $cd) {
            if ($cd->class_name === 'App\\User') {
                $user_change = $cd;
                break;
            }
        }
        $this->assertNotNull($user_change);
        $this->assertSame(100, $user_change->baseline_count);
        $this->assertSame(200, $user_change->target_count);
        $this->assertSame(100, $user_change->count_delta);
        $this->assertGreaterThan(0, $user_change->memory_delta);

        // App\NewClass should be in class_added
        $new_class = null;
        foreach ($result->class_added as $cd) {
            if ($cd->class_name === 'App\\NewClass') {
                $new_class = $cd;
                break;
            }
        }
        $this->assertNotNull($new_class);
        $this->assertSame(0, $new_class->baseline_count);
        $this->assertSame(50, $new_class->target_count);
    }

    public function testCompareClassRemoved(): void
    {
        $baseline_locations = [];
        for ($i = 0; $i < 50; $i++) {
            $baseline_locations[] = new ZendObjectMemoryLocation(
                0x1000 + $i * 0x100,
                256,
                1,
                7,
                'App\\OldClass'
            );
        }

        $this->buildDbWithLocations($this->baseline_path, $baseline_locations);
        $this->buildDbWithLocations($this->target_path, []);

        $result = $this->compare();

        $old_class = null;
        foreach ($result->class_removed as $cd) {
            if ($cd->class_name === 'App\\OldClass') {
                $old_class = $cd;
                break;
            }
        }
        $this->assertNotNull($old_class);
        $this->assertSame(50, $old_class->baseline_count);
        $this->assertSame(0, $old_class->target_count);
    }

    public function testThresholdFiltersSmallChanges(): void
    {
        $this->buildDb($this->baseline_path, [
            ['memory_get_usage' => 1000000, 'zend_mm_heap_usage' => 1000000],
            ['heap_memory_analyzed_percentage' => 99.0],
        ]);
        $this->buildDb($this->target_path, [
            ['memory_get_usage' => 1005000, 'zend_mm_heap_usage' => 1005000],
            ['heap_memory_analyzed_percentage' => 99.0],
        ]);

        $generator = new ComparisonGenerator();
        $result = $generator->compare(
            $this->openDb($this->baseline_path),
            $this->openDb($this->target_path),
            threshold_percent: 1.0,
        );

        // 0.5% change should be filtered
        $usage_delta = $this->findDelta($result, 'memory_get_usage');
        $this->assertNull($usage_delta);
    }

    public function testFindingsDiffDetectsNewAndResolved(): void
    {
        // Baseline: dominant class with BigClass
        $baseline_locations = [];
        for ($i = 0; $i < 200; $i++) {
            $baseline_locations[] = new ZendObjectMemoryLocation(
                0x1000 + $i * 0x100,
                256,
                1,
                7,
                'App\\BigClass'
            );
        }
        $baseline_locations[] = new ZendObjectMemoryLocation(0x90000, 64, 1, 7, 'App\\Small');

        // Target: no dominant class
        $target_locations = [];
        for ($i = 0; $i < 50; $i++) {
            $target_locations[] = new ZendObjectMemoryLocation(
                0x1000 + $i * 0x100,
                256,
                1,
                7,
                'App\\BigClass'
            );
            $target_locations[] = new ZendObjectMemoryLocation(
                0x50000 + $i * 0x100,
                256,
                1,
                7,
                'App\\AnotherClass'
            );
        }

        $this->buildDbWithLocations($this->baseline_path, $baseline_locations);
        $this->buildDbWithLocations($this->target_path, $target_locations);

        $result = $this->compare();

        // The dominant_class finding for BigClass should be resolved
        $resolved_kinds = array_map(
            fn($f) => $f->kind,
            $result->findings_diff->resolved
        );
        $this->assertContains('dominant_class', $resolved_kinds);
    }

    public function testComparisonResultToArray(): void
    {
        $this->buildDb($this->baseline_path, [
            ['memory_get_usage' => 100000, 'zend_mm_heap_usage' => 100000],
            ['heap_memory_analyzed_percentage' => 99.0],
        ]);
        $this->buildDb($this->target_path, [
            ['memory_get_usage' => 200000, 'zend_mm_heap_usage' => 200000],
            ['heap_memory_analyzed_percentage' => 99.0],
        ]);

        $result = $this->compare();
        $array = $result->toArray();

        $this->assertArrayHasKey('baseline', $array);
        $this->assertArrayHasKey('target', $array);
        $this->assertArrayHasKey('summary_deltas', $array);
        $this->assertArrayHasKey('class_deltas', $array);
        $this->assertArrayHasKey('findings_diff', $array);
        $this->assertIsArray($array['summary_deltas']);
        $this->assertArrayHasKey('changed', $array['class_deltas']);
        $this->assertArrayHasKey('added', $array['class_deltas']);
        $this->assertArrayHasKey('removed', $array['class_deltas']);
    }

    // ---- Helpers ----

    private function compare(): ComparisonResult
    {
        $generator = new ComparisonGenerator();
        return $generator->compare(
            $this->openDb($this->baseline_path),
            $this->openDb($this->target_path),
        );
    }

    private function openDb(string $path): \PDO
    {
        $db = new \PDO('sqlite:' . $path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    private function findDelta(ComparisonResult $result, string $metric): ?SummaryDelta
    {
        foreach ($result->summary_deltas as $d) {
            if ($d->metric === $metric) {
                return $d;
            }
        }
        return null;
    }

    /** @param array<int, array<string, mixed>> $summary */
    private function buildDb(string $path, array $summary = []): void
    {
        $context = $this->createMockContext('empty', [], []);
        $result = new MemoryAnalysisResult($summary, $context);
        $output = new PdoMemoryOutput(new SqliteDriver($path));
        $output->output($result);
    }

    /** @param list<MemoryLocation> $locations */
    private function buildDbWithLocations(string $path, array $locations): void
    {
        $holder = $this->createMockContext('holder', [], $locations);
        $top = $this->createMockContext('top', ['entry' => $holder], []);
        $result = new MemoryAnalysisResult([
            ['zend_mm_heap_total' => 10485760, 'zend_mm_heap_usage' => 8388608],
            ['heap_memory_analyzed_percentage' => 99.0],
        ], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($path));
        $output->output($result);
    }

    /**
     * @param array<string, ReferenceContext> $links
     * @param list<MemoryLocation> $locations
     */
    private function createMockContext(
        string $name,
        array $links,
        array $locations,
    ): ReferenceContext {
        $context = \Mockery::mock(ReferenceContext::class);
        $memo = null;
        $context->allows('getMemoNodeId')->andReturnUsing(
            static function () use (&$memo): ?int {
                return $memo;
            }
        );
        $context->allows('setMemoNodeId')->andReturnUsing(
            static function (?int $id) use (&$memo): void {
                $memo = $id;
            }
        );
        $context->allows('getName')->andReturns($name);
        $context->allows('getLinks')->andReturns($links);
        $context->allows('getLocations')->andReturns($locations);
        $context->allows('getContexts')->andReturns([]);
        $context->allows('releaseLinks');
        $context->allows('getLinkStrength')->andReturns(EdgeStrength::Strong);
        return $context;
    }
}
