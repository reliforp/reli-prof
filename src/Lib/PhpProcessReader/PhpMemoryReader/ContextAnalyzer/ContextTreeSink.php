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

use Reli\Lib\Process\MemoryLocation;

interface ContextTreeSink
{
    /**
     * @param iterable<array-key, MemoryLocation> $locations
     * @param array<string, mixed> $attributes
     */
    public function emitNode(
        int $node_id,
        ?int $parent_node_id,
        string $link_name,
        string $type,
        iterable $locations,
        array $attributes,
    ): void;

    public function emitReference(
        int $reference_node_id,
        ?int $parent_node_id,
        string $link_name,
    ): void;

    /** Whether traversed context nodes can release their child references for GC */
    public function allowsRelease(): bool;
}
