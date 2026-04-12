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

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

/**
 * Strategy for populating the shared {@see LinkNameResolver} that backs
 * the report passes' tree-edge lookups.
 *
 * - {@see self::Auto}  decides per database based on edge count: small
 *   graphs get a single bulk read (fast), huge graphs fall back to
 *   bounded lazy lookups (memory-safe). The default.
 * - {@see self::Eager} forces the bulk read regardless of edge count.
 *   Pick this when memory is plentiful and you want the report as fast
 *   as possible.
 * - {@see self::Lazy}  forces the per-edge prepared-statement path with
 *   a bounded cache. Pick this when memory is tight and you would rather
 *   trade speed for a flat-line memory footprint, even on smaller graphs.
 */
enum LinkCacheMode: string
{
    case Auto = 'auto';
    case Eager = 'eager';
    case Lazy = 'lazy';
}
