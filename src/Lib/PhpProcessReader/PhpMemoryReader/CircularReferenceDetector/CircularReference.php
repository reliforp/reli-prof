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

final class CircularReference
{
    /**
     * @param list<CircularReferenceNode> $path
     */
    public function __construct(
        public readonly array $path,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cycle_length' => count($this->path),
            'total_size' => array_sum(array_map(fn (CircularReferenceNode $n) => $n->size, $this->path)),
            'path' => array_map(fn (CircularReferenceNode $n) => $n->toArray(), $this->path),
        ];
    }
}
