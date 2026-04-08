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

namespace Reli\Inspector\Output\MemoryOutput\Comparison\Formatter;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\Comparison\ClassDelta;
use Reli\Inspector\Output\MemoryOutput\Comparison\ComparisonResult;
use Reli\Inspector\Output\MemoryOutput\Comparison\FindingsDiff;
use Reli\Inspector\Output\MemoryOutput\Comparison\SummaryDelta;
use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingConfidence;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;

class TextComparisonFormatterTest extends BaseTestCase
{
    public function testFormatContainsHeader(): void
    {
        $result = $this->emptyResult();
        $formatter = new TextComparisonFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('reli-prof Memory Comparison Report', $output);
    }

    public function testFormatShowsSummaryDeltas(): void
    {
        $result = new ComparisonResult(
            baseline_meta: [],
            target_meta: [],
            summary_deltas: [
                new SummaryDelta('memory_get_usage', 1000000, 1500000, 500000, 50.0),
            ],
            type_deltas: [],
            class_changes: [],
            class_added: [],
            class_removed: [],
            findings_diff: new FindingsDiff([], [], []),
        );
        $formatter = new TextComparisonFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Summary Delta ===', $output);
        $this->assertStringContainsString('memory_get_usage()', $output);
        $this->assertStringContainsString('+50.0%', $output);
    }

    public function testFormatShowsClassChanges(): void
    {
        $result = new ComparisonResult(
            baseline_meta: [],
            target_meta: [],
            summary_deltas: [],
            type_deltas: [],
            class_changes: [
                new ClassDelta('App\\User', 100, 200, 25600, 51200, 100, 25600),
            ],
            class_added: [
                new ClassDelta('App\\NewClass', 0, 50, 0, 6400, 50, 6400),
            ],
            class_removed: [
                new ClassDelta('App\\OldClass', 30, 0, 3840, 0, -30, -3840),
            ],
            findings_diff: new FindingsDiff([], [], []),
        );
        $formatter = new TextComparisonFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Class Memory Changes ===', $output);
        $this->assertStringContainsString('App\\User', $output);
        $this->assertStringContainsString('+100', $output);
        $this->assertStringContainsString('New classes (target only)', $output);
        $this->assertStringContainsString('App\\NewClass', $output);
        $this->assertStringContainsString('Removed classes (baseline only)', $output);
        $this->assertStringContainsString('App\\OldClass', $output);
    }

    public function testFormatShowsNewFindings(): void
    {
        $result = new ComparisonResult(
            baseline_meta: [],
            target_meta: [],
            summary_deltas: [],
            type_deltas: [],
            class_changes: [],
            class_added: [],
            class_removed: [],
            findings_diff: new FindingsDiff(
                new: [
                    new Finding(
                        kind: 'dominant_class',
                        severity: FindingSeverity::High,
                        confidence: FindingConfidence::High,
                        summary: 'App\\User: 10,000 instances',
                        impact_bytes: 5000000,
                    ),
                ],
                resolved: [],
                changed: [],
            ),
        );
        $formatter = new TextComparisonFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Findings Diff ===', $output);
        $this->assertStringContainsString('New findings:', $output);
        $this->assertStringContainsString('+ [HIGH]', $output);
        $this->assertStringContainsString('dominant_class', $output);
    }

    public function testFormatShowsResolvedFindings(): void
    {
        $result = new ComparisonResult(
            baseline_meta: [],
            target_meta: [],
            summary_deltas: [],
            type_deltas: [],
            class_changes: [],
            class_added: [],
            class_removed: [],
            findings_diff: new FindingsDiff(
                new: [],
                resolved: [
                    new Finding(
                        kind: 'cycle_cluster',
                        severity: FindingSeverity::Medium,
                        confidence: FindingConfidence::High,
                        summary: 'Cycle in App\\OldHandler',
                        impact_bytes: 320000,
                    ),
                ],
                changed: [],
            ),
        );
        $formatter = new TextComparisonFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('Resolved findings:', $output);
        $this->assertStringContainsString('- [MEDIUM]', $output);
        $this->assertStringContainsString('cycle_cluster', $output);
    }

    public function testFormatShowsChangedFindings(): void
    {
        $result = new ComparisonResult(
            baseline_meta: [],
            target_meta: [],
            summary_deltas: [],
            type_deltas: [],
            class_changes: [],
            class_added: [],
            class_removed: [],
            findings_diff: new FindingsDiff(
                new: [],
                resolved: [],
                changed: [
                    [
                        'baseline' => new Finding(
                            kind: 'near_memory_limit',
                            severity: FindingSeverity::Warning,
                            confidence: FindingConfidence::High,
                            summary: '80% of limit',
                            impact_bytes: 8000000,
                        ),
                        'target' => new Finding(
                            kind: 'near_memory_limit',
                            severity: FindingSeverity::High,
                            confidence: FindingConfidence::High,
                            summary: '95% of limit',
                            impact_bytes: 2000000,
                        ),
                    ],
                ],
            ),
        );
        $formatter = new TextComparisonFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('Changed findings:', $output);
        $this->assertStringContainsString('near_memory_limit', $output);
    }

    public function testFormatShowsMeta(): void
    {
        $result = new ComparisonResult(
            baseline_meta: ['captured_at' => '2025-01-01 12:00:00'],
            target_meta: ['captured_at' => '2025-01-02 12:00:00'],
            summary_deltas: [],
            type_deltas: [],
            class_changes: [],
            class_added: [],
            class_removed: [],
            findings_diff: new FindingsDiff([], [], []),
        );
        $formatter = new TextComparisonFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('Baseline: 2025-01-01 12:00:00', $output);
        $this->assertStringContainsString('Target:   2025-01-02 12:00:00', $output);
    }

    public function testFormatHandlesEmptyResult(): void
    {
        $result = $this->emptyResult();
        $formatter = new TextComparisonFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('reli-prof Memory Comparison Report', $output);
        $this->assertStringNotContainsString('=== Summary Delta ===', $output);
        $this->assertStringNotContainsString('=== Class Memory Changes ===', $output);
        $this->assertStringNotContainsString('=== Findings Diff ===', $output);
    }

    private function emptyResult(): ComparisonResult
    {
        return new ComparisonResult(
            baseline_meta: [],
            target_meta: [],
            summary_deltas: [],
            type_deltas: [],
            class_changes: [],
            class_added: [],
            class_removed: [],
            findings_diff: new FindingsDiff([], [], []),
        );
    }
}
