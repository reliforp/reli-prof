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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

use Reli\Lib\Process\MemoryLocation;

/**
 * Lightweight placeholder for a context that was already emitted to DB.
 * Holds only the node_id so the analyzer can emit a reference edge
 * without keeping the full context object in memory.
 */
final class SentinelContext implements ReferenceContext
{
    public function __construct(
        public int $node_id,
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return 'SentinelContext';
    }

    #[\Override]
    public function add(string $link_name, ReferenceContext $reference_context): void
    {
        throw new \LogicException('SentinelContext cannot have children');
    }

    /** @return array<string, ReferenceContext> */
    #[\Override]
    public function getLinks(): iterable
    {
        return [];
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
