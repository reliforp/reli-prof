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

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

/** @psalm-consistent-constructor */
class ZendMmHeap implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $use_custom_heap;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $size;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $peak;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $real_size;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $real_peak;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $limit;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $overflow;

    /** @var Pointer<ZendMmHugeList>|null */
    public ?Pointer $huge_list;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendMmChunk>|null
     */
    public ?Pointer $main_chunk;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $chunks_count;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $peak_chunks_count;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $cached_chunks_count;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_mm_heap> $casted_cdata
     * @param Pointer<ZendMmHeap> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->use_custom_heap);
        unset($this->size);
        unset($this->peak);
        unset($this->real_size);
        unset($this->real_peak);
        unset($this->limit);
        unset($this->overflow);
        unset($this->huge_list);
        unset($this->main_chunk);
        unset($this->chunks_count);
        unset($this->peak_chunks_count);
        unset($this->cached_chunks_count);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'use_custom_heap' => $this->use_custom_heap = $this->casted_cdata->casted->use_custom_heap,
            'size' => $this->size = $this->casted_cdata->casted->size,
            'peak' => $this->peak = $this->casted_cdata->casted->peak,
            'real_size' => $this->real_size = $this->casted_cdata->casted->real_size,
            'real_peak' => $this->real_peak = $this->casted_cdata->casted->real_peak,
            'limit' => $this->limit = $this->casted_cdata->casted->limit,
            'overflow' => $this->overflow = $this->casted_cdata->casted->overflow,
            'huge_list' => $this->huge_list = $this->casted_cdata->casted->huge_list !== null
                ? Pointer::fromCData(
                    ZendMmHugeList::class,
                    $this->casted_cdata->casted->huge_list,
                )
                : null
            ,
            'main_chunk' => $this->main_chunk = $this->casted_cdata->casted->main_chunk !== null
                ? Pointer::fromCData(
                    ZendMmChunk::class,
                    $this->casted_cdata->casted->main_chunk,
                )
                : null
            ,
            'chunks_count' => $this->chunks_count = $this->casted_cdata->casted->chunks_count,
            'peak_chunks_count' => $this->peak_chunks_count = $this->casted_cdata->casted->peak_chunks_count,
            'cached_chunks_count' => $this->cached_chunks_count = $this->casted_cdata->casted->cached_chunks_count,
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_mm_heap';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_mm_heap> $casted_cdata
         * @var Pointer<ZendMmHeap> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendMmHeap> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    /**
     * @return iterable<ZendMmHugeList>
     */
    public function iterateHugeList(Dereferencer $dereferencer): iterable
    {
        $huge_list_pointer = $this->huge_list;
        while ($huge_list_pointer !== null) {
            $huge_list = $dereferencer->deref($huge_list_pointer);
            yield $huge_list;
            $huge_list_pointer = $huge_list->next;
        }
    }
}
