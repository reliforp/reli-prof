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
 * This class mirrors the role `ZendArrayTableOverheadMemoryLocation`
 * plays for hash tables: a placeholder for "this address range is
 * reserved by the type itself, not by ZendMM rounding". The
 * placeholder is dropped by
 * `RegionAnalyzer::filterOverlappingLocations` when a concrete
 * location turns up at the same address — opcache, in particular,
 * trims reserved tails when copying class tables and similar
 * structures into its own SHM segment, so what reli reads as
 * "the string's reserved capacity" can legitimately contain
 * unrelated live data; yielding to the real location keeps that
 * case correct.
 */
final class ZendStringReservedCapacityMemoryLocation extends MemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        public ZendStringMemoryLocation $used_location,
    ) {
        parent::__construct($address, $size);
    }

    /**
     * Try to construct a reserved-capacity location for a string by
     * looking up the bin slot it occupies in the chunk index.
     * Returns null when no chunk contains the string (e.g. it lives in
     * a huge allocation that doesn't get bin-slot rounding) or when
     * the slot exactly matches `len + ZEND_STRING_HEADER_SIZE` (no
     * surplus to attribute).
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
