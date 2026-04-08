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

namespace Reli\Inspector\Output\MemoryOutput\Report\Formatter;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingConfidence;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\ReportResult;

class TextReportFormatterTest extends BaseTestCase
{
    public function testFormatContainsHeader(): void
    {
        $result = new ReportResult([], []);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('reli-prof Memory Analysis Report', $output);
    }

    public function testFormatShowsOverview(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'overview',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'Heap: 42.00 MB (99.5% analyzed)',
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Overview ===', $output);
        $this->assertStringContainsString('Heap: 42.00 MB (99.5% analyzed)', $output);
    }

    public function testFormatShowsActionableFindings(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'dominant_class',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: 'Foo: 10,000 instances, 95% of object memory',
                hypothesis: 'Unbounded accumulation',
                next_checks: ['Check loop', 'Check container'],
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Findings ===', $output);
        $this->assertStringContainsString('[HIGH', $output);
        $this->assertStringContainsString('dominant_class: Foo: 10,000 instances', $output);
        $this->assertStringContainsString('Unbounded accumulation', $output);
        $this->assertStringContainsString('Next: Check loop; Check container', $output);
    }

    public function testFormatSortsFindingsBySeverity(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'low_thing',
                severity: FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: 'Low severity item',
            ),
            new Finding(
                kind: 'high_thing',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: 'High severity item',
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $high_pos = strpos($output, '[HIGH');
        $low_pos = strpos($output, '[LOW');
        $this->assertNotFalse($high_pos);
        $this->assertNotFalse($low_pos);
        $this->assertLessThan($low_pos, $high_pos);
    }

    public function testFormatShowsBlameAllocation(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'root_blame',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'call_frames: 10.00 MB (80%)',
                facts: [
                    'root_name' => 'call_frames',
                    'exclusive_bytes' => 8388608,
                    'shared_bytes' => 2097152,
                    'total_bytes' => 10485760,
                    'percentage' => 80.0,
                ],
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Root Blame Allocation ===', $output);
        $this->assertStringContainsString('call_frames', $output);
    }

    public function testFormatHandlesEmptyFindings(): void
    {
        $result = new ReportResult([], []);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('reli-prof Memory Analysis Report', $output);
        $this->assertStringNotContainsString('=== Findings ===', $output);
    }

    public function testFormatShowsTypeBreakdownTable(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'type_ranking',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'ZendObject: 20.00 MB (12,000)',
                facts: [
                    'type' => 'ZendObjectMemoryLocation',
                    'count' => 12000,
                    'memory_usage' => 20971520,
                    'percentage' => 83.3,
                ],
                impact_bytes: 20971520,
            ),
            new Finding(
                kind: 'type_ranking',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'ZendString: 2.00 MB (3,500)',
                facts: [
                    'type' => 'ZendStringMemoryLocation',
                    'count' => 3500,
                    'memory_usage' => 2097152,
                    'percentage' => 8.3,
                ],
                impact_bytes: 2097152,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Type Breakdown ===', $output);
        $this->assertStringContainsString('ZendObject', $output);
        $this->assertStringContainsString('ZendString', $output);
        $this->assertStringContainsString('12,000', $output);
        $this->assertStringContainsString('83.3%', $output);
        // Should NOT appear in Additional Info
        $this->assertStringNotContainsString('=== Additional Info ===', $output);
    }

    public function testFormatShowsTopClassesTable(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'class_ranking',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: '#1 App\\Entity\\User: 4,000 instances x 1.60 KB (6.40 MB)',
                facts: [
                    'rank' => 1,
                    'class_name' => 'App\\Entity\\User',
                    'count' => 4000,
                    'memory_bytes' => 6553600,
                    'percentage_of_object_memory' => 32.0,
                    'avg_size' => 1638,
                ],
                impact_bytes: 6553600,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Top Classes by Memory ===', $output);
        $this->assertStringContainsString('App\\Entity\\User', $output);
        $this->assertStringContainsString('4,000', $output);
        $this->assertStringContainsString('32.0%', $output);
    }

    public function testFormatShowsTopArraysTable(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'large_array',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::High,
                summary: '6.40 MB array, 4,000 elements — $container->cache',
                facts: [
                    'node_id' => 42,
                    'table_size' => 1228800,
                    'retained_size' => 6553600,
                    'element_count' => 4000,
                    'owner_path' => '$container->cache',
                ],
                impact_bytes: 6553600,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Top Arrays ===', $output);
        $this->assertStringContainsString('$container->cache', $output);
        $this->assertStringContainsString('4,000', $output);
        // Should NOT appear in Findings section
        $this->assertStringNotContainsString('=== Findings ===', $output);
    }

    public function testFormatShowsTopStringsTable(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'large_string',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::High,
                summary: '512.00 KB — App\\Response->$body: {"data":[{"id":1',
                facts: [
                    'node_id' => 99,
                    'size' => 524288,
                    'owner_path' => 'App\\Response->$body',
                    'preview' => '{"data":[{"id":1,"name":"test"}]}',
                ],
                impact_bytes: 524288,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Top Strings ===', $output);
        $this->assertStringContainsString('App\\Response->$body', $output);
        $this->assertStringContainsString('{"data"', $output);
        // Should NOT appear in Findings section
        $this->assertStringNotContainsString('=== Findings ===', $output);
    }

    public function testFormatShowsSparseArrayInTopArrays(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'sparse_array',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: '32.00 KB table, 10/1,024 slots used (1.0%)',
                facts: [
                    'node_id' => 55,
                    'table_size' => 32768,
                    'element_count' => 10,
                    'capacity' => 1024,
                    'utilization_pct' => 1.0,
                ],
                impact_bytes: 32768,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Top Arrays ===', $output);
        $this->assertStringContainsString('[sparse]', $output);
    }

    public function testFormatTruncatesLongClassName(): void
    {
        $long_name = 'App\\Very\\Long\\Namespace\\That\\Goes\\On\\And\\On\\ClassName';
        $result = new ReportResult([], [
            new Finding(
                kind: 'class_ranking',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: "#1 {$long_name}: 100 instances",
                facts: [
                    'rank' => 1,
                    'class_name' => $long_name,
                    'count' => 100,
                    'memory_bytes' => 10000,
                    'percentage_of_object_memory' => 50.0,
                    'avg_size' => 100,
                ],
                impact_bytes: 10000,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        // Should contain truncated name with ... prefix
        $this->assertStringContainsString('...', $output);
        $this->assertStringContainsString('ClassName', $output);
    }
}
