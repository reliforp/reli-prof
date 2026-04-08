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

final class SummaryDelta
{
    public function __construct(
        public readonly string $metric,
        public readonly int|float $baseline,
        public readonly int|float $target,
        public readonly int|float $delta,
        public readonly ?float $delta_percent,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'metric' => $this->metric,
            'baseline' => $this->baseline,
            'target' => $this->target,
            'delta' => $this->delta,
        ];
        if ($this->delta_percent !== null) {
            $result['delta_percent'] = $this->delta_percent;
        }
        return $result;
    }
}
