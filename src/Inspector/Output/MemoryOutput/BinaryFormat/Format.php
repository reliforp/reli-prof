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
 * Constants for the .rmem binary intermediate format.
 *
 * Layout:
 *   [Header 64 bytes] [Section TOC] [Section data...]
 *
 * All multi-byte integers are little-endian.
 */
final class Format
{
    public const MAGIC = "RMEM";
    public const VERSION = 1;

    /** Header is exactly 64 bytes */
    public const HEADER_SIZE = 64;

    /** Each TOC entry is 40 bytes: 16 (name) + 8 (offset) + 8 (length) + 8 (element_count) */
    public const TOC_ENTRY_SIZE = 40;
    public const TOC_NAME_SIZE = 16;

    // Flag bits in the header flags field
    public const FLAG_LITTLE_ENDIAN = 0x01;

    // Section names (max 16 bytes, null-padded)
    public const SECTION_STRING_DICT = 'string_dict';
    public const SECTION_NODES = 'nodes';
    public const SECTION_EDGES = 'edges';
    public const SECTION_LOCATIONS = 'locations';
    public const SECTION_ATTRIBUTES = 'attributes';
    public const SECTION_SUMMARY = 'summary';
    public const SECTION_RUNS = 'runs';

    // Row sizes (bytes)
    public const NODE_ROW_SIZE = 16;
    public const EDGE_ROW_SIZE = 16;
    public const LOCATION_ROW_SIZE = 48;

    // Sentinel for NULL string_value_id in locations
    public const NULL_STRING_ID = 0xFFFFFFFF;
}
