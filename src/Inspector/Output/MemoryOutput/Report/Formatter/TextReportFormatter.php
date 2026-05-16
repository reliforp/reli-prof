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

use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\ReportResult;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\FindingClusterer;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\PathFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\UniformSiblingDetector;

final class TextReportFormatter implements ReportFormatterInterface
{
    public function __construct(
        // Gate the verbose per-bin diagnostic tables (Periodic Groups,
        // Per-bin Shape Detection) behind an opt-in. They are useful when
        // hunting a specific leak but too long for an always-on summary;
        // the bin histogram itself stays on. `bin_periodic_hotspot`
        // findings are surfaced separately in the Actionable section, so
        // the leak signal is preserved when this is off.
        private bool $bin_detail = false,
    ) {
    }

    /** @psalm-suppress InvalidOperand */
    #[\Override]
    public function format(ReportResult $result): string
    {
        $lines = [];
        $sep = str_repeat('=', 70);

        $lines[] = $sep;
        $lines[] = ' reli-prof Memory Analysis Report';
        $lines[] = $sep;
        $lines[] = '';

        // Group findings by section
        $overview = [];
        $actionable = [];
        $info = [];
        $type_rankings = [];
        $class_rankings = [];
        $top_arrays = [];
        $top_strings = [];
        $bin_histogram_overview = null;
        $bin_histogram_entries = [];
        $bin_periodic_groups = [];
        $bin_shape_counts = [];

        foreach ($result->findings as $finding) {
            if (in_array($finding->kind, ['overview', 'coverage_gap', 'call_stack'], true)) {
                $overview[] = $finding;
            } elseif ($finding->kind === 'type_ranking') {
                $type_rankings[] = $finding;
            } elseif ($finding->kind === 'class_ranking') {
                $class_rankings[] = $finding;
            } elseif ($finding->kind === 'large_array' || $finding->kind === 'sparse_array') {
                $top_arrays[] = $finding;
            } elseif ($finding->kind === 'large_string') {
                $top_strings[] = $finding;
            } elseif ($finding->kind === 'bin_histogram_overview') {
                $bin_histogram_overview = $finding;
            } elseif ($finding->kind === 'bin_histogram_entry') {
                $bin_histogram_entries[] = $finding;
            } elseif (
                $finding->kind === 'bin_shape_count'
                || $finding->kind === 'bin_shape_unclassified'
            ) {
                $bin_shape_counts[] = $finding;
            } elseif (
                $finding->kind === 'bin_periodic_group'
                || $finding->kind === 'bin_periodic_hotspot'
                || $finding->kind === 'bin_periodic_orphan'
                || $finding->kind === 'bin_periodic_reachable'
            ) {
                // Hotspots also fall into the actionable section so the
                // user gets the leak signal up top; the bin-level table
                // below repeats them with stride/fingerprint detail.
                // Reachable groups stay in the table for transparency
                // (so the user sees what reli's root walker already
                // claimed) but never go into the actionable section.
                $bin_periodic_groups[] = $finding;
                if ($finding->kind === 'bin_periodic_hotspot') {
                    $actionable[] = $finding;
                }
            } elseif (
                in_array($finding->kind, ['root_blame', 'retained_exact', 'retained_approximate'], true)
            ) {
                $info[] = $finding;
            } elseif ($finding->severity === FindingSeverity::Info) {
                $info[] = $finding;
            } else {
                $actionable[] = $finding;
            }
        }

        // Overview section
        if ($overview !== []) {
            $lines[] = '=== Overview ===';
            /** @var string|null $captured_at */
            $captured_at = $result->meta['captured_at'] ?? null;
            if (is_string($captured_at) && $captured_at !== '') {
                $lines[] = '  Captured: ' . $captured_at;
            }
            foreach ($overview as $finding) {
                if ($finding->kind === 'call_stack' && $finding->hypothesis !== '') {
                    $lines[] = '';
                    /** @var string $header */
                    $header = $finding->facts['header'] ?? 'Call Stack at capture:';
                    $lines[] = '  ' . $header;
                    foreach (explode("\n", $finding->hypothesis) as $frame) {
                        $lines[] = '    ' . $frame;
                    }
                } else {
                    $lines[] = '  ' . $finding->summary;
                }
            }
            $lines[] = '';
        }

        // Actionable findings (sorted by severity)
        if ($actionable !== []) {
            usort(
                $actionable,
                fn(Finding $a, Finding $b) =>
                    self::severityOrder($a->severity) <=> self::severityOrder($b->severity)
            );

            // S12: cluster findings that converge on the same target
            // (same class, same node) so a single phenomenon doesn't
            // dominate the section under multiple detector tags.
            // rw3_messenger-envelopes shows the worst case: 22 findings
            // for one envelope-accumulation, all sharing the Envelope
            // class. After clustering they read as one representative
            // entry plus an "Also detected as" line summarising what
            // the other detectors saw.
            $clusters = FindingClusterer::cluster($actionable);

            $lines[] = '=== Findings ===';
            $lines[] = '';

            foreach ($clusters as $cluster) {
                $finding = $cluster['representative'];
                $tag = strtoupper($finding->severity->value);
                $impact = $finding->impact_bytes > 0
                    ? SizeFormatter::format($finding->impact_bytes)
                    . ' impacted'
                    : "\u{2014}";
                $lines[] = "  [{$tag}] {$impact}";
                if ($finding->kind === 'bottleneck_path') {
                    // T4: render the descent step-by-step from
                    // facts.path[] + facts.sizes[] instead of echoing
                    // `summary`, where the path-total size sits next to
                    // the leaf path and reads as if the leaf weighs the
                    // total. The summary stays on Finding::$summary for
                    // JSON consumers; this is text-only narrative.
                    $descent_steps = self::buildBottleneckDescent($finding);
                    if ($descent_steps === []) {
                        // Facts missing or malformed (legacy fixtures,
                        // or paths shorter than one user-visible step):
                        // fall back to the legacy summary line.
                        $lines[] = "    {$finding->kind}: {$finding->summary}";
                    } elseif (count($descent_steps) >= 4) {
                        $lines[] = "    {$finding->kind}:";
                        foreach (self::renderDescentVertical($descent_steps) as $dl) {
                            $lines[] = "      {$dl}";
                        }
                    } else {
                        $lines[] = "    {$finding->kind}: "
                            . self::renderDescentInline($descent_steps);
                    }
                } else {
                    $lines[] = "    {$finding->kind}: {$finding->summary}";
                }

                if ($finding->kind === 'bottleneck_path') {
                    foreach (self::renderBottleneckSpine($finding) as $spine_line) {
                        $lines[] = "    {$spine_line}";
                    }
                }

                if ($finding->hypothesis !== '') {
                    foreach (explode("\n", $finding->hypothesis) as $h) {
                        $lines[] = "    {$h}";
                    }
                }

                if ($finding->next_checks !== []) {
                    $lines[] = '    Next: '
                        . implode('; ', $finding->next_checks);
                }

                if ($finding->evidence_node_ids !== []) {
                    $nodeHints = array_map(
                        fn(int $id) => "#$id",
                        array_slice($finding->evidence_node_ids, 0, 5),
                    );
                    $lines[] = '    Explore: rmem:explore --node='
                        . $finding->evidence_node_ids[0]
                        . '  (' . implode(', ', $nodeHints) . ')';
                }

                if ($cluster['others'] !== []) {
                    $lines[] = '    Also detected as: '
                        . FindingClusterer::summariseOtherKinds($cluster['others']);
                }

                $lines[] = '';
            }
        }

        // Type breakdown table
        if ($type_rankings !== []) {
            $lines[] = '=== Type Breakdown ===';
            $lines[] = '';
            $lines[] = sprintf('  %-30s %10s %12s %8s', 'Type', 'Count', 'Memory', '%');
            $lines[] = '  ' . str_repeat('-', 64);

            foreach ($type_rankings as $finding) {
                $facts = $finding->facts;
                /** @var string $type */
                $type = $facts['type'] ?? '?';
                $short_type = preg_replace('/^.*\\\\/', '', $type) ?? $type;
                $short_type = str_replace('MemoryLocation', '', $short_type);
                /** @var int $count */
                $count = $facts['count'] ?? 0;
                /** @var int $memory */
                $memory = $facts['memory_usage'] ?? 0;
                /** @var float $pct */
                $pct = $facts['percentage'] ?? 0;
                $lines[] = sprintf(
                    '  %-30s %10s %12s %7.1f%%',
                    $short_type,
                    number_format($count),
                    SizeFormatter::format($memory),
                    $pct,
                );
            }
            $lines[] = '';
        }

        // Top classes table
        if ($class_rankings !== []) {
            $lines[] = '=== Top Classes by Memory ===';
            $lines[] = '';
            $lines[] = sprintf('  %3s  %-40s %8s %10s %12s %8s', '#', 'Class', 'Count', 'Avg Size', 'Memory', '%');
            $lines[] = '  ' . str_repeat('-', 87);

            foreach ($class_rankings as $finding) {
                $facts = $finding->facts;
                /** @var int $rank */
                $rank = $facts['rank'] ?? 0;
                /** @var string $class_name */
                $class_name = $facts['class_name'] ?? '?';
                /** @var int $count */
                $count = $facts['count'] ?? 0;
                /** @var int $avg_size */
                $avg_size = $facts['avg_size'] ?? 0;
                /** @var int $memory */
                $memory = $facts['memory_bytes'] ?? 0;
                /** @var float $pct */
                $pct = $facts['percentage_of_object_memory'] ?? 0;
                $display_name = strlen($class_name) > 40
                    ? '...' . substr($class_name, -37)
                    : $class_name;
                $lines[] = sprintf(
                    '  %3d  %-40s %8s %10s %12s %7.1f%%',
                    $rank,
                    $display_name,
                    number_format($count),
                    SizeFormatter::format($avg_size),
                    SizeFormatter::format($memory),
                    $pct,
                );
            }
            $lines[] = '';
        }

        // ZendMM bin histogram — per-bin live-allocation distribution
        // recovered by the bin walker. Surfaces "where on the heap is
        // mass concentrated" with bin granularity (the type breakdown
        // above only sees what reli's root-traversal could claim).
        if ($bin_histogram_entries !== []) {
            $lines[] = '=== ZendMM Bin Histogram ===';
            $lines[] = '';
            if ($bin_histogram_overview !== null) {
                $lines[] = '  ' . $bin_histogram_overview->summary;
                $lines[] = '';
            }
            $lines[] = sprintf('  %3s  %8s %12s %14s', 'Bin', 'Size', 'Count', 'Memory');
            $lines[] = '  ' . str_repeat('-', 41);

            // Sort by total_bytes desc — biggest bins first.
            usort(
                $bin_histogram_entries,
                static function (Finding $a, Finding $b): int {
                    /** @var int $am */
                    $am = $a->facts['total_bytes'] ?? 0;
                    /** @var int $bm */
                    $bm = $b->facts['total_bytes'] ?? 0;
                    return $bm <=> $am;
                },
            );

            foreach ($bin_histogram_entries as $finding) {
                $facts = $finding->facts;
                /** @var int $bin_num */
                $bin_num = $facts['bin_num'] ?? 0;
                /** @var int $bin_size */
                $bin_size = $facts['bin_size'] ?? 0;
                /** @var int $count */
                $count = $facts['count'] ?? 0;
                /** @var int $memory */
                $memory = $facts['total_bytes'] ?? 0;
                $lines[] = sprintf(
                    '  %3d  %8s %12s %14s',
                    $bin_num,
                    SizeFormatter::format($bin_size),
                    number_format($count),
                    SizeFormatter::format($memory),
                );
            }
            $lines[] = '';
        }

        // Periodic groups — runs of like-shaped slots that are the
        // signature of "extension X is leaking copies of struct Y".
        if ($this->bin_detail && $bin_periodic_groups !== []) {
            $lines[] = '=== ZendMM Periodic Groups ===';
            $lines[] = '';
            $lines[] = sprintf(
                '   %8s %10s %8s  %-14s  %-9s %-32s %s',
                'BinSize',
                'Count',
                'Stride',
                'Sample addr',
                'Reach',
                'Inferred shape',
                'Fingerprint',
            );
            $lines[] = '  ' . str_repeat('-', 105);

            // Already sorted by count desc inside the bin walker but
            // re-sort here to be defensive (findings may have been
            // reshuffled by parallel passes). Hotspot rows float above
            // non-hotspot rows of the same count so the leak signal
            // lands first.
            usort(
                $bin_periodic_groups,
                static function (Finding $a, Finding $b): int {
                    $a_rank = self::periodicGroupKindRank($a->kind);
                    $b_rank = self::periodicGroupKindRank($b->kind);
                    if ($a_rank !== $b_rank) {
                        return $a_rank <=> $b_rank;
                    }
                    /** @var int $ac */
                    $ac = $a->facts['count'] ?? 0;
                    /** @var int $bc */
                    $bc = $b->facts['count'] ?? 0;
                    return $bc <=> $ac;
                },
            );

            foreach ($bin_periodic_groups as $finding) {
                $facts = $finding->facts;
                /** @var int $bin_size */
                $bin_size = $facts['bin_size'] ?? 0;
                /** @var int $count */
                $count = $facts['count'] ?? 0;
                /** @var int $stride */
                $stride = $facts['stride'] ?? 0;
                /** @var int $sample */
                $sample = $facts['sample_addr'] ?? 0;
                /** @var string $fp */
                $fp = $facts['fingerprint'] ?? '';
                /** @var string $shape */
                $shape = $facts['inferred_shape'] ?? '';
                /** @var string $confidence */
                $confidence = $facts['effective_confidence']
                    ?? $facts['inferred_confidence']
                    ?? '';
                /** @var string $reach */
                $reach = $facts['reachability'] ?? '';
                $shape_cell = $shape !== ''
                    ? sprintf('%s [%s]', $shape, strtoupper($confidence))
                    : '—';
                $reach_cell = $reach !== '' ? $reach : '—';
                $tag = match ($finding->kind) {
                    'bin_periodic_hotspot'
                        => $finding->severity === FindingSeverity::High ? '**' : '* ',
                    'bin_periodic_orphan' => 'o ',
                    'bin_periodic_reachable' => '= ',
                    default => '  ',
                };
                $lines[] = sprintf(
                    ' %s%8s %10s %8d  0x%012x  %-9s %-32s %s',
                    $tag,
                    SizeFormatter::format($bin_size),
                    number_format($count),
                    $stride,
                    $sample,
                    $reach_cell,
                    strlen($shape_cell) > 32 ? substr($shape_cell, 0, 31) . '…' : $shape_cell,
                    substr($fp, 0, 16),
                );
            }
            $lines[] = '  (** = orphan hotspot, * = hotspot, o = orphan group,'
                . ' = = reachable / claimed by PHP roots)';
            $lines[] = '';
        }

        // Per-bin shape detection — built from per-slot detector hits,
        // independent of fingerprint clustering. Surfaces "76% of bin[3]
        // is Bucket(IS_STRING)" for content-varying shapes that the
        // periodic-group path skips because their bytes don't match
        // slot-to-slot. Grouped by bin so the user reads "what's in
        // this bin?" in one block.
        if ($this->bin_detail && $bin_shape_counts !== []) {
            /** @var array<int, list<Finding>> $by_bin */
            $by_bin = [];
            foreach ($bin_shape_counts as $f) {
                /** @var int $bin_num */
                $bin_num = $f->facts['bin_num'] ?? -1;
                $by_bin[$bin_num][] = $f;
            }
            ksort($by_bin);

            $lines[] = '=== Per-bin Shape Detection ===';
            $lines[] = '';
            $lines[] = sprintf(
                '  %3s  %-32s %10s %8s  %s',
                'Bin',
                'Shape',
                'Count',
                '%',
                'Reach (orphan/reachable)',
            );
            $lines[] = '  ' . str_repeat('-', 86);
            foreach ($by_bin as $bin_num => $rows) {
                // Sort within a bin: real shapes first (by count desc),
                // unclassified last.
                usort($rows, static function (Finding $a, Finding $b): int {
                    $a_uc = $a->kind === 'bin_shape_unclassified' ? 1 : 0;
                    $b_uc = $b->kind === 'bin_shape_unclassified' ? 1 : 0;
                    if ($a_uc !== $b_uc) {
                        return $a_uc <=> $b_uc;
                    }
                    /** @var int $ac */
                    $ac = $a->facts['count'] ?? 0;
                    /** @var int $bc */
                    $bc = $b->facts['count'] ?? 0;
                    return $bc <=> $ac;
                });
                foreach ($rows as $f) {
                    /** @var int $count */
                    $count = $f->facts['count'] ?? 0;
                    /** @var float $pct */
                    $pct = $f->facts['percentage'] ?? 0.0;
                    if ($f->kind === 'bin_shape_unclassified') {
                        $shape_cell = 'unclassified';
                        $reach_cell = '—';
                    } else {
                        /** @var string $shape */
                        $shape = $f->facts['shape'] ?? '?';
                        /** @var string $confidence */
                        $confidence = $f->facts['confidence'] ?? '';
                        $shape_cell = sprintf('%s [%s]', $shape, strtoupper($confidence));
                        /** @var int $orphan */
                        $orphan = $f->facts['orphan_count'] ?? 0;
                        /** @var int $reachable */
                        $reachable = $f->facts['reachable_count'] ?? 0;
                        $reach_cell = sprintf('%d / %d', $orphan, $reachable);
                    }
                    $lines[] = sprintf(
                        '  %3d  %-32s %10s %7.1f%%  %s',
                        $bin_num,
                        strlen($shape_cell) > 32 ? substr($shape_cell, 0, 31) . '…' : $shape_cell,
                        number_format($count),
                        $pct,
                        $reach_cell,
                    );
                }
            }
            $lines[] = '';
        }

        // Top arrays table
        if ($top_arrays !== []) {
            $lines[] = '=== Top Arrays ===';
            $lines[] = '';
            $lines[] = sprintf('  %3s  %12s %12s %8s  %8s  %s', '#', 'Retained', 'Table', 'Elems', 'Node', 'Path');
            $lines[] = '  ' . str_repeat('-', 90);

            // Detect runs of "+N similar siblings" so the table doesn't
            // dump 25 near-identical $rows[0]…$rows[N] rows when one
            // representative line + a count is what a reader actually
            // wants. N4. Run only across same-kind findings — collapsing a
            // dense array into a sparse-array's run would lose the
            // [sparse] tag.
            $sizes = [];
            $extras = [];
            $paths = [];
            foreach ($top_arrays as $finding) {
                $facts = $finding->facts;
                /** @var int $r */
                $r = $facts['retained_size'] ?? $facts['table_size'] ?? 0;
                /** @var int $e */
                $e = $facts['element_count'] ?? $facts['capacity'] ?? 0;
                /** @var string $p */
                $p = $facts['owner_path'] ?? '';
                $sizes[] = $r;
                // Encode kind into the extras vector so dense vs sparse
                // never collapse together (different table-density tags
                // on the same row would confuse a reader).
                $extras[] = $e * 2 + ($finding->kind === 'sparse_array' ? 1 : 0);
                $paths[] = $p;
            }
            $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

            $array_rank = 0;
            foreach ($top_arrays as $i => $finding) {
                if ($decisions[$i]['kind'] === 'member') {
                    continue;
                }
                $facts = $finding->facts;
                /** @var int $retained */
                $retained = $facts['retained_size'] ?? $facts['table_size'] ?? 0;
                /** @var int $table */
                $table = $facts['table_size'] ?? 0;
                /** @var int $elements */
                $elements = $facts['element_count'] ?? $facts['capacity'] ?? 0;
                /** @var string $path */
                $path = $facts['owner_path'] ?? '';
                $tag = $finding->kind === 'sparse_array' ? ' [sparse]' : '';
                $nodeHint = $finding->evidence_node_ids !== []
                    ? '#' . $finding->evidence_node_ids[0]
                    : '';
                $lines[] = sprintf(
                    '  %3d  %12s %12s %8s  %8s  %s%s',
                    ++$array_rank,
                    SizeFormatter::format($retained),
                    SizeFormatter::format($table),
                    number_format($elements),
                    $nodeHint,
                    $path ?: '(root)',
                    $tag,
                );
                if ($decisions[$i]['kind'] === 'head' && isset($decisions[$i]['annotation'])) {
                    $lines[] = '       ' . $decisions[$i]['annotation'];
                }
            }
            $lines[] = '';
        }

        // Top strings table
        if ($top_strings !== []) {
            $lines[] = '=== Top Strings ===';
            $lines[] = '';
            $lines[] = sprintf('  %3s  %12s  %8s  %-30s  %s', '#', 'Size', 'Node', 'Path', 'Preview');
            $lines[] = '  ' . str_repeat('-', 100);

            // Same uniform-sibling collapse as Top Arrays. Run detection
            // uses the *full* path (not the truncated `$display_path`) so
            // segment differences past the leading `...` aren't masked.
            // Strings have no element_count, so pass 0 uniformly. N4.
            $sizes = [];
            $extras = [];
            $paths = [];
            foreach ($top_strings as $finding) {
                $facts = $finding->facts;
                /** @var int $s */
                $s = $facts['size'] ?? 0;
                /** @var string $p */
                $p = $facts['owner_path'] ?? '';
                $sizes[] = $s;
                $extras[] = 0;
                $paths[] = $p;
            }
            $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

            $string_rank = 0;
            foreach ($top_strings as $i => $finding) {
                if ($decisions[$i]['kind'] === 'member') {
                    continue;
                }
                $facts = $finding->facts;
                /** @var int $size */
                $size = $facts['size'] ?? 0;
                /** @var string $path */
                $path = $facts['owner_path'] ?? '';
                /** @var string $preview */
                $preview = $facts['preview'] ?? '';
                $display_path = strlen($path) > 30
                    ? '...' . substr($path, -27)
                    : $path;
                // Escape whitespace before truncating: a literal newline
                // splits the table row in two, and `\n`/`\t`/`\0` in the
                // preview are far more readable than the actual character.
                $preview = strtr($preview, [
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    "\0" => '\\0',
                ]);
                $display_preview = strlen($preview) > 40
                    ? substr($preview, 0, 37) . '...'
                    : $preview;
                $nodeHint = $finding->evidence_node_ids !== []
                    ? '#' . $finding->evidence_node_ids[0]
                    : '';
                $lines[] = sprintf(
                    '  %3d  %12s  %8s  %-30s  %s',
                    ++$string_rank,
                    SizeFormatter::format($size),
                    $nodeHint,
                    $display_path ?: '(root)',
                    $display_preview ?: '(binary)',
                );
                if ($decisions[$i]['kind'] === 'head' && isset($decisions[$i]['annotation'])) {
                    $lines[] = '       ' . $decisions[$i]['annotation'];
                }
            }
            $lines[] = '';
        }

        // Blame allocation
        $blame_findings = array_filter($info, fn(Finding $f) => $f->kind === 'root_blame');
        if ($blame_findings !== []) {
            $lines[] = '=== Root Blame Allocation ===';
            $lines[] = '';
            $lines[] = sprintf('  %-25s %10s %10s %10s %8s', 'Root Branch', 'Exclusive', 'Shared', 'Total', '% Heap');
            $lines[] = '  ' . str_repeat('-', 70);

            foreach ($blame_findings as $finding) {
                $facts = $finding->facts;
                /** @var string $root_name */
                $root_name = $facts['root_name'] ?? '?';
                /** @var int|float $exclusive */
                $exclusive = $facts['exclusive_bytes'] ?? 0;
                /** @var int|float $shared */
                $shared = $facts['shared_bytes'] ?? 0;
                /** @var int|float $total */
                $total = $facts['total_bytes'] ?? 0;
                /** @var float $pct */
                $pct = $facts['percentage'] ?? 0;
                $lines[] = sprintf(
                    '  %-25s %10s %10s %10s %7.1f%%',
                    $root_name,
                    SizeFormatter::format($exclusive),
                    SizeFormatter::format($shared),
                    SizeFormatter::format($total),
                    $pct,
                );
            }
            $lines[] = '';
        }

        // Info findings (non-table). N2: split into "Observations" (this
        // is the system working as intended — CoW sharing, string
        // interning) vs "Minor findings" (small dedup opportunities or
        // diagnostics worth a glance). Without the split, a working
        // singleton or interning pattern reads identical to a real
        // problem because both render with the same `[shared_*]` tag.
        $other_info = array_filter(
            $info,
            fn(Finding $f) => !in_array($f->kind, [
                'root_blame',
                'type_ranking',
                'class_ranking',
                'large_array',
                'sparse_array',
                'large_string',
            ], true)
        );
        $observations = [];
        $minor_findings = [];
        foreach ($other_info as $finding) {
            if (self::isObservation($finding)) {
                $observations[] = $finding;
            } else {
                $minor_findings[] = $finding;
            }
        }
        if ($observations !== []) {
            $lines[] = '=== Observations (no action needed) ===';
            foreach ($observations as $finding) {
                $label = self::observationLabel($finding);
                $lines[] = "  [{$label}] {$finding->summary}";
            }
            $lines[] = '';
        }
        if ($minor_findings !== []) {
            $lines[] = '=== Minor findings ===';
            foreach ($minor_findings as $finding) {
                $lines[] = "  [{$finding->kind}] {$finding->summary}";
            }
            $lines[] = '';
        }

        // Explore hint when any finding has evidence node IDs
        $hasNodeIds = false;
        foreach ($result->findings as $f) {
            if ($f->evidence_node_ids !== []) {
                $hasNodeIds = true;
                break;
            }
        }
        if ($hasNodeIds) {
            $lines[] = 'Tip: Use "rmem:explore <file.rmem> --node=N" to inspect'
                . ' nodes referenced above (#N).';
            $lines[] = '';
        }

        $lines[] = $sep;

        return implode("\n", $lines) . "\n";
    }

    private static function severityOrder(FindingSeverity $severity): int
    {
        return match ($severity) {
            FindingSeverity::High => 0,
            FindingSeverity::Warning => 1,
            FindingSeverity::Medium => 2,
            FindingSeverity::Low => 3,
            FindingSeverity::Info => 4,
        };
    }

    /**
     * Sort key for the ZendMM Periodic Groups table: hotspots first
     * (orphan-promoted ahead of plain), then orphan-only groups, then
     * unclassified, then reachable-only at the bottom (lowest signal).
     */
    private static function periodicGroupKindRank(string $kind): int
    {
        return match ($kind) {
            'bin_periodic_hotspot' => 0,
            'bin_periodic_orphan' => 1,
            'bin_periodic_group' => 2,
            'bin_periodic_reachable' => 3,
            default => 4,
        };
    }

    /**
     * Bucket selection for the Additional Info split (N2).
     *
     * - `shared_singleton` is always an observation: by definition it
     *   means many references collapsed onto one target, which is the
     *   CoW share working as intended.
     * - `shared_fanin` becomes an observation only when the refs/targets
     *   ratio is >= 100. Real-world examples: PDO HashTable keys at
     *   4.5M refs → 39 interned strings (~115k each). Below that
     *   threshold, the ratio represents a small-pool dedup opportunity
     *   worth surfacing as a minor finding rather than hiding as an
     *   "all good" observation. The [10, 100) middle band defaults to
     *   Minor — borderline patterns are worth a glance.
     *
     * @psalm-suppress MixedAssignment
     */
    private static function isObservation(Finding $finding): bool
    {
        if ($finding->kind === 'shared_singleton') {
            return true;
        }
        if ($finding->kind === 'shared_fanin') {
            $refs = $finding->facts['ref_count'] ?? 0;
            $targets = $finding->facts['target_count'] ?? 0;
            if (!is_int($refs) || !is_int($targets) || $targets <= 0) {
                return false;
            }
            return $refs / $targets >= 100;
        }
        return false;
    }

    /**
     * Reader-facing tag for the Observations bucket. The raw kinds
     * (`shared_singleton`, `shared_fanin`) document what the detector
     * looked for; the observation tag documents what it *means* — CoW
     * share, string interning. Both forms remain accessible on JSON
     * output via `Finding::$kind`; this is text-only narrative.
     */
    private static function observationLabel(Finding $finding): string
    {
        return match ($finding->kind) {
            'shared_singleton' => 'CoW share',
            'shared_fanin' => 'interning',
            default => $finding->kind,
        };
    }

    /**
     * Walk `facts.path[]` step-by-step (with PathFormatter applied to
     * each prefix) and emit one entry per *user-visible* step — the
     * structural intermediaries (`array_elements`, `object_properties`,
     * etc.) are elided, the leading call-frame label (`<main>()::`) is
     * stripped, and only the bare token added at each step appears in
     * `delta`. Sizes come straight from `facts.sizes[i]` at the same
     * index that produced the user-visible token; identical adjacent
     * sizes are *not* collapsed because the steps individually
     * communicate which variable name lives at which depth. T4.
     *
     * Stops at `facts.summary_path`'s depth — the leaf example beyond
     * a uniform-sibling truncation is rendered separately by the
     * existing `Leaf example: …` hypothesis line, not as a parallel
     * descent.
     *
     * Returns `[]` when facts are missing or malformed (older fixtures
     * predating this work, or pass-level bugs); the caller falls back
     * to the legacy summary line.
     *
     * @return list<array{delta: string, size: int}>
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArgumentTypeCoercion
     */
    private static function buildBottleneckDescent(Finding $finding): array
    {
        /** @var mixed $raw_path */
        $raw_path = $finding->facts['path'] ?? null;
        /** @var mixed $raw_types */
        $raw_types = $finding->facts['path_types'] ?? null;
        /** @var mixed $raw_sizes */
        $raw_sizes = $finding->facts['sizes'] ?? null;
        if (!is_array($raw_path) || !is_array($raw_types) || !is_array($raw_sizes)) {
            return [];
        }
        $n = count($raw_path);
        if ($n === 0 || $n !== count($raw_types) || $n !== count($raw_sizes)) {
            return [];
        }
        /** @var list<string> $path */
        $path = [];
        foreach ($raw_path as $p) {
            if (!is_string($p)) {
                return [];
            }
            $path[] = $p;
        }
        /** @var list<string> $types */
        $types = [];
        foreach ($raw_types as $t) {
            $types[] = is_string($t) ? $t : '';
        }
        /** @var list<int> $sizes */
        $sizes = [];
        foreach ($raw_sizes as $s) {
            if (!is_int($s) || $s < 0) {
                return [];
            }
            $sizes[] = $s;
        }
        $summary_path = is_string($finding->facts['summary_path'] ?? null)
            ? (string)$finding->facts['summary_path']
            : '';

        $steps = [];
        $prev_formatted = '';
        for ($i = 0; $i < $n; $i++) {
            // Structural intermediaries (`array_elements`,
            // `object_properties`, …) are elided by PathFormatter, so
            // they contribute nothing user-visible — skip without
            // recomputing the prefix. This also dodges PathFormatter's
            // " -> "-joined fallback for entirely-structural prefixes,
            // the same case `formatSpineDropLabel` defends against.
            if (PathFormatter::isStructuralLink($path[$i])) {
                continue;
            }
            $cur = PathFormatter::toPhpSyntax(
                array_slice($path, 0, $i + 1),
                array_slice($types, 0, $i + 1),
            );
            if ($cur === $prev_formatted || $cur === '(root)') {
                continue;
            }
            // PathFormatter adds `Class::method()::` segments for call
            // frames that haven't yet been followed by a variable. Skip
            // those — they're not a user-visible descent step on their
            // own; the next step glues a `$var` onto them and that's
            // where the descent starts.
            if (str_ends_with($cur, '::')) {
                $prev_formatted = $cur;
                continue;
            }
            // Stop once we descend past the chosen summary_path. The
            // leaf example beyond it is rendered separately by the
            // hypothesis "Leaf example: …" line.
            if (
                $summary_path !== ''
                && !str_starts_with($summary_path, $cur)
                && $cur !== $summary_path
            ) {
                break;
            }
            // Compute what was newly added at this step. For the very
            // first user-visible step, drop any leading `<main>()::` /
            // `Foo::method()::` so the descent reads as the user-named
            // variable (matching summary_path's behaviour). For later
            // steps, the delta is the suffix beyond the previous step's
            // formatted output.
            if ($prev_formatted === '' || str_ends_with($prev_formatted, '::')) {
                $delta = preg_replace('/^[^\$\[]+::/', '', $cur);
                if (!is_string($delta)) {
                    $delta = $cur;
                }
            } elseif (str_starts_with($cur, $prev_formatted)) {
                $delta = substr($cur, strlen($prev_formatted));
            } else {
                $delta = $cur;
            }
            if ($delta === '') {
                $prev_formatted = $cur;
                continue;
            }
            $steps[] = ['delta' => $delta, 'size' => $sizes[$i]];
            $prev_formatted = $cur;
            if ($cur === $summary_path) {
                break;
            }
        }
        return $steps;
    }

    /**
     * Vertical descent block. One line per user-visible step; each
     * line is `<indent + bullet><name>   <right-aligned size>`.
     * Both name and size columns are sized to the descent's own max
     * widths — same approach the Top Arrays / Top Strings tables use,
     * so reading `186.11 MB` next to `2.10 KB` keeps the right-edge
     * units aligned.
     *
     * @param list<array{delta: string, size: int}> $steps
     * @return list<string>
     */
    private static function renderDescentVertical(array $steps): array
    {
        $lefts = [];
        $size_strs = [];
        foreach ($steps as $depth => $step) {
            // depth 0 is the variable root: no bullet, no indent. From
            // depth 1 onward each level adds 2 spaces and a `└ ` to
            // signal "we descended into …". The Unicode glyph is
            // already in use elsewhere (the em-dash on the `[—]`
            // empty-impact tag) so terminal compatibility is moot.
            if ($depth === 0) {
                $prefix = '';
            } else {
                $prefix = str_repeat('  ', $depth - 1) . '└ ';
            }
            $lefts[] = $prefix . self::truncatePathSegment($step['delta']);
            $size_strs[] = SizeFormatter::format($step['size']);
        }
        $max_left = 0;
        foreach ($lefts as $left) {
            $w = mb_strlen($left, 'UTF-8');
            if ($w > $max_left) {
                $max_left = $w;
            }
        }
        $max_size = 0;
        foreach ($size_strs as $s) {
            $w = mb_strlen($s, 'UTF-8');
            if ($w > $max_size) {
                $max_size = $w;
            }
        }
        $lines = [];
        foreach ($lefts as $i => $left) {
            $size = $size_strs[$i];
            $left_pad = str_repeat(' ', max(0, $max_left - mb_strlen($left, 'UTF-8')));
            $size_pad = str_repeat(' ', max(0, $max_size - mb_strlen($size, 'UTF-8')));
            $lines[] = $left . $left_pad . '   ' . $size_pad . $size;
        }
        return $lines;
    }

    /**
     * Inline descent block: `$root (size) → step (size) → leaf (size)`.
     * Used for shallow descents (≤ 3 user-visible steps) where a
     * vertical block would feel oversized. Every step — including the
     * first — renders its own size, because `sizes[0]` (carried by the
     * `[HIGH] X impacted` line above) is the path's *true root*
     * retained, which differs from the first user-visible step's
     * retained whenever structural prefix nodes (`global_variables` /
     * `array_elements`) hold non-trivial state outside the chosen
     * descent. Concrete corpus cases — `rw3_reflection-heavy`
     * (7.21 MB impact vs 4.63 MB at `$propertyInfoCache`),
     * `rw4_graph-recursion` (110.97 MB vs 49.51 MB) — would otherwise
     * leave the first step's retained unobservable.
     *
     * @param list<array{delta: string, size: int}> $steps
     */
    private static function renderDescentInline(array $steps): string
    {
        $parts = [];
        foreach ($steps as $step) {
            $name = self::truncatePathSegment($step['delta']);
            $parts[] = $name . ' (' . SizeFormatter::format($step['size']) . ')';
        }
        return implode(' → ', $parts);
    }

    /**
     * Strip a leading `->` (the tree indent already conveys "descended
     * into …", so the arrow is redundant on a property step) and
     * shorten the result if it would push the descent past terminal
     * width. Brackets `[]` are kept because they *are* the index
     * syntax — a bare key would be unrecognisable out of context.
     */
    private static function truncatePathSegment(string $s): string
    {
        if (str_starts_with($s, '->')) {
            $s = substr($s, 2);
        }
        if (mb_strlen($s, 'UTF-8') > 40) {
            return '...' . mb_substr($s, -37, null, 'UTF-8');
        }
        return $s;
    }

    /**
     * Render `bottleneck_path` spine info from `facts.sizes`.
     *
     * Detects the first dominance drop (sizes[i+1] < sizes[i] * 0.5)
     * and prints one explanatory line — the leaf retained size, plus
     * a note if the descent flatlines after the drop (uniform-sibling
     * region). Returns 0–2 lines to be rendered under the descent
     * block.
     *
     * @return list<string>
     * @psalm-suppress MixedAssignment, MixedArgument
     */
    private static function renderBottleneckSpine(Finding $finding): array
    {
        /** @var mixed $raw_sizes */
        $raw_sizes = $finding->facts['sizes'] ?? null;
        if (!is_array($raw_sizes) || count($raw_sizes) < 2) {
            return [];
        }

        /** @var list<int> $sizes */
        $sizes = [];
        foreach ($raw_sizes as $s) {
            if (is_int($s) && $s >= 0) {
                $sizes[] = $s;
            }
        }
        if (count($sizes) < 2) {
            return [];
        }

        $root_size = $sizes[0];
        $leaf_size = $sizes[count($sizes) - 1];
        if ($root_size <= 0) {
            return [];
        }

        $drop_index = null;
        for ($i = 0; $i < count($sizes) - 1; $i++) {
            if ($sizes[$i + 1] * 2 < $sizes[$i]) {
                $drop_index = $i;
                break;
            }
        }

        if ($drop_index === null) {
            // Descent stays heavy all the way down — the displayed size
            // is roughly accurate; nothing to clarify.
            return [];
        }

        $pre_drop_size = $sizes[$drop_index];
        $post_drop_size = $sizes[$drop_index + 1];

        // Name the drop position by the path component the descent
        // landed on, not the depth integer ("at depth 5" forced the
        // reader to count segments in `facts.path[]` to learn what was
        // there). Format the path prefix up to and including the
        // pre-drop component via PathFormatter so it matches the
        // syntax used by `summary_path` ("$sink->addressMinNode" etc.).
        $drop_label = self::formatSpineDropLabel($finding, $drop_index);
        $line = $drop_label !== null
            ? sprintf(
                'Spine: heaviest-child mass drops after %s'
                . ' (%s → %s); leaf retains only %s',
                $drop_label,
                SizeFormatter::format($pre_drop_size),
                SizeFormatter::format($post_drop_size),
                SizeFormatter::format($leaf_size),
            )
            : sprintf(
                // Legacy path: facts predate path_types — fall back to
                // the depth integer rather than emit nothing.
                'Spine: heaviest-child mass drops at depth %d'
                . ' (%s → %s); leaf retains only %s',
                $drop_index + 1,
                SizeFormatter::format($pre_drop_size),
                SizeFormatter::format($post_drop_size),
                SizeFormatter::format($leaf_size),
            );

        $out = [$line];

        // Decide whether to mark the post-drop region as a "uniform-
        // sibling" tail. The label is a reader hint that the chosen leaf
        // index is incidental: the descent landed on one of many similar
        // children of the same parent, not a single deep spine.
        //
        // Two forgiving signals — either suffices:
        //   (a) No single tail step is sharper than 3× (the descent
        //       slope is shallow — weight is spreading gradually).
        //   (b) Overall max/min ratio across the tail is < 4× (the tail
        //       spans less than two doublings; even if some individual
        //       steps are >3×, the total spread is bounded).
        //
        // 2× was too strict: descents like
        // 2.47 KB → 1.8 KB → 600 B (still gradual but with one >3× step)
        // were excluded. 3× is calibrated against the corpus — see the
        // T1+T2 verification doc on the research branch for examples.
        if ($drop_index + 2 < count($sizes)) {
            $tail = array_slice($sizes, $drop_index + 1);
            // The outer length check guarantees $tail has ≥ 2 elements, so
            // max()/min() are safe — but psalm can't infer that, so seed
            // the running min/max from the first element instead.
            $has_sharp_step = false;
            $tail_max = $tail[0];
            $tail_min = $tail[0];
            for ($i = 0; $i < count($tail); $i++) {
                if ($i + 1 < count($tail) && $tail[$i + 1] * 3 < $tail[$i]) {
                    $has_sharp_step = true;
                }
                if ($tail[$i] > $tail_max) {
                    $tail_max = $tail[$i];
                }
                if ($tail[$i] < $tail_min) {
                    $tail_min = $tail[$i];
                }
            }
            $tail_min = max(1, $tail_min);
            $tail_spread_bounded = ($tail_max / $tail_min) < 4.0;

            if (!$has_sharp_step || $tail_spread_bounded) {
                $out[] = '       leaf is one of many similar-sized siblings'
                    . ' — weight is distributed, no single deep spine';
            }
        }

        return $out;
    }

    /**
     * Resolve the spine-drop position to a user-facing PHP-syntax label
     * like `$sink->addressMinNode` (matching the format of
     * `facts.summary_path`). Returns null when the finding doesn't carry
     * `path` + `path_types` — the caller falls back to a depth integer
     * in that case.
     *
     * Long prefixes are truncated to the trailing portion so the line
     * stays inside terminal width: a path like
     * `$container->config->servers[0]->endpoints[3]` becomes
     * `...->endpoints[3]` past 50 chars.
     *
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArgumentTypeCoercion
     */
    private static function formatSpineDropLabel(
        Finding $finding,
        int $drop_index,
    ): ?string {
        /** @var mixed $raw_path */
        $raw_path = $finding->facts['path'] ?? null;
        /** @var mixed $raw_types */
        $raw_types = $finding->facts['path_types'] ?? null;
        if (!is_array($raw_path) || !is_array($raw_types)) {
            return null;
        }
        if ($drop_index < 0 || $drop_index >= count($raw_path)) {
            return null;
        }

        /** @var list<string> $all_parts */
        $all_parts = [];
        foreach ($raw_path as $p) {
            if (!is_string($p)) {
                return null;
            }
            $all_parts[] = $p;
        }
        /** @var list<string> $all_types */
        $all_types = [];
        foreach ($raw_types as $t) {
            $all_types[] = is_string($t) ? $t : '';
        }

        // Walk past trailing structural intermediaries (`global_variables`,
        // `array_elements`, `object_properties`, ...) so the rendered
        // prefix lands on a user-named identifier (`$decoded`, `$bus`,
        // a class name, ...). Without this, a depth-1 drop into
        // `array_elements` of `global_variables` rendered as the literal
        // `global_variables -> array_elements` because PathFormatter's
        // structural elision had nothing to anchor on, while the
        // bottleneck_path summary line on the same finding showed the
        // user-side `$decoded[...]`. T2.5 — the two lines now share
        // vocabulary.
        //
        // Stop conditions:
        //  - Hit a non-structural component (the user-named slot that
        //    PathFormatter can render as `$var`, `Class::name`, etc.).
        //  - Run off the end of the path. In that rare case the descent
        //    is entirely structural (e.g. pure class_table internals);
        //    we fall back to the depth integer rather than emit a
        //    longer string of structural noise.
        $end = $drop_index + 1;
        while (
            $end < count($all_parts)
            && PathFormatter::isStructuralLink($all_parts[$end - 1])
        ) {
            $end++;
        }
        if (PathFormatter::isStructuralLink($all_parts[$end - 1])) {
            return null;
        }

        $path_parts = array_slice($all_parts, 0, $end);
        $path_types = array_slice($all_types, 0, $end);

        $label = PathFormatter::toPhpSyntax($path_parts, $path_types);
        if ($label === '' || $label === '(root)') {
            return null;
        }

        // Truncate from the right when the prefix grows long. 50 chars
        // keeps the full Spine line under ~110 chars, comfortable for
        // most terminals.
        $max = 50;
        if (strlen($label) > $max) {
            $label = '...' . substr($label, -($max - 3));
        }
        return $label;
    }
}
