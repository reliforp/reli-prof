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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class DedupCandidatePass implements PassInterface
{
    /**
     * @param list<array{
     *     link_name: string,
     *     size: int,
     *     cnt: int,
     *     total_waste: int,
     *     sample_parent_node_id: int,
     *     sample_child_node_id: int,
     *     sample_location_type?: ?string,
     *     target_class?: ?string,
     *     target_location_type?: ?string,
     *     sample_child_node_ids?: list<int>,
     *     examples?: array<string, mixed>
     * }> $precomputed_dedup_candidates
     *     Bucketed dedup-candidate stats. Produced by
     *     {@see GraphSubstrate::getDedupCandidateStats()} — the pass is a
     *     pure formatter on top of the substrate primitive, with no SQL
     *     fallback (Stage B of #787).
     */
    public function __construct(
        private GraphSubstrate $substrate,
        private array $precomputed_dedup_candidates,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument, RiskyTruthyFalsyComparison
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    #[\Override]
    public function analyze(): array
    {
        $findings = [];

        foreach ($this->precomputed_dedup_candidates as $row) {
            $link_name = $row['link_name'];
            if ($link_name === 'key') {
                continue;
            }

            $sample_child_node_id = $row['sample_child_node_id'];
            $target_class = $row['target_class'] ?? null;
            $target_location_type = $row['target_location_type']
                ?? $row['sample_location_type']
                ?? $this->substrate->getNodeLocationType($sample_child_node_id);
            if ($target_location_type === 'ZendArrayMemoryLocation') {
                continue;
            }

            $cnt = $row['cnt'];
            $shallow_size = $row['size'];
            $total = $row['total_waste'];
            $size = $shallow_size;
            /** @var list<int>|null $sample_child_node_ids */
            $sample_child_node_ids = $row['sample_child_node_ids'] ?? null;

            // Compute `total_waste` as the **union** of tree-edge subtrees
            // rooted at the bucket's member nodes. Each reachable node is
            // counted once even when N seeds share an SCC subtree, so this
            // value reads honestly as "memory currently sitting under any
            // of the N copies" and is intrinsically bounded by the heap
            // total — no `cnt × retained` over-count, no clamp needed.
            // See T2.1 in docs/internals/memory-report-implementation-handoff.md.
            $member_node_ids = $sample_child_node_ids ?? [];
            if ($member_node_ids !== []) {
                $union_size = $this->substrate->unionReachableTreeSize($member_node_ids);
                if ($union_size > 0) {
                    $total = $union_size;
                    $size = $cnt > 0 ? (int)($union_size / $cnt) : $shallow_size;
                }
            }

            $sample_parent_node_id = $row['sample_parent_node_id'];
            [
                'source_class' => $dedup_src,
                'owner_prop' => $owner_prop,
            ] = $this->resolveDedupOwnerInfoFromSubstrate($sample_parent_node_id);
            $dedup_tgt = $target_class ?? $this->substrate->getNodeClass($sample_child_node_id);

            $dedup_label = $this->buildDedupLabel(
                $dedup_src,
                $owner_prop,
                $link_name,
                $dedup_tgt,
            );

            /** @var array<string, mixed> $examples */
            $examples = $row['examples'] ?? ['type' => 'object', 'samples' => [], 'identical_count' => 0];

            [
                'hypothesis' => $hypothesis,
                'confidence' => $confidence,
            ] = $this->buildEvidenceSummary($examples, $cnt);

            $findings[] = new Finding(
                kind: 'dedup_candidate',
                severity: $total > 102400
                    ? FindingSeverity::Low
                    : FindingSeverity::Info,
                confidence: $confidence,
                summary: sprintf(
                    '%s: %s copies x %s%s = %s',
                    $dedup_label,
                    number_format($cnt),
                    SizeFormatter::format($size),
                    $size > $shallow_size ? ' avg retained' : '',
                    SizeFormatter::format($total),
                ),
                facts: [
                    'link_name' => $link_name,
                    'source_class' => $dedup_src,
                    'target_class' => $dedup_tgt,
                    'count' => $cnt,
                    'each_size' => $size,
                    'total_waste' => $total,
                    'examples' => $examples,
                ],
                hypothesis: $hypothesis,
                impact_bytes: $total,
                evidence_node_ids: $sample_child_node_ids !== null
                    ? array_slice($sample_child_node_ids, 0, 20)
                    : [$sample_child_node_id],
            );
        }

        return $findings;
    }

    /**
     * @return array{source_class: ?string, owner_prop: ?string}
     */
    private function resolveDedupOwnerInfoFromSubstrate(int $parent_node_id): array
    {
        $source_class = $this->resolveDirectSourceClassFromSubstrate($parent_node_id);
        if ($source_class !== null) {
            return [
                'source_class' => $source_class,
                'owner_prop' => null,
            ];
        }

        $array_elements_node_id = $this->substrate->getTreeParentNodeId($parent_node_id);
        if (
            $array_elements_node_id === null
            || $this->substrate->getTreeLinkName($array_elements_node_id) !== 'array_elements'
        ) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $array_header_node_id = $this->substrate->getTreeParentNodeId($array_elements_node_id);
        if ($array_header_node_id === null) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $owner_prop = $this->substrate->getTreeLinkName($array_header_node_id);
        $object_properties_node_id = $this->substrate->getTreeParentNodeId($array_header_node_id);
        if (
            $object_properties_node_id === null
            || $this->substrate->getTreeLinkName($object_properties_node_id) !== 'object_properties'
        ) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $owner_node_id = $this->substrate->getTreeParentNodeId($object_properties_node_id);
        if ($owner_node_id === null) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        return [
            'source_class' => $this->substrate->getNodeClass($owner_node_id),
            'owner_prop' => $owner_prop,
        ];
    }

    private function resolveDirectSourceClassFromSubstrate(int $parent_node_id): ?string
    {
        if ($this->substrate->getTreeLinkName($parent_node_id) !== 'object_properties') {
            return null;
        }

        $owner_node_id = $this->substrate->getTreeParentNodeId($parent_node_id);
        if ($owner_node_id === null) {
            return null;
        }

        return $this->substrate->getNodeClass($owner_node_id);
    }

    private function buildDedupLabel(
        ?string $source_class,
        ?string $owner_prop,
        string $link_name,
        ?string $target_class,
    ): string {
        if ($source_class !== null && $owner_prop !== null) {
            $label = "{$source_class}::\${$owner_prop}[{$link_name}]";
        } elseif ($source_class !== null) {
            $label = "{$source_class}::\${$link_name}";
        } else {
            $label = $link_name;
        }

        if ($target_class !== null) {
            $label .= " ({$target_class})";
        }

        return $label;
    }

    /**
     * @param array<string, mixed> $examples
     * @return array{hypothesis: string, confidence: FindingConfidence}
     */
    private function buildEvidenceSummary(array $examples, int $count): array
    {
        $hypothesis = 'Multiple copies of same-size objects'
            . ' via shared references; may be shareable';
        $confidence = FindingConfidence::Low;

        if (($examples['type'] ?? null) === 'string') {
            $identical_count = (int)($examples['identical_count'] ?? 0);
            if ($identical_count > 0) {
                $pct = $count > 0
                    ? ((float)$identical_count / (float)$count) * 100.0
                    : 0.0;
                $hypothesis = sprintf(
                    '%d/%d copies have identical content (%.0f%%). Example: "%s"',
                    $identical_count,
                    $count,
                    $pct,
                    (string)($examples['sample_value'] ?? ''),
                );
                $confidence = $pct > 50.0
                    ? FindingConfidence::High
                    : FindingConfidence::Medium;
            } else {
                /** @var list<string> $samples */
                $samples = is_array($examples['samples'] ?? null)
                    ? $examples['samples']
                    : [];
                $hypothesis = sprintf(
                    'Same size but different content. Examples: "%s", "%s"',
                    $samples[0] ?? '?',
                    $samples[1] ?? '?',
                );
            }
        } elseif (($examples['type'] ?? null) === 'object') {
            /** @var mixed $raw_samples */
            $raw_samples = $examples['samples'] ?? null;
            /** @var list<string> $samples */
            $samples = is_array($raw_samples)
                ? array_slice($raw_samples, 0, 3)
                : [];
            if ($samples !== []) {
                $hypothesis .= sprintf('. Examples: %s', implode(', ', $samples));
            }
        }

        return [
            'hypothesis' => $hypothesis,
            'confidence' => $confidence,
        ];
    }
}
