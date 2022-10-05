<?php
namespace FFI\PhpInternals;

use FFI\CArray;
use FFI\CData;
use FFI\CInteger;
use FFI\CPointer;
use PhpProfiler\Lib\Process\Pointer\Pointer;

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
}

class zend_execute_data extends CData
{
    public ?CPointer $opline;
    public ?CPointer $func;
    public ?CPointer $prev_execute_data;
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

class zend_function extends CData
{
    public int $type;
    public zend_function_common $common;
    public zend_op_array $op_array;
}

class zend_op_array extends CData
{
    public ?CPointer $filename;
}

class zend_function_common extends CData
{
    public ?CPointer $function_name;
    public ?CPointer $scope;
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
    public CPointer $name;
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
    public CPointer $counted;
    public CPointer $str;
    public CPointer $obj;
    public CPointer $res;
    public CPointer $ref;
    public CPointer $ast;
    public CPointer $zv;
    public CPointer $ptr;
    public CPointer $ce;
    public CPointer $func;
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

class zval extends CData
{
    public zend_value $value;
    public zval_u1 $u1;
    public zval_u2 $u2;
}

class Bucket extends CData
{
    public zval $val;
    public int $h;
    public CPointer $key;
}

class zend_module_entry extends CData
{
    public int $zts;
    public CPointer $version;
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

class zend_array extends CData
{
    public zend_array_u $u;
    public int $nTableMask;
    public CPointer $arData;
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