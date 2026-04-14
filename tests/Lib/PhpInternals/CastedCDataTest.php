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
}
