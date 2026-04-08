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

final class FindingsDiff
{
    /**
     * @param list<Finding> $new
     * @param list<Finding> $resolved
     * @param list<array{baseline: Finding, target: Finding}> $changed
     */
    public function __construct(
        public readonly array $new,
        public readonly array $resolved,
        public readonly array $changed,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'new' => array_map(fn(Finding $f) => $f->toArray(), $this->new),
            'resolved' => array_map(fn(Finding $f) => $f->toArray(), $this->resolved),
            'changed' => array_map(
                fn(array $pair) => [
                    'baseline' => $pair['baseline']->toArray(),
                    'target' => $pair['target']->toArray(),
                ],
                $this->changed,
            ),
        ];
    }
}
