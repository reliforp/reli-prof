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

interface ReferenceContext
{
    public function getName(): string;

    public function add(string $link_name, self|int $reference_context): void;

    /** @return iterable<string, self|int> */
    public function getLinks(): iterable;

    /** @return iterable<array-key, MemoryLocation> */
    public function getLocations(): iterable;

    public function getContexts(): iterable;

    public function getLinkStrength(string $link_name): EdgeStrength;

    /** Release child references to allow GC of traversed subtrees */
    public function releaseLinks(): void;

    /**
     * ContextAnalyzer emit-state memo. The slot used to be a
     * `WeakMap<ReferenceContext, int>` keyed by Context object,
     * which showed up as a visible analyzer self-time hot spot
     * (spl_object_id + bucket walk). Moving the slot onto the
     * Context itself makes the lookup a single instance read.
     *
     * Encoding (matches the WeakMap encoding the analyzer used to
     * carry):
     *
     *   null    ... not yet visited
     *   >= 0    ... fully emitted, reuse as the node_id when
     *               creating reference edges
     *   < 0     ... reserved via assignNodeId() but not yet
     *               emitted; decoded as `-$value - 1`
     *
     * Implementations are encouraged to back this with a private
     * int property and trivial accessors (see ReferenceContextDefault)
     * so the JIT can inline the call. SentinelContext may return null
     * unconditionally, which is what the analyzer treats as
     * "unknown — fall back to its own placeholder logic".
     */
    public function getMemoNodeId(): ?int;

    public function setMemoNodeId(?int $node_id): void;
}
