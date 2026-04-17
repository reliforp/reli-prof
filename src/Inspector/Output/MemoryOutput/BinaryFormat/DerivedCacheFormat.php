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

namespace Reli\Inspector\Output\MemoryOutput\BinaryFormat;

/**
 * Constants for the .rmem.derived sidecar cache format.
 *
 * Layout:
 *   [Header 32 bytes] [Section data...] [TOC]
 *
 * All multi-byte integers are little-endian.
 */
final class DerivedCacheFormat
{
    public const MAGIC = "RMDC";
    public const VERSION = 1;

    public const HEADER_SIZE = 32;

    /** Same TOC layout as rmem: 16 (name) + 8 (offset) + 8 (length) + 8 (element_count) */
    public const TOC_ENTRY_SIZE = 40;
    public const TOC_NAME_SIZE = 16;

    // Section names (max 16 bytes)
    public const SECTION_SUBTREE_SIZES = 'subtree_by_idx';
    public const SECTION_SCC_NODE_MAP = 'scc_by_idx';
    public const SECTION_SCC_PROFILES = 'scc_profiles';
}
