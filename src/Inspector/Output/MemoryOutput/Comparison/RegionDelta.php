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

/**
 * One row of the region-map delta — the design's B.2 deliverable.
 *
 * `change` is one of {@see self::CHANGE_*}. For ADDED / REMOVED rows the
 * "other side" size is 0 (the region only exists on one side); for GROWN /
 * SHRUNK rows both sizes are non-zero and the delta is `target - baseline`.
 *
 * Region maps come from {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionMap}
 * and are persisted to rmem at analyze time so this delta works on the
 * rmem-only workflow without needing both rdumps in hand.
 */
final class RegionDelta
{
    public const CHANGE_ADDED = 'added';
    public const CHANGE_GROWN = 'grown';
    public const CHANGE_SHRUNK = 'shrunk';
    public const CHANGE_REMOVED = 'removed';

    public function __construct(
        public readonly string $kind,
        public readonly int $address,
        public readonly int $baseline_size,
        public readonly int $target_size,
        public readonly int $size_delta,
        public readonly string $change,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'address' => $this->address,
            'baseline_size' => $this->baseline_size,
            'target_size' => $this->target_size,
            'size_delta' => $this->size_delta,
            'change' => $this->change,
        ];
    }
}
