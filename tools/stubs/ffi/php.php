<?php
namespace FFI\PhpInternals;

use FFI\CArray;
use FFI\CData;
use FFI\CInteger;
use FFI\CPointer;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class zend_executor_globals extends CData
{
    public ?CPointer $current_execute_data;
    public ?CPointer $function_table;
    public ?CPointer $class_table;
    public ?CPointer $zend_constants;
    public zend_array $symbol_table;
    public ?CPointer $vm_stack;
    public ?CPointer $vm_stack_top;
    public zend_array $included_files;
    public ?CPointer $ini_directives;
    public ?CPointer $modified_ini_directives;
    public zend_objects_store $objects_store;
}

class zend_compiler_globals extends CData
{
    public ?CPointer $arena;
    public ?CPointer $ast_arena;
    public zend_array $interned_strings;
    public int $map_ptr_base;
}

class zend_arena extends CData
{
    public ?CPointer $ptr;
    public ?CPointer $end;
    public ?CPointer $prev;
}

class zend_closure extends CData
{
    public zend_object $std;
    public zend_function $func;
    public zval $this_ptr;
    public ?CPointer $called_scope;
}

class zend_constants extends CData
{
    public ?CPointer $name;
    public zval $value;
    public int $type;
    public int $flags;
    public ?CPointer $module;
    public ?CPointer $doc_comment;
}

class zend_execute_data extends CData
{
    public ?CPointer $opline;
    public ?CPointer $func;
    public ?CPointer $prev_execute_data;
    public zval $This;
    public ?CPointer $symbol_table;
    public ?CPointer $extra_named_params;
}

class zend_op extends CData
{
    public CInteger $op1;
    public CInteger $op2;
    public CInteger $result;
    public int $op1_type;
    public int $op2_type;
    public int $opcode;
    public int $result_type;
    public int $extended_value;
    public int $lineno;
}

class zend_fiber extends CData
{
}

class zend_fiber_context extends CData
{
}

class zend_function extends CData
{
    public int $type;
    public zend_function_common $common;
    public zend_op_array $op_array;
}

class zend_live_range extends CData
{
    public int $var;
    public int $start;
    public int $end;
}

class zend_mm_chunk extends CData
{
    public ?CPointer $heap;
    public ?CPointer $prev;
    public ?CPointer $next;
    public int $num;
    public zend_mm_heap $heap_slot;
    public int $free_pages;
    public zend_mm_page_map $map;
}
class zend_mm_heap extends CData
{
    public int $use_custom_heap;
    public int $size;
    public int $peak;
    public int $real_size;
    public int $real_peak;
    public int $limit;
    public int $overflow;
    public ?CPointer $huge_list;
    public ?CPointer $main_chunk;
    public int $chunks_count;
    public int $peak_chunks_count;
    public int $cached_chunks_count;
}
class zend_mm_huge_list extends CData
{
    public int $ptr;
    public int $size;
    public ?CPointer $next;
}

/** @implements \ArrayAccess<int, int> */
class zend_mm_page_map extends CData implements \ArrayAccess
{
    public function offsetExists($offset): bool
    {
    }
    public function offsetGet($offset): int
    {
    }
}

class zend_object extends CData
{
    public zend_refcounted_h $gc;
    public int $handle;
    public ?CPointer $properties;
    public ?CPointer $ce;
    public ?CPointer $handlers;
    public array $properties_table;
}
class zend_objects_store extends CData
{
    public int $top;
    public int $size;
    public int $free_list_head;
    public ?CPointer $object_buckets;
}

class zend_op_array extends CData
{
    public int $fn_flags;
    public ?CPointer $filename;
    public int $num_args;
    public ?CPointer $arg_info;
    public ?CPointer $refcount;
    public int $this_var;
    public ?CPointer $scope;
    public ?CPointer $opcodes;
    public ?CPointer $prototype;
    public ?CPointer $doc_comment;
    public int $last_live_range;
    public ?CPointer $live_range;
    public int $num_dynamic_func_defs;
    public ?CPointer $dynamic_func_defs;
    public int $last;
    public int $last_var;
    public int $T;
    public ?CPointer $vars;
    public ?CPointer $literals;
    public ?CPointer $static_variables;
    public int $line_start;
    public int $line_end;
    public int $last_literal;
    public int $cache_size;
    public ?CPointer $run_time_cache;
    public ?CPointer $run_time_cache__ptr;
}

class zend_function_common extends CData
{
    public ?CPointer $function_name;
    public ?CPointer $scope;
    public int $fn_flags;
    public int $num_args;
}

class zend_string extends CData
{
    public zend_refcounted_h $gc;

    /** @var int */
    public int $h;

    /** @var int */
    public int $len;

    /** @var CPointer*/
    public CData $val;
}

class zend_property_info extends CData
{
    public int $offset;
    public int $flags;
    public ?CPointer $name;
    public ?CPointer $doc_comment;
}

class zend_refcounted_h extends CData
{
    public int $refcount;

    public zend_refcounted_h_u $u;
}

class zend_refcounted_h_u extends CData
{
    public int $type_info;
}

class zend_class_entry extends CData
{
    public string $type;
    public int $num_interfaces;
    public int $num_traits;
    public int $default_properties_count;
    public int $default_static_members_count;
    public ?CPointer $default_static_members_table;
    public ?CPointer $static_members_table;
    public ?CPointer $static_members_table__ptr;
    public ?CPointer $default_properties_table;
    public zend_array $function_table;
    public zend_array $constants_table;
    public zend_array $properties_info;
    public int $ce_flags;
    public CPointer $name;
    public zend_class_entry_info $info;
}

class zend_class_entry_info extends CData
{
    public zend_class_entry_info_user $user;
}

class zend_class_entry_info_user extends CData
{
    public ?CPointer $filename;
    public int $line_start;
    public int $line_end;
    public ?CPointer $doc_comment;
}

class zend_class_constant extends CData
{
    public zval $value;
    public ?CPointer $doc_comment;
    public ?CPointer $attributes;
    public ?CPointer $ce;
    public zend_type $type;
}
class zend_reference extends CData
{
    public zend_refcounted_h $gc;
    public zval $val;
}

class zend_resource extends CData
{
    public zend_refcounted_h $gc;
    public int $handle;
    public int $type;
}
class zend_type extends CData
{
    public int $ptr;
    public int $type_mask;
}

class zend_value_ww extends CData
{
    public int $w1;
    public int $w2;
}

class zend_value extends CData
{
    public int $lval;
    public float $dval;
    public ?CPointer $counted;
    public ?CPointer $arr;
    public ?CPointer $str;
    public ?CPointer $obj;
    public ?CPointer $res;
    public ?CPointer $ref;
    public ?CPointer $ast;
    public ?CPointer $zv;
    public ?CPointer $ptr;
    public ?CPointer $ce;
    public ?CPointer $func;
    public zend_value_ww $ww;
}

class zval_u1_u extends CData
{
    public int $extra;
}

class zval_u1_v extends CData
{
    public int $type;
    public int $type_flags;
    public zval_u1_u $u;
}

class zval_u1 extends CData
{
    public int $type_info;
    public zval_u1_v $v;
}

class zval_u2 extends CData
{
    public int $next;
    public int $opline_num;
    public int $lineno;
    public int $num_args;
    public int $fe_pos;
    public int $fe_iter_idx;
    public int $access_flags;
    public int $property_guard;
    public int $constant_flags;
    public int $extra;
}

class zend_vm_stack extends CData
{
    public ?CPointer $top;
    public ?CPointer $end;
    public ?CPointer $prev;
}

class zval extends CData
{
    public zend_value $value;
    public zval_u1 $u1;
    public zval_u2 $u2;
}

/** @implements \ArrayAccess<int, zval> */
class zval_array extends CData implements \ArrayAccess
{
    public function offsetExists($offset): bool
    {
    }
    public function offsetGet($offset): zval
    {
    }
}

class Bucket extends CData
{
    public zval $val;
    public int $h;
    public ?CPointer $key;
}

class zend_module_entry extends CData
{
    public int $zts;
    public ?CPointer $version;
}

class zend_hash_func_ffi extends \FFI
{
    public function zend_hash_func(string $str, int $len): int {}
}

class zend_array_v extends CData
{
    public int $flags;
    public int $nApplyCount;
    public int $nIteratorsCount;
    public int $consistency;
}

class zend_array_u extends CData
{
    public int $flags;
    public zend_array_v $v;

}

class zend_arg_info extends CData
{
    public ?CPointer $name;
    public ?CPointer $type;
}

class zend_array extends CData
{
    public zend_refcounted_h $gc;
    public zend_array_u $u;
    public int $nTableMask;
    public ?CPointer $arData;
    public ?CPointer $arPacked;
    public int $nNumUsed;
    public int $nNumOfElements;
    public int $nTableSize;
    public int $nInternalPointer;
    public int $nNextFreeElement;
}

class sapi_globals_struct extends CData
{
    public float $global_request_time;
}