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

namespace Reli\Inspector\Output\MemoryOutput\Comparison;

use Reli\Inspector\Output\MemoryOutput\Report\Finding;

final class ComparisonResult
{
    /**
     * @param array<string, mixed> $baseline_meta
     * @param array<string, mixed> $target_meta
     * @param list<SummaryDelta> $summary_deltas
     * @param list<TypeDelta> $type_deltas
     * @param list<ClassDelta> $class_changes
     * @param list<ClassDelta> $class_added
     * @param list<ClassDelta> $class_removed
     * @param list<BinDelta> $bin_deltas
     * @param list<RegionDelta> $region_deltas
     */
    public function __construct(
        public readonly array $baseline_meta,
        public readonly array $target_meta,
        public readonly array $summary_deltas,
        public readonly array $type_deltas,
        public readonly array $class_changes,
        public readonly array $class_added,
        public readonly array $class_removed,
        public readonly FindingsDiff $findings_diff,
        public readonly array $bin_deltas = [],
        public readonly ?Finding $unaccounted_finding = null,
        public readonly array $region_deltas = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'baseline' => $this->baseline_meta,
            'target' => $this->target_meta,
            'summary_deltas' => array_map(
                fn(SummaryDelta $d) => $d->toArray(),
                $this->summary_deltas,
            ),
            'type_deltas' => array_map(
                fn(TypeDelta $d) => $d->toArray(),
                $this->type_deltas,
            ),
            'class_deltas' => [
                'changed' => array_map(fn(ClassDelta $d) => $d->toArray(), $this->class_changes),
                'added' => array_map(fn(ClassDelta $d) => $d->toArray(), $this->class_added),
                'removed' => array_map(fn(ClassDelta $d) => $d->toArray(), $this->class_removed),
            ],
            'findings_diff' => $this->findings_diff->toArray(),
        ];
        if ($this->bin_deltas !== []) {
            $out['bin_deltas'] = array_map(
                fn(BinDelta $d) => $d->toArray(),
                $this->bin_deltas,
            );
        }
        if ($this->region_deltas !== []) {
            $out['region_deltas'] = array_map(
                fn(RegionDelta $d) => $d->toArray(),
                $this->region_deltas,
            );
        }
        if ($this->unaccounted_finding !== null) {
            $out['unaccounted_finding'] = $this->unaccounted_finding->toArray();
        }
        return $out;
    }
}
