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

class Attribute
{
    // phpcs:disable Generic.NamingConventions.UpperCaseConstantName
    public const DW_AT_sibling = 0x01; //  reference
    public const DW_AT_location = 0x02; //  exprloc, loclist
    public const DW_AT_name = 0x03; //  string
    public const DW_AT_ordering = 0x09; //  constant
    public const DW_AT_byte_size = 0x0b; //  constant, exprloc, reference
    public const DW_AT_bit_size = 0x0d; //  constant, exprloc, reference
    public const DW_AT_stmt_list = 0x10; //  lineptr
    public const DW_AT_low_pc = 0x11; //  address
    public const DW_AT_high_pc = 0x12; //  address, constant
    public const DW_AT_language = 0x13; //  constant
    public const DW_AT_discr = 0x15; //  reference
    public const DW_AT_discr_value = 0x16; //  constant
    public const DW_AT_visibility = 0x17; //  constant
    public const DW_AT_import = 0x18; //  reference
    public const DW_AT_string_length = 0x19; //  exprloc, loclist, reference
    public const DW_AT_common_reference = 0x1a; //  reference
    public const DW_AT_comp_dir = 0x1b; //  string
    public const DW_AT_const_value = 0x1c; //  block, constant, string
    public const DW_AT_containing_type = 0x1d; //  reference
    public const DW_AT_default_value = 0x1e; //  constant, reference, flag
    public const DW_AT_inline = 0x20; //  constant
    public const DW_AT_is_optional = 0x21; //  flag
    public const DW_AT_lower_bound = 0x22; //  constant, exprloc, reference
    public const DW_AT_producer = 0x25; //  string
    public const DW_AT_prototyped = 0x27; //  flag
    public const DW_AT_return_addr = 0x2a; //  exprloc, loclist
    public const DW_AT_start_scope = 0x2c; //  constant, rnglist
    public const DW_AT_bit_stride = 0x2e; //  constant, exprloc, reference
    public const DW_AT_upper_bound = 0x2f; //  constant, exprloc, reference
    public const DW_AT_abstract_origin = 0x31; //  reference
    public const DW_AT_accessibility = 0x32; //  constant
    public const DW_AT_address_class = 0x33; //  constant
    public const DW_AT_artificial = 0x34; //  flag
    public const DW_AT_base_types = 0x35; //  reference
    public const DW_AT_calling_convention = 0x36; //  constant
    public const DW_AT_count = 0x37; //  constant, exprloc, reference
    public const DW_AT_data_member_location = 0x38; //  constant, exprloc, loclist
    public const DW_AT_decl_column = 0x39; //  constant
    public const DW_AT_decl_file = 0x3a; //  constant
    public const DW_AT_decl_line = 0x3b; //  constant
    public const DW_AT_declaration = 0x3c; //  flag
    public const DW_AT_discr_list = 0x3d; //  block
    public const DW_AT_encoding = 0x3e; //  constant
    public const DW_AT_external = 0x3f; //  flag
    public const DW_AT_frame_base = 0x40; //  exprloc, loclist
    public const DW_AT_friend = 0x41; //  reference
    public const DW_AT_identifier_case = 0x42; //  constant
    public const DW_AT_namelist_item = 0x44; //  reference
    public const DW_AT_priority = 0x45; //  reference
    public const DW_AT_segment = 0x46; //  exprloc, loclist
    public const DW_AT_specification = 0x47; //  reference
    public const DW_AT_static_link = 0x48; //  exprloc, loclist
    public const DW_AT_type = 0x49; //  reference
    public const DW_AT_use_location = 0x4a; //  exprloc, loclist
    public const DW_AT_variable_parameter = 0x4b; //  flag
    public const DW_AT_virtuality = 0x4c; //  constant
    public const DW_AT_vtable_elem_location = 0x4d; //  exprloc, loclist
    public const DW_AT_allocated = 0x4e; //  constant, exprloc, reference
    public const DW_AT_associated = 0x4f; //  constant, exprloc, reference
    public const DW_AT_data_location = 0x50; //  exprloc
    public const DW_AT_byte_stride = 0x51; //  constant, exprloc, reference
    public const DW_AT_entry_pc = 0x52; //  address, constant
    public const DW_AT_use_UTF8 = 0x53; //  flag
    public const DW_AT_extension = 0x54; //  reference
    public const DW_AT_ranges = 0x55; //  rnglist
    public const DW_AT_trampoline = 0x56; //  address, flag, reference, string
    public const DW_AT_call_column = 0x57; //  constant
    public const DW_AT_call_file = 0x58; //  constant
    public const DW_AT_call_line = 0x59; //  constant
    public const DW_AT_description = 0x5a; //  string
    public const DW_AT_binary_scale = 0x5b; //  constant
    public const DW_AT_decimal_scale = 0x5c; //  constant
    public const DW_AT_small = 0x5d; //  reference
    public const DW_AT_decimal_sign = 0x5e; //  constant
    public const DW_AT_digit_count = 0x5f; //  constant
    public const DW_AT_picture_string = 0x60; //  string
    public const DW_AT_mutable = 0x61; //  flag
    public const DW_AT_threads_scaled = 0x62; //  flag
    public const DW_AT_explicit = 0x63; //  flag
    public const DW_AT_object_pointer = 0x64; //  reference
    public const DW_AT_endianity = 0x65; //  constant
    public const DW_AT_elemental = 0x66; //  flag
    public const DW_AT_pure = 0x67; //  flag
    public const DW_AT_recursive = 0x68; //  flag
    public const DW_AT_signature = 0x69; //  reference
    public const DW_AT_main_subprogram = 0x6a; //  flag
    public const DW_AT_data_bit_offset = 0x6b; //  constant
    public const DW_AT_const_expr = 0x6c; //  flag
    public const DW_AT_enum_class = 0x6d; //  flag
    public const DW_AT_linkage_name = 0x6e; //  string
    public const DW_AT_string_length_bit_size = 0x6f; //  constant
    public const DW_AT_string_length_byte_size = 0x70; //  constant
    public const DW_AT_rank = 0x71; //  constant, exprloc
    public const DW_AT_str_offsets_base = 0x72; //  stroffsetsptr
    public const DW_AT_addr_base = 0x73; //  addrptr
    public const DW_AT_rnglists_base = 0x74; //  rnglistsptr
    public const DW_AT_dwo_name = 0x76; //  string
    public const DW_AT_reference = 0x77; //  flag
    public const DW_AT_rvalue_reference = 0x78; //  flag
    public const DW_AT_macros = 0x79; //  macptr
    public const DW_AT_call_all_calls = 0x7a; //  flag
    public const DW_AT_call_all_source_calls = 0x7b; //  flag
    public const DW_AT_call_all_tail_calls = 0x7c; //  flag
    public const DW_AT_call_return_pc = 0x7d; //  address
    public const DW_AT_call_value = 0x7e; //  exprloc
    public const DW_AT_call_origin = 0x7f; //  exprloc
    public const DW_AT_call_parameter = 0x80; //  reference
    public const DW_AT_call_pc = 0x81; //  address
    public const DW_AT_call_tail_call = 0x82; //  flag
    public const DW_AT_call_target = 0x83; //  exprloc
    public const DW_AT_call_target_clobbered = 0x84; //  exprloc
    public const DW_AT_call_data_location = 0x85; //  exprloc
    public const DW_AT_call_data_value = 0x86; //  exprloc
    public const DW_AT_noreturn = 0x87; //  flag
    public const DW_AT_alignment = 0x88; //  constant
    public const DW_AT_export_symbols = 0x89; //  flag
    public const DW_AT_deleted = 0x8a; //  flag
    public const DW_AT_defaulted = 0x8b; //  constant
    public const DW_AT_loclists_base = 0x8c; //  loclistsptr
    public const DW_AT_lo_user = 0x2000;
    public const DW_AT_hi_user = 0x3fff;
    // phpcs:enable Generic.NamingConventions.UpperCaseConstantName

    public function __construct(
        public int $name,
        public Form $form,
    ) {
    }
}
