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

namespace Reli\Inspector\Output\MemoryOutput\Report\Pass;

use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingConfidence;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\LinkNameResolver;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class PropertyScalingPass implements PassInterface
{
    /**
     * @param array<string, array{count: int, memory_usage: int}> $class_objects_summary
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private array $class_objects_summary,
        private GraphSubstrate $substrate,
        private ?LinkNameResolver $link_resolver = null,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     * @psalm-suppress MixedOperand, InvalidOperand, MixedArrayOffset, PossiblyInvalidArgument
     */
    #[\Override]
    public function analyze(): array
    {
        $total_object_memory = 0;
        foreach ($this->class_objects_summary as $entry) {
            $total_object_memory += $entry['memory_usage'];
        }
        if ($total_object_memory === 0) {
            return [];
        }

        $dominant_class = null;
        $dominant_count = 0;
        foreach ($this->class_objects_summary as $name => $entry) {
            $pct = $entry['memory_usage'] / $total_object_memory * 100.0;
            if ($pct > 50.0 && $entry['count'] > 100) {
                $dominant_class = $name;
                $dominant_count = $entry['count'];
                break;
            }
        }

        if ($dominant_class === null) {
            return [];
        }

        $props = $this->analyzeWithGraph($dominant_class);

        if ($props === []) {
            return [];
        }

        $use_retained = $this->substrate->hasSubtreeSizes();

        $per_instance = [];
        $shared = [];
        $scalar_count = 0;

        foreach ($props as $p) {
            if ($p['scaling'] !== 'SHARED') {
                if ($p['size'] === 0) {
                    $scalar_count++;
                    continue;
                }
                $per_instance[] = $p;
            } else {
                $shared[] = $p;
            }
        }

        $this->classifySharedReasons($shared);

        $short = $dominant_class;
        $size_label = $use_retained ? 'retained' : 'shallow';

        $lines = [];
        if ($per_instance !== []) {
            $lines[] = "PER-INSTANCE ({$size_label}, scales with count):";
            usort(
                $per_instance,
                fn($a, $b) => $b['size'] <=> $a['size']
            );
            foreach (array_slice($per_instance, 0, 8) as $p) {
                $avg = $p['distinct_targets'] > 0
                    ? (int)($p['size'] / $p['distinct_targets'])
                    : 0;
                $lines[] = sprintf(
                    '  %s::$%s: %s copies x %s = %s',
                    $short,
                    $p['property'],
                    number_format($p['distinct_targets']),
                    SizeFormatter::format($avg),
                    SizeFormatter::format($p['size']),
                );
            }
        }
        if ($scalar_count > 0) {
            $lines[] = sprintf(
                '(%d scalar properties per-instance,'
                . ' included in object size)',
                $scalar_count
            );
        }
        if ($shared !== []) {
            $shared_names = array_map(
                function ($s) use ($short): string {
                    $reason = $s['share_reason'] ?? '';
                    $suffix = $reason !== '' ? " ({$reason})" : '';
                    return $short . '::$' . $s['property'] . $suffix;
                },
                array_slice($shared, 0, 10)
            );
            $lines[] = 'SHARED: '
                . implode(', ', $shared_names);
        }

        $per_instance_total = array_sum(
            array_column($per_instance, 'size')
        );
        $impact_bytes = $per_instance_total;

        $hypothesis = 'Per-instance properties scale linearly;'
            . ' shared properties have constant cost.'
            . "\n" . implode("\n", $lines);

        $facts = [
            'class_name' => $dominant_class,
            'instance_count' => $dominant_count,
            'per_instance_properties' => $per_instance,
            'shared_properties' => $shared,
            'scalar_properties_count' => $scalar_count,
            'per_instance_total_bytes' => $per_instance_total,
            'size_mode' => $size_label,
        ];

        return [
            new Finding(
                kind: 'property_scaling',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%s (%s instances): %d per-instance props'
                    . ' (%s/instance %s), %d shared',
                    $short,
                    number_format($dominant_count),
                    count($per_instance),
                    SizeFormatter::format(
                        $dominant_count > 0
                            ? (int)($per_instance_total / $dominant_count)
                            : 0
                    ),
                    $size_label,
                    count($shared),
                ),
                facts: $facts,
                hypothesis: $hypothesis,
                next_checks: [
                    'Per-instance props with small values'
                    . ' may benefit from lazy init',
                    'Check if per-instance arrays can be replaced'
                    . ' with defaults',
                ],
                impact_bytes: $impact_bytes,
            ),
        ];
    }

    /**
     * In-memory analysis using GraphSubstrate. O(nodes).
     * @return list<array<string, mixed>>
     * @psalm-suppress InvalidOperand
     */
    private function analyzeWithGraph(string $dominant_class): array
    {
        $use_retained = $this->substrate->hasSubtreeSizes();

        $resolver = $this->link_resolver ?? new LinkNameResolver($this->db, $this->run_id);

        // For each object of dominant_class, walk children to find
        // object_properties → property children
        /** @var array<string, array{distinct: array<int, true>, total_refs: int, size: int}> */
        $prop_stats = [];

        foreach ($this->substrate->iterateNodeClasses() as $node_id => $cls) {
            if ($cls !== $dominant_class) {
                continue;
            }
            // Skip non-canonical duplicates to avoid double-counting instances
            if (!$this->substrate->isCanonicalOrUnique($node_id)) {
                continue;
            }
            foreach ($this->substrate->getChildren($node_id) as $child) {
                if (($resolver->lookup($child) ?? '') !== 'object_properties') {
                    continue;
                }
                // child = ObjectPropertiesContext
                // Deduplicate property children by canonical
                $seen_prop_canonicals = [];
                foreach ($this->substrate->getChildren($child) as $prop_child) {
                    $prop_name = $resolver->lookup($prop_child);
                    if ($prop_name === null) {
                        continue;
                    }
                    $prop_canon = $this->substrate->getCanonical($prop_child);
                    if (isset($seen_prop_canonicals[$prop_canon])) {
                        continue;
                    }
                    $seen_prop_canonicals[$prop_canon] = true;

                    if (!isset($prop_stats[$prop_name])) {
                        $prop_stats[$prop_name] = [
                            'distinct' => [],
                            'total_refs' => 0,
                            'size' => 0,
                        ];
                    }
                    $prop_stats[$prop_name]['distinct'][$prop_canon] = true;
                    $prop_stats[$prop_name]['total_refs']++;
                    if (!$use_retained) {
                        // Shallow-size mode is unaffected by SCC sharing —
                        // accumulate per-child as before.
                        $prop_stats[$prop_name]['size']
                            += $this->substrate->getNodeSize($prop_child);
                    }
                }
                break;
            }
        }

        // Retained-size mode: sum each property's union of tree-edge
        // subtrees rooted at its distinct child node_ids. Each reachable
        // node counted once across all N instances of the property — so
        // an SCC shared by every instance contributes once, not N times,
        // and the sum is intrinsically bounded by the heap. Replaces the
        // earlier `Σ_i getSubtreeSize(prop_child_i)` which was O(N²) for
        // SCC graphs and required a heap-total clamp downstream.
        // See T2.1 in docs/internals/memory-report-implementation-handoff.md.
        if ($use_retained) {
            foreach ($prop_stats as $prop_name => $stat) {
                $seeds = array_keys($stat['distinct']);
                $prop_stats[$prop_name]['size']
                    = $this->substrate->unionReachableTreeSize($seeds);
            }
        }

        $results = [];
        foreach ($prop_stats as $prop_name => $stat) {
            $distinct = count($stat['distinct']);
            $total_refs = $stat['total_refs'];

            if ($distinct === 1) {
                $scaling = 'SHARED';
            } elseif ($distinct >= $total_refs * 0.9) {
                $scaling = 'PER-INSTANCE';
            } else {
                $scaling = 'PARTIALLY SHARED';
            }

            // Get a sample child_id for shared reason classification
            $sample_id = array_key_first($stat['distinct']) ?? 0;

            $results[] = [
                'property' => $prop_name,
                'distinct_targets' => $distinct,
                'total_refs' => $total_refs,
                'size' => $stat['size'],
                'scaling' => $scaling,
                'sample_child_id' => $sample_id,
            ];
        }

        return $results;
    }

    /**
     * Classify sharing reason for each shared property.
     * @param list<array<string, mixed>> $shared
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function classifySharedReasons(array &$shared): void
    {
        if ($shared === []) {
            return;
        }

        foreach ($shared as &$s) {
            $cid = (int)$s['sample_child_id'];
            $type = $this->substrate->getNodeType($cid) ?? '';
            $s['share_reason'] = $type === 'PhpReferenceContext'
                ? 'PHP reference'
                : 'shared';
        }
    }
}
