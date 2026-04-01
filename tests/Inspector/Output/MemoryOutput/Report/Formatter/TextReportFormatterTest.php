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
        $this->assertStringContainsString('[HIGH] dominant_class', $output);
        $this->assertStringContainsString('Foo: 10,000 instances', $output);
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

        $high_pos = strpos($output, '[HIGH]');
        $low_pos = strpos($output, '[LOW]');
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
}
