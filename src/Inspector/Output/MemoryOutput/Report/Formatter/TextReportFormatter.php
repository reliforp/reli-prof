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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\PathFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\UniformSiblingDetector;

final class TextReportFormatter implements ReportFormatterInterface
{
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
                    $lines[] = '  Call Stack at capture:';
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

            $lines[] = '=== Findings ===';
            $lines[] = '';

            foreach ($actionable as $finding) {
                $tag = strtoupper($finding->severity->value);
                $impact = $finding->impact_bytes > 0
                    ? SizeFormatter::format($finding->impact_bytes)
                    . ' impacted'
                    : "\u{2014}";
                $lines[] = "  [{$tag}] {$impact}";
                $lines[] = "    {$finding->kind}: {$finding->summary}";

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

        // Info findings (non-table)
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
        if ($other_info !== []) {
            $lines[] = '=== Additional Info ===';
            foreach ($other_info as $finding) {
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
     * Render `bottleneck_path` spine info from `facts.sizes`.
     *
     * The summary string already shows `<path> (<size>)`, but on a
     * descent like `$decoded[data][10100][profile]` the displayed size
     * is the spine *root's* retained size while the path is the spine's
     * *leaf*. When the heaviest-child descent crosses into a uniform-
     * sibling region (e.g. one of 25,000 ~3 KB profiles), the size and
     * the path describe opposite ends of the chain.
     *
     * Detect the first dominance drop (sizes[i+1] < sizes[i] * 0.5) and
     * print one explanatory line — the leaf retained size, plus a note
     * if the descent flatlines after the drop (uniform-sibling region).
     *
     * Returns 0–2 lines to be rendered under the standard summary.
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
