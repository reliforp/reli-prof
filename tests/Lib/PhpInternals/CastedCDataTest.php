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

namespace Reli\Lib\PhpInternals;

use FFI;
use Reli\BaseTestCase;
use Reli\Lib\PhpInternals\Types\Zend\ZendClassEntryInfo;
use Reli\Lib\PhpInternals\Types\Zend\ZendOpArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendObjectsStore;
use Reli\Lib\PhpInternals\Types\Zend\ZendRefcountedH;
use Reli\Lib\PhpInternals\Types\Zend\ZendValue;

class CastedCDataTest extends BaseTestCase
{
    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedPropertyAssignment
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     */
    public function testCreateSubViewPreservesRootOwnerAcrossNestedSubviews(): void
    {
        $ffi = FFI::cdef(
            '
            typedef struct { int value; } leaf_t;
            typedef struct { leaf_t leaf; } node_t;
            typedef struct { node_t node; } root_t;
            '
        );
        $root_cdata = $ffi->new('root_t');
        $root_cdata->node->leaf->value = 123;

        $root = new CastedCData($root_cdata, $root_cdata);
        $node = $root->createSubView($root_cdata->node);
        $leaf = $node->createSubView($root_cdata->node->leaf);

        self::assertSame(spl_object_id($root_cdata), spl_object_id($node->raw));
        self::assertSame(spl_object_id($root_cdata), spl_object_id($leaf->raw));
    }

    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedPropertyAssignment
     * @psalm-suppress UndefinedPropertyFetch
     */
    public function testCreateSubViewKeepsBackingBufferAliveAfterParentIsReleased(): void
    {
        $ffi = FFI::cdef(
            '
            typedef struct { int value; } leaf_t;
            typedef struct { leaf_t leaf; } root_t;
            '
        );
        $root_cdata = $ffi->new('root_t');
        $root_cdata->leaf->value = 456;

        $root = new CastedCData($root_cdata, $root_cdata);
        $leaf = $root->createSubView($root_cdata->leaf);

        unset($root);
        unset($root_cdata);
        gc_collect_cycles();

        self::assertSame(456, $leaf->casted->value);
    }

    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedPropertyAssignment
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress ArgumentTypeCoercion
     * @psalm-suppress UndefinedPropertyFetch
     */
    public function testZendValueKeepsSubviewOwnerAlive(): void
    {
        $ffi = FFI::cdef(
            '
            typedef union { long lval; double dval; void *ptr; } zend_value;
            typedef struct { zend_value value; } root_t;
            '
        );
        $root_cdata = $ffi->new('root_t');
        $root_cdata->value->lval = 789;

        $root = new CastedCData($root_cdata, $root_cdata);
        $value = new ZendValue($root->createSubView($root_cdata->value));

        unset($root);
        unset($root_cdata);
        gc_collect_cycles();

        self::assertSame(789, $value->lval);
    }

    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedPropertyAssignment
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress ArgumentTypeCoercion
     * @psalm-suppress UndefinedPropertyFetch
     */
    public function testZendClassEntryInfoKeepsSubviewOwnerAlive(): void
    {
        $ffi = FFI::cdef(
            '
            typedef struct {
                char *filename;
                int line_start;
                int line_end;
                char *doc_comment;
            } zend_class_entry_info_user;
            typedef struct {
                zend_class_entry_info_user user;
            } zend_class_entry_info;
            typedef struct {
                zend_class_entry_info info;
            } root_t;
            '
        );
        $root_cdata = $ffi->new('root_t');
        $root_cdata->info->user->line_start = 12;
        $root_cdata->info->user->line_end = 34;

        $root = new CastedCData($root_cdata, $root_cdata);
        $info = new ZendClassEntryInfo($root->createSubView($root_cdata->info));
        $user = $info->user;

        unset($root);
        unset($info);
        unset($root_cdata);
        gc_collect_cycles();

        self::assertSame(12, $user->line_start);
        self::assertSame(34, $user->line_end);
    }

    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedPropertyAssignment
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress ArgumentTypeCoercion
     * @psalm-suppress UndefinedPropertyFetch
     */
    public function testZendOpArrayKeepsSubviewOwnerAlive(): void
    {
        $ffi = FFI::cdef(
            '
            typedef struct {
                unsigned int fn_flags;
                int line_start;
                int line_end;
            } zend_op_array;
            typedef struct {
                zend_op_array op_array;
            } root_t;
            '
        );
        $root_cdata = $ffi->new('root_t');
        $root_cdata->op_array->line_start = 55;
        $root_cdata->op_array->line_end = 89;

        $root = new CastedCData($root_cdata, $root_cdata);
        $op_array = new ZendOpArray($root->createSubView($root_cdata->op_array));

        unset($root);
        unset($root_cdata);
        gc_collect_cycles();

        self::assertSame(55, $op_array->line_start);
        self::assertSame(89, $op_array->line_end);
    }

    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedPropertyAssignment
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress ArgumentTypeCoercion
     * @psalm-suppress UndefinedPropertyFetch
     */
    public function testZendRefcountedHKeepsSubviewOwnerAlive(): void
    {
        $ffi = FFI::cdef(
            '
            typedef struct { unsigned int type_info; } zend_refcounted_u;
            typedef struct { unsigned int refcount; zend_refcounted_u u; } zend_refcounted_h;
            typedef struct { zend_refcounted_h gc; } root_t;
            '
        );
        $root_cdata = $ffi->new('root_t');
        $root_cdata->gc->refcount = 7;
        $root_cdata->gc->u->type_info = 0x1234;

        $root = new CastedCData($root_cdata, $root_cdata);
        $gc = new ZendRefcountedH($root->createSubView($root_cdata->gc));

        unset($root);
        unset($root_cdata);
        gc_collect_cycles();

        self::assertSame(7, $gc->refcount);
        self::assertSame(0x1234, $gc->type_info);
    }

    /**
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedPropertyAssignment
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress ArgumentTypeCoercion
     * @psalm-suppress UndefinedPropertyFetch
     */
    public function testZendObjectsStoreKeepsSubviewOwnerAlive(): void
    {
        $ffi = FFI::cdef(
            '
            typedef struct {
                unsigned int size;
                unsigned int top;
                unsigned int free_list_head;
                void *object_buckets;
            } zend_objects_store;
            typedef struct {
                zend_objects_store objects_store;
            } root_t;
            '
        );
        $root_cdata = $ffi->new('root_t');
        $root_cdata->objects_store->size = 11;
        $root_cdata->objects_store->top = 22;
        $root_cdata->objects_store->free_list_head = 33;
        $root_cdata->objects_store->object_buckets = null;

        $root = new CastedCData($root_cdata, $root_cdata);
        $store = new ZendObjectsStore($root->createSubView($root_cdata->objects_store));

        unset($root);
        unset($root_cdata);
        gc_collect_cycles();

        self::assertSame(11, $store->size);
        self::assertSame(22, $store->top);
        self::assertSame(33, $store->free_list_head);
    }
}
