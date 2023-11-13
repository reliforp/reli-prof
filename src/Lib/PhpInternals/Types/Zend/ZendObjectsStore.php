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

namespace Reli\Lib\PhpInternals\Types\Zend;

use FFI\CData;
use Reli\Lib\FFI\Cast;
use Reli\Lib\PhpInternals\Types\C\PointerArray;
use Reli\Lib\Process\Pointer\Pointer;

class ZendObjectsStore
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $size;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $top;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $free_list_head;

    /** @var Pointer<PointerArray>|null */
    public ?Pointer $object_buckets;

    /** @param \FFI\PhpInternals\zend_objects_store $cdata */
    public function __construct(
        private CData $cdata,
    ) {
        unset($this->size);
        unset($this->top);
        unset($this->free_list_head);
        unset($this->object_buckets);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'size' => $this->size = $this->cdata->size,
            'top' => $this->top = $this->cdata->top,
            'free_list_head' => $this->free_list_head = $this->cdata->free_list_head,
            'object_buckets' => $this->object_buckets = $this->cdata->object_buckets !== null
                ? new Pointer(
                    PointerArray::class,
                    Cast::castPointerToInt($this->cdata->object_buckets),
                    $this->cdata->size * 8,
                )
                : null
            ,
        };
    }
}
