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

    public function testFormatEscapesWhitespaceInTopStringPreview(): void
    {
        // Strings whose preview contains a literal newline used to wrap
        // mid-row and break the table layout (B3). Verify the formatter
        // now escapes \n / \r / \t / \0 before truncating.
        $result = new ReportResult([], [
            new Finding(
                kind: 'large_string',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::High,
                summary: '512.00 KB — multi-line string',
                facts: [
                    'node_id' => 99,
                    'size' => 524288,
                    'owner_path' => '$multiline',
                    'preview' => "first line\nsecond line\twith tab",
                ],
                impact_bytes: 524288,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('=== Top Strings ===', $output);
        // The escape sequences appear literally; no raw newline inside the row
        $this->assertStringContainsString('first line\\nsecond line\\twith tab', $output);
        // No literal newline can appear inside the preview portion: the
        // exact preview substring must not contain one.
        $this->assertStringNotContainsString("first line\nsecond line", $output);
    }

    public function testFormatRendersBottleneckSpineDropLineWithPathComponent(): void
    {
        // T2.2: the spine line should name the path component the
        // descent landed on ("drops after $decoded[data]"), not a
        // depth integer that forces the reader to count segments in
        // facts.path manually. PathFormatter renders the prefix in
        // the same syntax as facts.summary_path so the two read
        // consistently.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: '$decoded[data][10100][profile] (171.45 MB)',
                facts: [
                    // global_variables → array_elements → 'decoded' →
                    // array_header → array_elements → 'data' → ...
                    // PathFormatter strips the structural intermediaries
                    // and renders ['$decoded', '[data]', ...].
                    'path' => [
                        'global_variables', 'array_elements', 'decoded',
                        'array_header', 'array_elements', 'data',
                        'array_header', 'array_elements', '10100', 'profile',
                        'value', 'value',
                    ],
                    'path_types' => [
                        '', '', '',
                        '', '', '',
                        '', '', 'ArrayElementContext', '',
                        '', '',
                    ],
                    'sizes' => [
                        325480905, 325479089, 325475106, 325475106, 324977898,
                        3336, 3336, 3184, 2520, 2240, 2144, 2144,
                    ],
                ],
                impact_bytes: 325480905,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        // Names the drop position as a path component, not "depth 5".
        $this->assertStringContainsString('Spine: heaviest-child mass drops after ', $output);
        $this->assertStringNotContainsString('drops at depth', $output);
        // After the drop, sizes flatline within ~10% — formatter should
        // mark the leaf as one-of-many uniform siblings.
        $this->assertStringContainsString('one of many similar-sized siblings', $output);
    }

    public function testFormatFallsBackToDepthIntegerWhenPathTypesMissing(): void
    {
        // Legacy / minimal facts (path / path_types absent — older
        // captures and unit-test fixtures that pre-date T2.2). The
        // spine line should still appear, falling back to the depth
        // integer rather than skipping the line entirely.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: '$decoded[data][10100][profile] (171.45 MB)',
                facts: [
                    'sizes' => [
                        325480905, 325479089, 325475106, 325475106, 324977898,
                        3336, 3336, 3184, 2520, 2240, 2144, 2144,
                    ],
                ],
                impact_bytes: 325480905,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('Spine: heaviest-child mass drops at depth 5', $output);
    }

    public function testFormatTruncatesLongSpineDropLabel(): void
    {
        // Very deep paths get truncated from the right so the Spine
        // line stays inside terminal width. The pre-truncation prefix
        // here is ~110 chars; the rendered label should start with
        // "..." and keep the trailing ~47 chars.
        $deep_path = [];
        $deep_types = [];
        for ($i = 0; $i < 30; $i++) {
            $deep_path[] = 'verbose_property_name_' . $i;
            $deep_types[] = '';
        }
        $sizes = array_fill(0, 31, 1_000_000);
        $sizes[10] = 1000; // sharp drop at depth 10

        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: 'long path summary',
                facts: [
                    'path' => $deep_path,
                    'path_types' => $deep_types,
                    'sizes' => $sizes,
                ],
                impact_bytes: $sizes[0],
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('drops after ...', $output);
    }

    public function testFormatExtendsSpineSliceToUserIdentifierForShallowStructuralDrop(): void
    {
        // T2.5: when the drop lands on a structural intermediary
        // (`global_variables` → `array_elements`), PathFormatter has no
        // user-named segment to anchor on and falls back to a literal
        // `global_variables -> array_elements` join. The bottleneck_path
        // summary line on the same finding speaks the user-side
        // vocabulary (`$decoded[...]`); the Spine line should match.
        // The formatter walks the slice forward past trailing
        // structural components until a user-named identifier appears.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: '$decoded[data] (171.45 MB)',
                facts: [
                    'path' => [
                        'global_variables', 'array_elements', 'decoded',
                        'array_header', 'array_elements', 'data',
                    ],
                    'path_types' => [
                        '', '', '',
                        '', '', '',
                    ],
                    // ~2× drop at depth 1 (between sizes[0] and sizes[1]).
                    // Without T2.5, the slice [0..1] is
                    // [global_variables, array_elements] — both
                    // structural — and PathFormatter renders
                    // `global_variables -> array_elements`. With T2.5,
                    // the slice extends to include `decoded` and renders
                    // `$decoded`.
                    'sizes' => [171_000_000, 84_000_000, 84_000_000, 84_000_000, 84_000_000, 84_000_000],
                ],
                impact_bytes: 171_000_000,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringContainsString('drops after $decoded', $output);
        $this->assertStringNotContainsString('global_variables -> array_elements', $output);
    }

    public function testFormatFallsBackToDepthIntegerWhenDescentEntirelyStructural(): void
    {
        // Pathological case: every component on the descent is a
        // structural intermediary. The extension loop runs off the
        // end without finding a user-named segment; the formatter
        // falls back to the depth integer rather than emit a longer
        // string of structural noise.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: 'pathological structural-only descent',
                facts: [
                    'path' => [
                        'global_variables', 'array_elements',
                        'object_properties', 'value',
                    ],
                    'path_types' => ['', '', '', ''],
                    // Drop at depth 1.
                    'sizes' => [10_000_000, 1_000_000, 500_000, 100_000],
                ],
                impact_bytes: 10_000_000,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        // No user-named identifier ever appears, so the new label
        // can't be built. The legacy "at depth N" form takes over.
        // (depth = drop_index + 1; drop_index = 0 here because the
        // first comparison sizes[1]*2 < sizes[0] satisfies on i=0.)
        $this->assertStringContainsString('drops at depth 1', $output);
        $this->assertStringNotContainsString('global_variables', $output);
    }

    public function testFormatOmitsBottleneckSpineLineForUniformDescent(): void
    {
        // When the spine stays dominant from root to leaf (no >2× drop
        // anywhere), the displayed size is roughly accurate and the spine
        // line would be noise.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: '$root->$child->$leaf (10.00 MB)',
                facts: [
                    'sizes' => [10485760, 10000000, 9500000, 9000000],
                ],
                impact_bytes: 10485760,
            ),
        ]);
        $formatter = new TextReportFormatter();
        $output = $formatter->format($result);

        $this->assertStringNotContainsString('Spine:', $output);
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
