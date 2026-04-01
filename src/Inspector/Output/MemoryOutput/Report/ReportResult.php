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

namespace Reli\Inspector\Output\MemoryOutput\Report;

final class ReportResult
{
    /**
     * @param array<string, mixed> $meta
     * @param list<Finding> $findings
     */
    public function __construct(
        public readonly array $meta,
        public readonly array $findings,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'findings' => array_map(
                fn(Finding $f) => $f->toArray(),
                $this->findings,
            ),
        ];
    }
}
