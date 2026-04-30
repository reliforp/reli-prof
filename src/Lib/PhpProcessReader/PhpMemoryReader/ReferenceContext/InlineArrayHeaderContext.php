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

/**
 * Structural marker for a HashTable header that is INLINE inside an enclosing
 * struct (e.g. `zend_weakmap = { HashTable ht; zend_object std; }`).
 *
 * Unlike {@see ArrayHeaderContext}, this does not own a
 * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation}
 * — the inline header's bytes are already accounted for by the enclosing
 * struct's location, so emitting one here would double-count them and (more
 * subtly) collide with the parent on `(address)` keying inside
 * `PdoMemoryOutput::computeCanonicalNodeIds` / the binary canonical map. That
 * collision used to close the (object → weak_map → entries-ht) chain into a
 * phantom `cycle_cluster: 1x WeakMap` finding on every WeakMap, including
 * empty ones.
 *
 * Children — `array_elements` (the heap-allocated buckets table) and
 * `possible_unused_area` (its over-allocated tail) — are attached the same
 * way as on {@see ArrayHeaderContext} so report passes and viz code can keep
 * navigating by link name.
 */
final class InlineArrayHeaderContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function getElements(): ?ArrayElementsContext
    {
        /** @var ArrayElementsContext|null */
        return $this->referencing_contexts['array_elements'] ?? null;
    }
}
