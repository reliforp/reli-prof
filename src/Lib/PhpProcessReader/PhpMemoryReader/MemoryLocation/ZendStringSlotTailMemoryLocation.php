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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use Reli\Lib\Process\MemoryLocation;

/**
 * The tail of a `zend_string`'s ZendMM slot that lies beyond
 * `len + ZEND_STRING_HEADER_SIZE` (i.e. beyond
 * `ZendStringMemoryLocation::size`).
 *
 * `zend_string` only persists `len`, not the buffer's allocated
 * capacity, but PHP's rope/realloc-friendly call paths can ask
 * ZendMM for a slot bigger than the active content needs and keep
 * the surplus capacity attached after the writer settles on a
 * shorter `len`. Without a dedicated location for that tail the
 * over-allocated bytes are handed off to the generic
 * `ZendMmChunkMemoryLocation::getOverhead` and end up booked as
 * ZendMM slot-rounding overhead — biasing the `ZendString` row in
 * the type breakdown downward and inflating
 * `allocation_overhead`.
 *
 * The honest framing is "string-attributable slot tail", not
 * "reserved capacity": from a `zend_string` we can't tell how
 * much of `bin_size − (len + 24)` is PHP-reserved growth room
 * and how much is ZendMM bin rounding / alignment slack. The
 * tail just gets attributed to the string instead of to the
 * generic ZendMM overhead bucket, which is the corrective the
 * report needs.
 *
 * Mirrors the role `ZendArrayTableOverheadMemoryLocation`
 * plays for hash tables, with one design difference: the array
 * placeholder is THE reserved-tail-only — slot rounding for the
 * whole table is computed afterwards via
 * `ZendMmChunkMemoryLocation::getOverhead`'s special case. For
 * strings, the slot tail already represents
 * `bin_size − (len + 24)`, so there is no further rounding
 * layer to compute; `RegionAnalyzer::analyze` skips the
 * placeholder from the generic `getOverhead` call entirely.
 *
 * The placeholder is also dropped by
 * `RegionAnalyzer::filterOverlappingLocations` (and by
 * `MemoryLocations::add`) when a concrete location turns up at
 * the same address — opcache, in particular, trims reserved
 * tails when copying class tables and similar structures into
 * its own SHM segment, so what reli reads as the string's slot
 * tail can legitimately contain unrelated live data; yielding
 * to the real location keeps that case correct.
 */
final class ZendStringSlotTailMemoryLocation extends MemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        public ZendStringMemoryLocation $used_location,
    ) {
        parent::__construct($address, $size);
    }

    /**
     * Try to construct a slot-tail location for a string by looking
     * up the bin slot it occupies in the chunk index. Returns null
     * when no chunk contains the string (e.g. it lives in a huge
     * allocation that doesn't get bin-slot rounding) or when the
     * slot exactly matches `len + ZEND_STRING_HEADER_SIZE` (no
     * tail bytes to attribute).
     */
    public static function tryFromStringInChunks(
        ZendStringMemoryLocation $string_location,
        ?MemoryLocations $chunk_memory_locations,
    ): ?self {
        if ($chunk_memory_locations === null) {
            return null;
        }
        $chunk = $chunk_memory_locations->getContainingMemoryLocation($string_location);
        if (!$chunk instanceof ZendMmChunkMemoryLocation) {
            return null;
        }
        $overhead = $chunk->getOverhead($string_location);
        if ($overhead === null || $overhead->size === 0) {
            return null;
        }
        return new self(
            $overhead->address,
            $overhead->size,
            $string_location,
        );
    }
}
