<?php
namespace FFI\PhpInternals;

use FFI\CArray;
use FFI\CData;

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class zend_executor_globals extends CData
{
    public zend_execute_data $current_execute_data;
}

class zend_execute_data extends CData
{
    public zend_op $opline;
    public zend_function $func;
    public zend_execute_data $prev_execute_data;
}

class zend_op extends CData
{
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
    public zend_string $filename;
}

class zend_function_common extends CData
{
    public zend_string $function_name;
    public zend_class_entry $scope;
}

class zend_string extends CData
{
    public zend_refcounted_h $gc;

    /** @var int */
    public int $h;

    /** @var int */
    public int $len;

    /** @var CData|CArray */
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
    public zend_string $name;
}

class zend_module_entry extends CData
{
    public int $version; // pointer
}
