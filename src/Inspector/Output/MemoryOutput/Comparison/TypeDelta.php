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

final class TypeDelta
{
    public function __construct(
        public readonly string $type,
        public readonly int $baseline_count,
        public readonly int $target_count,
        public readonly int $baseline_memory,
        public readonly int $target_memory,
        public readonly int $count_delta,
        public readonly int $memory_delta,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'baseline_count' => $this->baseline_count,
            'target_count' => $this->target_count,
            'baseline_memory' => $this->baseline_memory,
            'target_memory' => $this->target_memory,
            'count_delta' => $this->count_delta,
            'memory_delta' => $this->memory_delta,
        ];
    }
}
