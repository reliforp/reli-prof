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
 * @property ?int $memo_node_id  ContextAnalyzer emit-state memo —
 *     stored directly on the Context as a mutable property so the
 *     hot analyzer path can do `$context->memo_node_id` instead of
 *     a WeakMap lookup. See ReferenceContextDefault for the encoding
 *     (null = unvisited, >= 0 = emitted, < 0 = reserved).
 */
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
}
