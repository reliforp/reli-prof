<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Refines the global unpack() return type for the format strings actually
 * used by the FastPath readers and PrimitiveReaders. Psalm's CallMap types
 * unpack() as array<array-key, mixed>|false because it does not parse the
 * format string; this stub encodes the documented PHP behaviour for the
 * single-element formats we use, so callers do not need inline @var hints.
 *
 * Buffer-size invariants (which determine the |false branch) are not
 * expressible in PHP's type system; FastPath readers gate the unpack()
 * with a preceding RegionByteProvider::regionFor() call and document the
 * remaining PossiblyInvalidArrayAccess via a localized @psalm-suppress.
 */

namespace {
    /**
     * @psalm-pure
     * @psalm-return (
     *   $format is 'P' ? array{1: int}|false :
     *   ($format is 'l' ? array{1: int}|false :
     *   ($format is 'V' ? array{1: int}|false :
     *   ($format is 'v' ? array{1: int}|false :
     *   ($format is 'C' ? array{1: int}|false :
     *   array<array-key, mixed>|false))))
     * )
     */
    function unpack(string $format, string $string, int $offset = 0): array|false {}
}
