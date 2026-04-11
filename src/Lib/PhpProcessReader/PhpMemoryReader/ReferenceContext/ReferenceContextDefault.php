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

/** @psalm-require-implements ReferenceContext */
trait ReferenceContextDefault
{
    /** @var array<string, ReferenceContext|int> */
    private array $referencing_contexts = [];

    /** @var list<MemoryLocation> */
    private array $extra_locations = [];

    /**
     * Emit-state memo for {@see ContextAnalyzer}. Values are
     * encoded the same way the old WeakMap<ReferenceContext, int>
     * used to encode them:
     *
     *   null    ... not yet visited
     *   >= 0    ... fully emitted, use as node_id when creating
     *               reference edges
     *   < 0     ... reserved via assignNodeId() but not yet
     *               emitted; decoded as `-$memo_node_id - 1`
     *
     * Living on the Context itself means looking up the memo is
     * a single property read (no WeakMap spl_object_id hash +
     * bucket walk). The Context lifetime matches the WeakMap
     * lifetime exactly — when the Context is GC'd the memo
     * vanishes with it — so there's no extra cleanup to do.
     */
    public ?int $memo_node_id = null;

    public function getName(): string
    {
        return (new \ReflectionClass(static::class))->getShortName();
    }

    public function add(string $link_name, ReferenceContext|int $reference_context): void
    {
        $this->referencing_contexts[$link_name] = $reference_context;
    }

    public function addLocation(MemoryLocation $location): void
    {
        $this->extra_locations[] = $location;
    }

    /** @return array<string, ReferenceContext|int> */
    public function getLinks(): iterable
    {
        return $this->referencing_contexts;
    }

    /** @return iterable<array-key, MemoryLocation> */
    public function getLocations(): iterable
    {
        return $this->extra_locations;
    }

    public function getContexts(): iterable
    {
        return [];
    }

    public function getLinkStrength(string $link_name): EdgeStrength
    {
        return EdgeStrength::Strong;
    }

    public function releaseLinks(): void
    {
        $this->referencing_contexts = [];
    }
}
