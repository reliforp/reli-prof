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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\CircularReferenceDetector;

final class CircularReferenceDetectorResult
{
    /**
     * @param list<CircularReference> $circular_references
     */
    public function __construct(
        public readonly array $circular_references,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'circular_reference_count' => count($this->circular_references),
            'circular_references' => array_map(
                fn (CircularReference $cr) => $cr->toArray(),
                $this->circular_references,
            ),
        ];
    }
}
