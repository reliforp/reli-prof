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
use Reli\Inspector\Output\MemoryOutput\Comparison\ComparisonResult;
use Reli\Inspector\Output\MemoryOutput\Comparison\FindingsDiff;
use Reli\Inspector\Output\MemoryOutput\Comparison\SummaryDelta;

class JsonComparisonFormatterTest extends BaseTestCase
{
    public function testFormatProducesValidJson(): void
    {
        $result = new ComparisonResult(
            baseline_meta: ['node_count' => 100],
            target_meta: ['node_count' => 200],
            summary_deltas: [
                new SummaryDelta('memory_get_usage', 100000, 200000, 100000, 100.0),
            ],
            class_changes: [],
            class_added: [],
            class_removed: [],
            findings_diff: new FindingsDiff([], [], []),
        );
        $formatter = new JsonComparisonFormatter();
        $json = $formatter->format($result);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('baseline', $decoded);
        $this->assertArrayHasKey('target', $decoded);
        $this->assertArrayHasKey('summary_deltas', $decoded);
        $this->assertArrayHasKey('class_deltas', $decoded);
        $this->assertArrayHasKey('findings_diff', $decoded);
        $this->assertCount(1, $decoded['summary_deltas']);
        $this->assertSame('memory_get_usage', $decoded['summary_deltas'][0]['metric']);
    }

    public function testPrettyPrintAddsWhitespace(): void
    {
        $result = new ComparisonResult(
            baseline_meta: [],
            target_meta: [],
            summary_deltas: [],
            class_changes: [],
            class_added: [],
            class_removed: [],
            findings_diff: new FindingsDiff([], [], []),
        );
        $compact = (new JsonComparisonFormatter(false))->format($result);
        $pretty = (new JsonComparisonFormatter(true))->format($result);

        $this->assertGreaterThan(strlen($compact), strlen($pretty));
        $this->assertStringContainsString("\n", $pretty);
    }
}
