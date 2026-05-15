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
    // VERSION 3: invalidate caches built before the
    // FfiCsrGraphSubstrate.loadFromBinary csr-vs-node-id fix. Caches
    // produced against the broken loader stored bogus held_by /
    // defined_at node_ids in srcloc_refs (because the tree-parent walk
    // saw a shifted topology), so recomputing on first open after the
    // fix is required to surface correct source locations.
    public const VERSION = 3;

    public const HEADER_SIZE = 32;

    /** Same TOC layout as rmem: 16 (name) + 8 (offset) + 8 (length) + 8 (element_count) */
    public const TOC_ENTRY_SIZE = 40;
    public const TOC_NAME_SIZE = 16;

    // Section names (max 16 bytes)
    public const SECTION_SUBTREE_SIZES = 'subtree_by_idx';
    public const SECTION_SCC_NODE_MAP = 'scc_by_idx';
    public const SECTION_SCC_PROFILES = 'scc_profiles';

    /**
     * Source location index: packed rows of
     *   u32 node_id | i32 defined_at_nid | i32 held_by_nid
     * (12 bytes each). Only nodes that benefit from indirection are
     * included — nodes that carry a filename attribute themselves are
     * served directly from attributes and don't need a row here.
     */
    public const SECTION_SOURCE_LOC_REFS = 'srcloc_refs';
    public const SOURCE_LOC_REF_ROW_SIZE = 12;
}
