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

final class Tag
{
    // phpcs:disable Generic.NamingConventions.UpperCaseConstantName
    public const DW_TAG_array_type = 0x01;
    public const DW_TAG_class_type = 0x02;
    public const DW_TAG_entry_point = 0x03;
    public const DW_TAG_enumeration_type = 0x04;
    public const DW_TAG_formal_parameter = 0x05;
    public const DW_TAG_imported_declaration = 0x08;
    public const DW_TAG_label = 0x0a;
    public const DW_TAG_lexical_block = 0x0b;
    public const DW_TAG_member = 0x0d;
    public const DW_TAG_pointer_type = 0x0f;
    public const DW_TAG_reference_type = 0x10;
    public const DW_TAG_compile_unit = 0x11;
    public const DW_TAG_string_type = 0x12;
    public const DW_TAG_structure_type = 0x13;
    public const DW_TAG_subroutine_type = 0x15;
    public const DW_TAG_typedef = 0x16;
    public const DW_TAG_union_type = 0x17;
    public const DW_TAG_unspecified_parameters = 0x18;
    public const DW_TAG_variant = 0x19;
    public const DW_TAG_common_block = 0x1a;
    public const DW_TAG_common_inclusion = 0x1b;
    public const DW_TAG_inheritance = 0x1c;
    public const DW_TAG_inlined_subroutine = 0x1d;
    public const DW_TAG_module = 0x1e;
    public const DW_TAG_ptr_to_member_type = 0x1f;
    public const DW_TAG_set_type = 0x20;
    public const DW_TAG_subrange_type = 0x21;
    public const DW_TAG_with_stmt = 0x22;
    public const DW_TAG_access_declaration = 0x23;
    public const DW_TAG_base_type = 0x24;
    public const DW_TAG_catch_block = 0x25;
    public const DW_TAG_const_type = 0x26;
    public const DW_TAG_constant = 0x27;
    public const DW_TAG_enumerator = 0x28;
    public const DW_TAG_file_type = 0x29;
    public const DW_TAG_friend = 0x2a;
    public const DW_TAG_namelist = 0x2b;
    public const DW_TAG_namelist_item = 0x2c;
    public const DW_TAG_packed_type = 0x2d;
    public const DW_TAG_subprogram = 0x2e;
    public const DW_TAG_template_type_parameter = 0x2f;
    public const DW_TAG_template_value_parameter = 0x30;
    public const DW_TAG_thrown_type = 0x31;
    public const DW_TAG_try_block = 0x32;
    public const DW_TAG_vriant_part = 0x33;
    public const DW_TAG_variable = 0x34;
    public const DW_TAG_volatile_type = 0x35;
    public const DW_TAG_dwarf_procedure = 0x36;
    public const DW_TAG_restrict_type = 0x37;
    public const DW_TAG_interface_type = 0x38;
    public const DW_TAG_namespace = 0x39;
    public const DW_TAG_imported_module = 0x3a;
    public const DW_TAG_unspecified_type = 0x3b;
    public const DW_TAG_partial_unit = 0x3c;
    public const DW_TAG_imported_unit = 0x3d;
    public const DW_TAG_condition = 0x3f;
    public const DW_TAG_shared_type = 0x40;
    public const DW_TAG_type_unit = 0x41;
    public const DW_TAG_rvalue_reference_type = 0x42;
    public const DW_TAG_template_alias = 0x43;
    public const DW_TAG_coarray_type = 0x44;
    public const DW_TAG_generic_subrange = 0x45;
    public const DW_TAG_dynamic_type = 0x46;
    public const DW_TAG_atomic_type = 0x47;
    public const DW_TAG_call_site = 0x48;
    public const DW_TAG_call_site_parameter = 0x49;
    public const DW_TAG_skeleton_unit = 0x4a;
    public const DW_TAG_immutable_type = 0x4b;
    public const DW_TAG_lo_user = 0x4080;
    public const DW_TAG_hi_user = 0xffff;
    // phpcs:enable Generic.NamingConventions.UpperCaseConstantName
}
