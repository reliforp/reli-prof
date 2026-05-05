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

    public function testTopArraysCollapseUniformIndexSiblings(): void
    {
        // N4: when several Top Arrays rows describe what is clearly the
        // same shape — same retained size, same element_count, paths
        // differing only in `[N]` — collapse into one representative
        // line plus a "+N more" annotation. Without this, captures from
        // workloads like rw3_graphql-shape spend half a screen on
        // identical $queryResult[viewer][recentActivity][0..N] rows.
        $facts_template = [
            'table_size' => 1228800,
            'retained_size' => 6553600,
            'element_count' => 100,
        ];
        $findings = [];
        for ($i = 0; $i < 5; $i++) {
            $findings[] = new Finding(
                kind: 'large_array',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::High,
                summary: 'sibling',
                facts: $facts_template + [
                    'node_id' => 1000 + $i,
                    'owner_path' => "\$queryResult[viewer][recentActivity][{$i}]",
                ],
                impact_bytes: 6553600,
            );
        }
        $output = (new TextReportFormatter())->format(new ReportResult([], $findings));

        // First row renders normally.
        $this->assertStringContainsString('$queryResult[viewer][recentActivity][0]', $output);
        // Annotation appears on the row after the first.
        $this->assertStringContainsString('+4 more similar siblings', $output);
        $this->assertStringContainsString('indices [0..4]', $output);
        // Members are skipped — the trailing siblings shouldn't print.
        $this->assertStringNotContainsString('$queryResult[viewer][recentActivity][3]', $output);
        $this->assertStringNotContainsString('$queryResult[viewer][recentActivity][4]', $output);
    }

    public function testTopStringsCollapseUniformVaryingProperty(): void
    {
        // N4 for Top Strings — same shape as Top Arrays: rw_logger-stack
        // dumps several Carbon doc_comments with the same ~156 KB size,
        // path differing on the class-name segment.
        $facts_template = [
            'preview' => '/**\n * A simple API extension for DateT...',
        ];
        $sizes_bytes = [159907, 159870, 159860, 159855];
        $classes = ['CarbonInterface', 'CarbonImmutable', 'Carbon', 'Traits\\Date'];
        $findings = [];
        foreach ($classes as $i => $cls) {
            $findings[] = new Finding(
                kind: 'large_string',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::High,
                summary: 'doc_comment',
                facts: $facts_template + [
                    'node_id' => 2000 + $i,
                    'size' => $sizes_bytes[$i],
                    'owner_path' => "\$class_table->Carbon\\{$cls}->doc_comment",
                ],
                impact_bytes: $sizes_bytes[$i],
            );
        }
        $output = (new TextReportFormatter())->format(new ReportResult([], $findings));

        $this->assertStringContainsString('Carbon\\CarbonInterface', $output);
        // The non-numeric-key form: enumerated values, kind-labelled
        // as `property` because the varying segment is `->Class`.
        $this->assertStringContainsString('+3 more similar siblings', $output);
        $this->assertStringContainsString('varying property:', $output);
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

    public function testSharedSingletonRendersUnderObservations(): void
    {
        // N2: a shared_singleton (1 target, many refs) means CoW share
        // is working — always an observation, never a "finding to fix".
        $result = new ReportResult([], [
            new Finding(
                kind: 'shared_singleton',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'AttributeMetadata::$ignoredAttributes (4,499 refs -> 1 target) [singleton]',
                facts: [
                    'ref_count' => 4499,
                    'target_count' => 1,
                ],
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        $this->assertStringContainsString('=== Observations (no action needed) ===', $output);
        // Reader-facing label rather than the internal kind tag.
        $this->assertStringContainsString('[CoW share] AttributeMetadata', $output);
        // Must NOT appear under Minor findings, and the legacy combined
        // section header must not appear either.
        $this->assertStringNotContainsString('=== Minor findings ===', $output);
        $this->assertStringNotContainsString('=== Additional Info ===', $output);
    }

    public function testSharedFaninWithHighRatioRendersAsInterning(): void
    {
        // refs/targets = 4,500,030 / 39 ≈ 115k each — classic interning
        // (PDO HashTable keys, real corpus example). Above the 100x
        // threshold so it lands in Observations with the [interning] tag.
        $result = new ReportResult([], [
            new Finding(
                kind: 'shared_fanin',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::Medium,
                summary: 'key -> ? (4,500,030 refs -> 39 targets, 115385.4 each)',
                facts: [
                    'ref_count' => 4500030,
                    'target_count' => 39,
                ],
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        $this->assertStringContainsString('=== Observations (no action needed) ===', $output);
        $this->assertStringContainsString('[interning] key', $output);
        $this->assertStringNotContainsString('=== Minor findings ===', $output);
    }

    public function testSharedFaninWithLowRatioRendersUnderMinorFindings(): void
    {
        // refs/targets = 4391 / 686 ≈ 6.4 each — well below 100x. This
        // is a small dedup opportunity, not a working interning pattern,
        // so it lands in Minor findings with the raw kind tag.
        $result = new ReportResult([], [
            new Finding(
                kind: 'shared_fanin',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::Medium,
                summary: 'config -> Setting (4,391 refs -> 686 targets, 6.4 each)',
                facts: [
                    'ref_count' => 4391,
                    'target_count' => 686,
                ],
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        $this->assertStringContainsString('=== Minor findings ===', $output);
        $this->assertStringContainsString('[shared_fanin] config', $output);
        $this->assertStringNotContainsString('=== Observations', $output);
    }

    public function testSharedFaninWithMidRatioStaysInMinorFindings(): void
    {
        // refs/targets = 4501 / 300 ≈ 15 each — sits in the [10, 100)
        // middle band. Per the bucketing rules, mid-ratio cases stay
        // in Minor findings: a 15x ratio is borderline enough to be
        // worth a glance, not loud enough to call "everything's fine".
        $result = new ReportResult([], [
            new Finding(
                kind: 'shared_fanin',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::Medium,
                summary: 'PropertyInfoCacheEntry::$className -> ? (4,501 refs -> 300 targets, 15 each)',
                facts: [
                    'ref_count' => 4501,
                    'target_count' => 300,
                ],
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        $this->assertStringContainsString('=== Minor findings ===', $output);
        $this->assertStringContainsString('[shared_fanin] PropertyInfoCacheEntry', $output);
        $this->assertStringNotContainsString('=== Observations', $output);
    }

    public function testObservationsAndMinorFindingsCoexist(): void
    {
        // Both buckets populated — make sure both section headers print
        // and findings sort into the right one. Verifies the buckets
        // are independently emitted, not mutually exclusive.
        $result = new ReportResult([], [
            new Finding(
                kind: 'shared_singleton',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'Singleton::$instance (200 refs -> 1 target)',
                facts: ['ref_count' => 200, 'target_count' => 1],
            ),
            new Finding(
                kind: 'shared_fanin',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::Medium,
                summary: 'cfg -> Setting (40 refs -> 8 targets, 5.0 each)',
                facts: ['ref_count' => 40, 'target_count' => 8],
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        $this->assertStringContainsString('=== Observations (no action needed) ===', $output);
        $this->assertStringContainsString('=== Minor findings ===', $output);
        $this->assertStringContainsString('[CoW share] Singleton', $output);
        $this->assertStringContainsString('[shared_fanin] cfg', $output);
        // Observations should come before Minor findings — section order
        // is part of the narrative ("here's what's working, here's the
        // smaller stuff"). pos comparison guarantees the order.
        $obs_pos = strpos($output, '=== Observations');
        $min_pos = strpos($output, '=== Minor findings');
        $this->assertNotFalse($obs_pos);
        $this->assertNotFalse($min_pos);
        $this->assertLessThan($min_pos, $obs_pos);
    }

    public function testFindingsForSameClassClusterIntoOneRepresentative(): void
    {
        // S12: rw3_messenger-envelopes shape — multiple detector kinds
        // converge on the same class. Without clustering, each kind
        // gets its own [tag] block and the section reads as 4 separate
        // problems. After clustering, one representative + an "Also
        // detected as" line summarising the rest.
        $result = new ReportResult([], [
            new Finding(
                kind: 'expensive_property',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: 'App\\Envelope::$messages: 50000 occurrences x 2 KB',
                facts: ['class_name' => 'App\\Envelope', 'property_name' => 'messages'],
                impact_bytes: 100_000_000,
            ),
            new Finding(
                kind: 'empty_object',
                severity: FindingSeverity::Low,
                confidence: FindingConfidence::Medium,
                summary: 'App\\Envelope: empty header object',
                facts: ['class_name' => 'App\\Envelope'],
                impact_bytes: 0,
            ),
            new Finding(
                kind: 'structural_duplicate',
                severity: FindingSeverity::Low,
                confidence: FindingConfidence::Medium,
                summary: 'App\\Envelope: 50000 instances share structure',
                facts: ['class_name' => 'App\\Envelope'],
                impact_bytes: 0,
            ),
            new Finding(
                kind: 'ownership_pattern',
                severity: FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: 'App\\Envelope (50000 instances) owned 1:1 by Bus',
                facts: ['owned_class' => 'App\\Envelope'],
                impact_bytes: 0,
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        $this->assertStringContainsString('=== Findings ===', $output);
        // The High-severity expensive_property is the representative —
        // its summary appears at the head of its block.
        $this->assertStringContainsString(
            'expensive_property: App\\Envelope::$messages',
            $output,
        );
        // The other three kinds are summarised on a single line.
        $this->assertStringContainsString(
            'Also detected as: empty_object, structural_duplicate, ownership_pattern',
            $output,
        );
        // The suppressed kinds must NOT appear as their own [tag] blocks
        // — that would defeat the whole point of clustering.
        $this->assertStringNotContainsString('empty_object: App', $output);
        $this->assertStringNotContainsString('structural_duplicate: App', $output);
        $this->assertStringNotContainsString('ownership_pattern: App', $output);
    }

    public function testFindingsWithDifferentTargetsDoNotCluster(): void
    {
        // Two separate phenomena — should produce two top-level
        // representatives, not one cluster. Verifies the clusterer
        // doesn't over-aggregate.
        $result = new ReportResult([], [
            new Finding(
                kind: 'expensive_property',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: 'App\\Foo::$bar',
                facts: ['class_name' => 'App\\Foo'],
                impact_bytes: 1024,
            ),
            new Finding(
                kind: 'expensive_property',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: 'App\\Baz::$qux',
                facts: ['class_name' => 'App\\Baz'],
                impact_bytes: 512,
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        $this->assertStringContainsString('App\\Foo::$bar', $output);
        $this->assertStringContainsString('App\\Baz::$qux', $output);
        $this->assertStringNotContainsString('Also detected as:', $output);
    }

    public function testBottleneckPathRendersVerticalDescentForDeepPath(): void
    {
        // T4: rw3_messenger-envelopes shape — 4 user-visible segments.
        // The text output must NOT echo `summary` (which sticks the
        // 186 MB spine total next to the leaf path and reads as if the
        // leaf weighs that). Instead emit a per-step descent block from
        // facts.path[] + facts.sizes[], one line per step with the
        // size at that depth.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: "\$processedEnvelopes[0]->stamps['RetryStamp'] (186.11 MB)",
                facts: [
                    'path' => [
                        'global_variables', 'array_elements', 'processedEnvelopes',
                        'array_elements', '0',
                        'object_properties', 'stamps',
                        'array_elements', 'RetryStamp', 'value',
                    ],
                    'path_types' => [
                        '', '', '',
                        '', 'ArrayElementContext',
                        '', '',
                        '', 'ArrayElementContext', '',
                    ],
                    'sizes' => [
                        195_159_654, 195_159_654, 195_159_654,
                        194_887_741, 194_887_741,
                        4_403, 4_403,
                        2_150, 2_150, 2_150,
                    ],
                    'summary_path' => "\$processedEnvelopes[0]->stamps['RetryStamp']",
                    'depth' => 10,
                ],
                impact_bytes: 195_159_654,
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        // Heading line — descent block follows on subsequent lines.
        $this->assertStringContainsString("\n    bottleneck_path:\n", $output);
        // The path-total parenthetical that misled readers in the
        // legacy summary line must NOT appear.
        $this->assertStringNotContainsString('(186.11 MB)', $output);
        // Each user-visible step appears on its own line with its
        // own size. Structural intermediaries (`array_elements`,
        // `object_properties`) and the synthesised array key are
        // elided by PathFormatter, so the four segments are
        // `$processedEnvelopes`, `[0]`, `->stamps`, `['RetryStamp']`.
        $this->assertStringContainsString('$processedEnvelopes', $output);
        $this->assertStringContainsString('└ [0]', $output);
        // Property delta strips the leading `->`: the tree indent
        // already conveys descent, so the arrow is redundant.
        $this->assertStringContainsString('└ stamps', $output);
        $this->assertStringNotContainsString('└ ->stamps', $output);
        $this->assertStringContainsString("└ ['RetryStamp']", $output);
        // Per-step sizes — each step's own retained, not the spine total.
        // 195_159_654 B / 1048576 ≈ 186.12 MB; 194_887_741 ≈ 185.86 MB.
        $this->assertStringContainsString('186.12 MB', $output);
        $this->assertStringContainsString('185.86 MB', $output);
        $this->assertStringContainsString('4.30 KB', $output);
        $this->assertStringContainsString('2.10 KB', $output);
    }

    public function testBottleneckPathRendersInlineDescentForShallowPath(): void
    {
        // 3 user-visible segments — short enough that a vertical block
        // would be oversized. Render inline with `→` separators.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: '$bus->listeners[\'request.completed\'] (11.37 MB)',
                facts: [
                    'path' => [
                        'global_variables', 'array_elements', 'bus',
                        'object_properties', 'listeners',
                        'array_elements', 'request.completed', 'value',
                    ],
                    'path_types' => [
                        '', '', '',
                        '', '',
                        '', 'ArrayElementContext', '',
                    ],
                    'sizes' => [
                        11_923_456, 11_923_456, 11_923_456,
                        7_490_125, 7_490_125,
                        2724, 2724, 2724,
                    ],
                    'summary_path' => "\$bus->listeners['request.completed']",
                    'depth' => 8,
                ],
                impact_bytes: 11_923_456,
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        // Inline form on the kind line — single line, arrows between
        // steps. Every step renders its own size, including the first:
        // `sizes[0]` (carried by `[HIGH] X impacted`) is the path's
        // true root retained, not the first user-visible step's, and
        // the two diverge whenever `global_variables` etc. hold
        // non-trivial state outside the descent.
        $this->assertStringContainsString(
            '    bottleneck_path: $bus (11.37 MB) → listeners (7.14 MB)',
            $output,
        );
        $this->assertStringContainsString('→', $output);
        // No vertical heading — distinguish from the deep-path mode.
        $this->assertStringNotContainsString("    bottleneck_path:\n", $output);
    }

    public function testInlineDescentShowsFirstStepSizeWhenGapToImpact(): void
    {
        // Locks in the inline-mode invariant from the verification
        // pass on the impl15 corpus: when sizes[0] (path-total
        // retained, often `global_variables`) is bigger than the
        // first user-visible step's retained — because other state
        // lives in global_variables outside the chosen descent —
        // the first step must STILL render its own size, otherwise
        // the gap becomes unobservable in inline mode. Mirrors
        // `rw3_reflection-heavy`: 7.21 MB impact vs 4.63 MB at
        // `$propertyInfoCache`.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: "\$propertyInfoCache['attr_key']->accessors (4.63 MB)",
                facts: [
                    'path' => [
                        'global_variables', 'array_elements', 'propertyInfoCache',
                        'array_elements', 'attr_key',
                        'object_properties', 'accessors', 'value',
                    ],
                    'path_types' => [
                        '', '', '',
                        '', 'ArrayElementContext',
                        '', '', '',
                    ],
                    // sizes[0] = 7.21 MB (global_variables total —
                    // includes other globals besides the cache).
                    // sizes[2] = 4.63 MB (the cache itself, first
                    // user-visible step). The 2.58 MB gap must be
                    // observable from the rendered output.
                    'sizes' => [
                        7_558_184, 7_558_184, 4_858_787,
                        4_858_787, 4_858_787,
                        497, 497, 497,
                    ],
                    'summary_path' =>
                        "\$propertyInfoCache['attr_key']->accessors",
                    'depth' => 8,
                ],
                impact_bytes: 7_558_184,
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        // [HIGH] line carries sizes[0] = 7.21 MB.
        $this->assertStringContainsString('[HIGH] 7.21 MB impacted', $output);
        // First inline step explicitly carries its own retained
        // (4.63 MB), distinct from impact_bytes — without this the
        // 2.58 MB held by other globals would be invisible.
        $this->assertStringContainsString(
            '$propertyInfoCache (4.63 MB)',
            $output,
        );
        // All three user-visible steps render with their own size.
        $this->assertStringContainsString("['attr_key']", $output);
        $this->assertStringContainsString('accessors', $output);
        // 497 B = the leaf size — last step carries it inline.
        $this->assertStringContainsString('497 B', $output);
    }

    public function testBottleneckPathTruncatesLongSegment(): void
    {
        // A property name longer than 40 chars must truncate so the
        // descent block doesn't push past terminal width. Mirrors the
        // Top Classes name-column treatment.
        $long = str_repeat('A', 60) . 'TAIL';
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: "\$x->{$long}->y (1.00 MB)",
                facts: [
                    'path' => [
                        'global_variables', 'array_elements', 'x',
                        'object_properties', $long,
                        'object_properties', 'y',
                        'value',
                    ],
                    'path_types' => ['', '', '', '', '', '', '', ''],
                    'sizes' => [
                        1_048_576, 1_048_576, 1_048_576,
                        524_288, 524_288,
                        262_144, 262_144,
                        262_144,
                    ],
                    'summary_path' => "\$x->{$long}->y",
                    'depth' => 8,
                ],
                impact_bytes: 1_048_576,
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        // 60-A property truncates: 3 chars `...` + last 37 chars of
        // the segment. The trailing `TAIL` plus a partial run of `A`s
        // must remain so the discriminating tail is visible.
        $this->assertStringContainsString('...', $output);
        $this->assertStringContainsString('TAIL', $output);
        // Full 60-char prefix must NOT appear verbatim.
        $this->assertStringNotContainsString(str_repeat('A', 60), $output);
    }

    public function testBottleneckPathFallsBackToSummaryWhenAllStructural(): void
    {
        // Pathological path with only structural intermediaries —
        // PathFormatter has nothing to anchor on. Don't emit a descent
        // block (it would be structural noise); fall back to the
        // legacy `kind: summary` line.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: 'all-structural fallback',
                facts: [
                    'path' => [
                        'global_variables', 'array_elements',
                        'object_properties', 'value',
                    ],
                    'path_types' => ['', '', '', ''],
                    'sizes' => [10_000_000, 1_000_000, 500_000, 100_000],
                ],
                impact_bytes: 10_000_000,
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        $this->assertStringContainsString(
            'bottleneck_path: all-structural fallback',
            $output,
        );
        $this->assertStringNotContainsString('└ ', $output);
    }

    public function testBottleneckPathFallsBackToSummaryWhenFactsMissing(): void
    {
        // Older fixtures (pre-T2.2) don't carry path / path_types /
        // sizes. The descent builder must defensively return [] so
        // legacy reports keep rendering as before.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: 'legacy form (no facts.path)',
                facts: ['some' => 'unrelated'],
                impact_bytes: 1024,
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        $this->assertStringContainsString(
            'bottleneck_path: legacy form (no facts.path)',
            $output,
        );
        $this->assertStringNotContainsString('└ ', $output);
    }

    public function testBottleneckPathDescentStopsAtSummaryPathWhenLeafDiverges(): void
    {
        // When summary_path != leaf_path (the existing
        // selectSummaryPath truncation case), the descent must stop
        // at summary_path's depth. The leaf example beyond is rendered
        // separately by the existing `Leaf example: …` hypothesis
        // line. Use a deeper path (4 user-visible segments in
        // summary_path) so we land in vertical mode where the cut-off
        // is unambiguous to assert.
        $result = new ReportResult([], [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: '$rows[0]->frames->inner (54.50 KB)',
                facts: [
                    'path' => [
                        'global_variables', 'array_elements', 'rows',
                        'array_elements', '0',
                        'object_properties', 'frames',
                        'object_properties', 'inner',
                        'array_elements', 'leaf_inner', 'value',
                    ],
                    'path_types' => [
                        '', '', '',
                        '', 'ArrayElementContext',
                        '', '',
                        '', '',
                        '', 'ArrayElementContext', '',
                    ],
                    'sizes' => [
                        100_000_000, 100_000_000, 100_000_000,
                        99_500_000, 99_500_000,
                        55_808, 55_808,
                        30_000, 30_000,
                        100, 100, 100,
                    ],
                    'summary_path' => '$rows[0]->frames->inner',
                    'leaf_path' => "\$rows[0]->frames->inner['leaf_inner']",
                    'depth' => 12,
                ],
                hypothesis: "Heaviest retained branch — primary chain\n"
                    . "Leaf example: \$rows[0]->frames->inner['leaf_inner']",
                impact_bytes: 100_000_000,
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        // Descent runs through `->inner`; the deeper `['leaf_inner']`
        // segment must NOT appear in the descent block. (Property
        // delta strips the leading `->` — tree indent conveys descent.)
        $this->assertStringContainsString('└ inner', $output);
        $this->assertStringNotContainsString("└ ['leaf_inner']", $output);
        // Leaf example survives on the hypothesis line.
        $this->assertStringContainsString(
            "Leaf example: \$rows[0]->frames->inner['leaf_inner']",
            $output,
        );
    }

    public function testNonSharedInfoFindingFallsIntoMinorFindings(): void
    {
        // Generic Info-severity finding kinds (anything outside the
        // shared_* family) are not "everything's fine" signals, so they
        // belong in Minor findings rather than Observations.
        $result = new ReportResult([], [
            new Finding(
                kind: 'retained_approximate',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::Medium,
                summary: 'approximate retained size note',
                facts: [],
            ),
        ]);
        $output = (new TextReportFormatter())->format($result);

        $this->assertStringContainsString('=== Minor findings ===', $output);
        $this->assertStringContainsString('[retained_approximate]', $output);
        $this->assertStringNotContainsString('=== Observations', $output);
    }

    public function testBinDetailSectionsAreHiddenByDefault(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'bin_histogram_overview',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'ZendMM live: 1.00 MB in 1,000 small slots',
                facts: [],
            ),
            new Finding(
                kind: 'bin_histogram_entry',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'bin[32 B]: 100 live (3.20 KB)',
                facts: [
                    'bin_num' => 1,
                    'bin_size' => 32,
                    'count' => 100,
                    'total_bytes' => 3200,
                ],
            ),
            new Finding(
                kind: 'bin_periodic_group',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'periodic group',
                facts: [
                    'bin_size' => 32,
                    'count' => 50,
                    'stride' => 32,
                    'sample_addr' => 0x7f0000000000,
                    'fingerprint' => 'abcd',
                    'inferred_shape' => 'Bucket(IS_STRING)',
                    'inferred_confidence' => 'high',
                    'reachability' => 'orphan',
                ],
            ),
            new Finding(
                kind: 'bin_shape_count',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'shape count',
                facts: [
                    'bin_num' => 1,
                    'shape' => 'Bucket(IS_STRING)',
                    'confidence' => 'high',
                    'count' => 50,
                    'percentage' => 76.0,
                    'orphan_count' => 30,
                    'reachable_count' => 20,
                ],
            ),
        ]);

        $output = (new TextReportFormatter())->format($result);

        // Histogram itself stays on (the user keeps the compact summary).
        $this->assertStringContainsString('=== ZendMM Bin Histogram ===', $output);
        // Verbose per-bin diagnostic tables are gated off by default.
        $this->assertStringNotContainsString('=== ZendMM Periodic Groups ===', $output);
        $this->assertStringNotContainsString('=== Per-bin Shape Detection ===', $output);
    }

    public function testBinDetailSectionsAppearWhenEnabled(): void
    {
        $result = new ReportResult([], [
            new Finding(
                kind: 'bin_periodic_group',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'periodic group',
                facts: [
                    'bin_size' => 32,
                    'count' => 50,
                    'stride' => 32,
                    'sample_addr' => 0x7f0000000000,
                    'fingerprint' => 'abcd',
                    'inferred_shape' => 'Bucket(IS_STRING)',
                    'inferred_confidence' => 'high',
                    'reachability' => 'orphan',
                ],
            ),
            new Finding(
                kind: 'bin_shape_count',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'shape count',
                facts: [
                    'bin_num' => 1,
                    'shape' => 'Bucket(IS_STRING)',
                    'confidence' => 'high',
                    'count' => 50,
                    'percentage' => 76.0,
                    'orphan_count' => 30,
                    'reachable_count' => 20,
                ],
            ),
        ]);

        $output = (new TextReportFormatter(bin_detail: true))->format($result);

        $this->assertStringContainsString('=== ZendMM Periodic Groups ===', $output);
        $this->assertStringContainsString('=== Per-bin Shape Detection ===', $output);
    }
}
