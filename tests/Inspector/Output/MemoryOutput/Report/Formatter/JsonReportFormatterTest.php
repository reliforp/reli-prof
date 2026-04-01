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

class JsonReportFormatterTest extends BaseTestCase
{
    public function testFormatProducesValidJson(): void
    {
        $result = new ReportResult(['node_count' => 100], [
            new Finding(
                kind: 'overview',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'Test summary',
            ),
        ]);
        $formatter = new JsonReportFormatter();
        $json = $formatter->format($result);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('meta', $decoded);
        $this->assertArrayHasKey('findings', $decoded);
        $this->assertCount(1, $decoded['findings']);
        $this->assertSame('overview', $decoded['findings'][0]['kind']);
        $this->assertSame('info', $decoded['findings'][0]['severity']);
        $this->assertSame('high', $decoded['findings'][0]['confidence']);
    }

    public function testPrettyPrintAddsWhitespace(): void
    {
        $result = new ReportResult([], []);
        $compact = (new JsonReportFormatter(false))->format($result);
        $pretty = (new JsonReportFormatter(true))->format($result);

        $this->assertGreaterThan(strlen($compact), strlen($pretty));
        $this->assertStringContainsString("\n", $pretty);
    }

    public function testFindingFieldsOmittedWhenEmpty(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'test',
                severity: FindingSeverity::Low,
                confidence: FindingConfidence::Low,
                summary: 'Minimal',
            ),
        ]);
        $formatter = new JsonReportFormatter();
        $json = $formatter->format($result);

        $decoded = json_decode($json, true);
        $finding = $decoded['findings'][0];
        $this->assertArrayNotHasKey('hypothesis', $finding);
        $this->assertArrayNotHasKey('next_checks', $finding);
        $this->assertArrayNotHasKey('impact_bytes', $finding);
        $this->assertArrayNotHasKey('evidence_node_ids', $finding);
    }

    public function testFindingFieldsIncludedWhenPopulated(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'test',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: 'Full finding',
                facts: ['count' => 42],
                hypothesis: 'Something is wrong',
                next_checks: ['Check this'],
                impact_bytes: 1024,
                evidence_node_ids: [1, 2],
                representative_paths: ['root -> child'],
                replay_query: 'SELECT 1',
            ),
        ]);
        $formatter = new JsonReportFormatter();
        $json = $formatter->format($result);

        $decoded = json_decode($json, true);
        $finding = $decoded['findings'][0];
        $this->assertSame(['count' => 42], $finding['facts']);
        $this->assertSame('Something is wrong', $finding['hypothesis']);
        $this->assertSame(['Check this'], $finding['next_checks']);
        $this->assertSame(1024, $finding['impact_bytes']);
        $this->assertSame([1, 2], $finding['evidence_node_ids']);
        $this->assertSame(['root -> child'], $finding['representative_paths']);
        $this->assertSame('SELECT 1', $finding['replay_query']);
    }
}
