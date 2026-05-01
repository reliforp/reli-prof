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
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\FieldReader;
use Reli\Lib\Process\Pointer\LazyDereferencable;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\Pointer;

/** @psalm-consistent-constructor */
final class ZendMmHeap implements LazyDereferencable, CDataDereferencable
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

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $last_chunks_delete_boundary;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $last_chunks_delete_count;

    private ?FieldReader $field_reader = null;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_mm_heap>|null $casted_cdata
     * @param Pointer<ZendMmHeap> $pointer
     */
    public function __construct(
        private ?CastedCData $casted_cdata,
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
        unset($this->last_chunks_delete_boundary);
        unset($this->last_chunks_delete_count);
    }

    #[\Override]
    public static function fromLazy(
        FieldReader $field_reader,
        Pointer $pointer,
        ?PointedTypeResolver $pointed_type_resolver = null,
    ): static {
        $self = new static(null, $pointer);
        $self->field_reader = $field_reader;
        return $self;
    }

    public function __get(string $field_name): mixed
    {
        if ($this->field_reader !== null) {
            return $this->getFieldLazy($field_name);
        }
        return $this->getFieldEager($field_name);
    }

    private function getFieldLazy(string $field_name): mixed
    {
        assert($this->field_reader !== null);
        return match ($field_name) {
            'use_custom_heap' => $this->use_custom_heap = $this->field_reader->readIntField(
                $this->pointer,
                'use_custom_heap',
            ),
            'size' => $this->size = $this->field_reader->readIntField($this->pointer, 'size'),
            'peak' => $this->peak = $this->field_reader->readIntField($this->pointer, 'peak'),
            'real_size' => $this->real_size = $this->field_reader->readIntField(
                $this->pointer,
                'real_size',
            ),
            'real_peak' => $this->real_peak = $this->field_reader->readIntField(
                $this->pointer,
                'real_peak',
            ),
            'limit' => $this->limit = $this->field_reader->readIntField($this->pointer, 'limit'),
            'overflow' => $this->overflow = $this->field_reader->readIntField(
                $this->pointer,
                'overflow',
            ),
            'huge_list' => $this->huge_list = $this->field_reader->readPointerField(
                $this->pointer,
                'huge_list',
                ZendMmHugeList::class,
            ),
            'main_chunk' => $this->main_chunk = $this->field_reader->readPointerField(
                $this->pointer,
                'main_chunk',
                ZendMmChunk::class,
            ),
            'chunks_count' => $this->chunks_count = $this->field_reader->readIntField(
                $this->pointer,
                'chunks_count',
            ),
            'peak_chunks_count' => $this->peak_chunks_count = $this->field_reader->readIntField(
                $this->pointer,
                'peak_chunks_count',
            ),
            'cached_chunks_count' => $this->cached_chunks_count = $this->field_reader->readIntField(
                $this->pointer,
                'cached_chunks_count',
            ),
            'last_chunks_delete_boundary' => $this->last_chunks_delete_boundary = $this->field_reader->readIntField(
                $this->pointer,
                'last_chunks_delete_boundary',
            ),
            'last_chunks_delete_count' => $this->last_chunks_delete_count = $this->field_reader->readIntField(
                $this->pointer,
                'last_chunks_delete_count',
            ),
        };
    }

    private function getFieldEager(string $field_name): mixed
    {
        assert($this->casted_cdata !== null);
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
            'last_chunks_delete_boundary' => $this->last_chunks_delete_boundary
                = $this->casted_cdata->casted->last_chunks_delete_boundary,
            'last_chunks_delete_count' => $this->last_chunks_delete_count
                = $this->casted_cdata->casted->last_chunks_delete_count,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_mm_heap';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_mm_heap>|null $casted_cdata
         * @var Pointer<ZendMmHeap> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendMmHeap> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    /**
     * Read the heads of `free_slot[ZEND_MM_BINS]` as raw addresses.
     *
     * Only meaningful when this heap was loaded eagerly (the chunk-walker
     * path always loads the main chunk eagerly so that path is fine).
     * Returns an empty array if no eager CData is attached.
     *
     * The FFI Psalm stub for `zend_mm_heap` does not declare `free_slot`
     * (the project's stubs cover only the reli-touched fields), so the
     * field-fetch + array-access here are necessarily mixed; suppress
     * narrowly rather than fattening the stub for one read site.
     *
     * @return list<int>
     * @psalm-suppress UndefinedPropertyFetch, MixedArrayAccess, MixedArgument
     */
    public function getFreeSlotHeads(): array
    {
        if ($this->casted_cdata === null) {
            return [];
        }
        $heads = [];
        // ZEND_MM_BINS is 30 across all supported PHP versions.
        for ($i = 0; $i < 30; $i++) {
            $heads[] = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->free_slot[$i],
            );
        }
        return $heads;
    }

    /**
     * @return iterable<ZendMmHugeList>
     */
    public function iterateHugeList(Dereferencer $dereferencer): iterable
    {
        $huge_list_pointer = $this->huge_list;
        $visited = [];
        while ($huge_list_pointer !== null) {
            if (isset($visited[$huge_list_pointer->address])) {
                break;
            }
            $visited[$huge_list_pointer->address] = true;
            try {
                $huge_list = $dereferencer->deref($huge_list_pointer);
            } catch (\Throwable) {
                break;
            }
            yield $huge_list;
            $huge_list_pointer = $huge_list->next;
        }
    }
}
