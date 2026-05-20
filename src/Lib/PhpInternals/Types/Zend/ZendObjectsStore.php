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

use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\C\PointerArray;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendObjectsStore
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $size;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $top;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $free_list_head;

    /** @var Pointer<PointerArray>|null */
    public ?Pointer $object_buckets;

    /** @param CastedCData<\FFI\PhpInternals\zend_objects_store> $cdata */
    public function __construct(
        private CastedCData $cdata,
    ) {
        unset($this->size);
        unset($this->top);
        unset($this->free_list_head);
        unset($this->object_buckets);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'size' => $this->size = $this->cdata->casted->size,
            'top' => $this->top = $this->cdata->casted->top,
            'free_list_head' => $this->free_list_head = $this->cdata->casted->free_list_head,
            'object_buckets' => $this->object_buckets = $this->cdata->casted->object_buckets !== null
                ? new Pointer(
                    PointerArray::class,
                    FFIHelper::castPointerToInt($this->cdata->casted->object_buckets),
                    // Cover only slots [0..top): slots [top..size) are
                    // untouched (handle never assigned), so the dump's
                    // pagemap-residency filter excludes those pages and a
                    // full-capacity deref fails with
                    // MemoryAddressNotInDumpException. The job runner then
                    // silently swallows the exception, EmitObjectsStoreJob
                    // never pushes the iterator, and every object that
                    // is only reachable through the iter path (typical
                    // leak shape: live in objects_store but unreachable
                    // from globals/locals/etc.) becomes orphan.
                    $this->cdata->casted->top * 8,
                )
                : null
            ,
        };
    }
}
