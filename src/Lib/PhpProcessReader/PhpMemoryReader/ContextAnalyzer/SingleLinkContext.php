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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\Process\MemoryLocation;

/**
 * A thin wrapper that exposes a single link from a parent ReferenceContext.
 *
 * Used by ParallelContextAnalyzer so that each forked child processes
 * exactly one top-level branch of the reference graph.
 */
final class SingleLinkContext implements ReferenceContext
{
    public function __construct(
        private string $link_name,
        private ReferenceContext $target,
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return 'SingleLink';
    }

    #[\Override]
    public function add(string $link_name, ReferenceContext $reference_context): void
    {
    }

    /** @return array<string, ReferenceContext> */
    #[\Override]
    public function getLinks(): iterable
    {
        return [$this->link_name => $this->target];
    }

    /** @return iterable<array-key, MemoryLocation> */
    #[\Override]
    public function getLocations(): iterable
    {
        return [];
    }

    #[\Override]
    public function getContexts(): iterable
    {
        return [];
    }

    #[\Override]
    public function releaseLinks(): void
    {
    }
}
