<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Dwarf;

final class Form
{
    public const DW_FORM_addr = 0x01; // address
    public const DW_FORM_block2 = 0x03; // block
    public const DW_FORM_block4 = 0x04; // block
    public const DW_FORM_data2 = 0x05; // constant
    public const DW_FORM_data4 = 0x06; // constant
    public const DW_FORM_data8 = 0x07; // constant
    public const DW_FORM_string = 0x08; // string
    public const DW_FORM_block = 0x09; // block
    public const DW_FORM_block1 = 0x0a; // block
    public const DW_FORM_data1 = 0x0b; // constant
    public const DW_FORM_flag = 0x0c; // flag
    public const DW_FORM_sdata = 0x0d; // constant
    public const DW_FORM_strp = 0x0e; // string
    public const DW_FORM_udata = 0x0f; // constant
    public const DW_FORM_ref_addr = 0x10; // reference
    public const DW_FORM_ref1 = 0x11; // reference
    public const DW_FORM_ref2 = 0x12; // reference
    public const DW_FORM_ref4 = 0x13; // reference
    public const DW_FORM_ref8 = 0x14; // reference
    public const DW_FORM_ref_udata = 0x15; // reference
    public const DW_FORM_indirect = 0x16; // (see Section 7.5.3 on page 203)
    public const DW_FORM_sec_offset = 0x17; // addrptr, lineptr, loclist, loclistsptr,macptr, rnglist, rnglistsptr, stroffsetsptr
    public const DW_FORM_exprloc = 0x18; // exprloc
    public const DW_FORM_flag_present = 0x19; // flag
    public const DW_FORM_strx = 0x1a; // string
    public const DW_FORM_addrx = 0x1b; // address
    public const DW_FORM_ref_sup4 = 0x1c; // reference
    public const DW_FORM_strp_sup = 0x1d; // string
    public const DW_FORM_data16 = 0x1e; // constant
    public const DW_FORM_line_strp = 0x1f; // string
    public const DW_FORM_ref_sig8 = 0x20; // reference
    public const DW_FORM_implicit_const = 0x21; // constant
    public const DW_FORM_loclistx = 0x22; // loclist
    public const DW_FORM_rnglistx = 0x23; // rnglist
    public const DW_FORM_ref_sup8 = 0x24; // reference
    public const DW_FORM_strx1 = 0x25; // string
    public const DW_FORM_strx2 = 0x26; // string
    public const DW_FORM_strx3 = 0x27; // string
    public const DW_FORM_strx4 = 0x28; // string
    public const DW_FORM_addrx1 = 0x29; // address
    public const DW_FORM_addrx2 = 0x2a; // address
    public const DW_FORM_addrx3 = 0x2b; // address
    public const DW_FORM_addrx4 = 0x2c; // address
}
