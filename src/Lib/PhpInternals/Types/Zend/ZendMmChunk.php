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
final class ZendMmChunk implements Dereferencable
{
    public const SIZE = (2 * 1024 * 1024);

    public const PAGE_SIZE = (4 * 1024);

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendMmHeap>|null
     */
    public ?Pointer $heap;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendMmChunk>|null
     */
    public ?Pointer $prev;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendMmChunk>|null
     */
    public ?Pointer $next;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $num;

    public ZendMmHeap $heap_slot;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $free_pages;
    public ZendMmPageMap $map;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_mm_chunk> $casted_cdata
     * @param Pointer<ZendMmChunk> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->heap);
        unset($this->prev);
        unset($this->next);
        unset($this->num);
        unset($this->free_pages);
        $this->heap_slot = new ZendMmHeap(
            new CastedCData(
                $this->casted_cdata->casted->heap_slot,
                $this->casted_cdata->casted->heap_slot,
            ),
            new Pointer(
                ZendMmHeap::class,
                $this->pointer->address
                +
                \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('heap_slot'),
                \FFI::sizeof($this->casted_cdata->casted->heap_slot),
            ),
        );
        $this->map = new ZendMmPageMap($this->casted_cdata->casted->map);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'heap' => $this->heap = $this->casted_cdata->casted->heap !== null
                ? Pointer::fromCData(
                    ZendMmHeap::class,
                    $this->casted_cdata->casted->heap,
                )
                : null
            ,
            'prev' => $this->prev = $this->casted_cdata->casted->prev !== null
                ? Pointer::fromCData(
                    ZendMmChunk::class,
                    $this->casted_cdata->casted->prev,
                )
                : null
            ,
            'next' => $this->next = $this->casted_cdata->casted->next !== null
                ? Pointer::fromCData(
                    ZendMmChunk::class,
                    $this->casted_cdata->casted->next,
                )
                : null
            ,
            'num' => $this->num = $this->casted_cdata->casted->num,
            'free_pages' => $this->free_pages = $this->casted_cdata->casted->free_pages,
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_mm_chunk';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_mm_chunk> $casted_cdata
         * @var Pointer<ZendMmChunk> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendMmChunk> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    public function isInRange(int $address): bool
    {
        return $this->getPointer()->address <= $address
            and $address < $this->getPointer()->address + self::SIZE;
    }

    /**
     * @return iterable<ZendMmChunk>
     */
    public function iterateChunks(Dereferencer $dereferencer): iterable
    {
        yield $this;
        $chunk = $this;
        while (!is_null($chunk->next) and $chunk->next->address !== $this->getPointer()->address) {
            $chunk = $dereferencer->deref($chunk->next);
            yield $chunk;
        }
    }

    public function getPageOfAddress(int $address): int
    {
        return (int)(($address - $this->getPointer()->address) / self::PAGE_SIZE);
    }
}
