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

use FFI\PhpInternals\zend_array;
use FFI\PhpInternals\zend_hash_func_ffi;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\C\RawInt32;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * struct _zend_array {
 * zend_refcounted_h gc;
* union {
* struct {
* zend_uchar    flags;
* zend_uchar    _unused;
* zend_uchar    nIteratorsCount;
* zend_uchar    _unused2;
* } v;
* uint32_t flags;
* } u;
* uint32_t          nTableMask;
* Bucket           *arData;
* uint32_t          nNumUsed;
* uint32_t          nNumOfElements;
* uint32_t          nTableSize;
* uint32_t          nInternalPointer;
* zend_long         nNextFreeElement;
* dtor_func_t       pDestructor;
* }; */
/** @psalm-consistent-constructor */
class ZendArray implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $flags;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $nTableMask;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<Bucket>
     */
    public Pointer $arData;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $nNumUsed;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $nNumOfElements;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $nTableSize;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $nInternalPointer;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $nNextFreeElement;

    /**
     * @param CastedCData<zend_array> $casted_cdata
     * @param Pointer<ZendArray> $pointer,
     */
    public function __construct(
        protected CastedCData $casted_cdata,
        protected Pointer $pointer,
    ) {
        unset($this->flags);
        unset($this->nTableMask);
        unset($this->arData);
        unset($this->nNumUsed);
        unset($this->nNumOfElements);
        unset($this->nTableSize);
        unset($this->nInternalPointer);
        unset($this->nNextFreeElement);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'flags' => $this->flags = $this->casted_cdata->casted->u->flags,
            'nTableMask' => $this->nTableMask = $this->casted_cdata->casted->nTableMask,
            'arData' => $this->arData = Pointer::fromCData(
                Bucket::class,
                $this->casted_cdata->casted->arData,
            ),
            'nNumUsed' => $this->nNumUsed = $this->casted_cdata->casted->nNumUsed,
            'nNumOfElements' => $this->nNumOfElements = $this->casted_cdata->casted->nNumOfElements,
            'nTableSize' => $this->nTableSize = $this->casted_cdata->casted->nTableSize,
            'nInternalPointer' => $this->nInternalPointer = $this->casted_cdata->casted->nInternalPointer,
            'nNextFreeElement' => $this->nNextFreeElement = $this->casted_cdata->casted->nNextFreeElement,
        };
    }

    public function findByKey(
        Dereferencer $dereferencer,
        string $key,
        int $hash = null
    ): ?Bucket {
        $hash ??= $this->calculateHash($key);
        $hash_index = $hash | $this->nTableMask;
        $hash_index = $hash_index & 0xFFFF_FFFF;
        if ($hash_index & 0x8000_0000) {
            $hash_index = $hash_index & ~0x8000_0000;
            $hash_index = -2147483648 + $hash_index;
        }
        $idx = $dereferencer->deref(
            $this->calculateIndex($hash_index, $this->arData)
        )->value;

        while ($idx !== -1) {
            /** @var Bucket $bucket */
            $bucket = $dereferencer->deref(
                $this->arData->indexedAt($idx)
            );
            if ($bucket->h === $hash) {
                $bucket_key_zstring = $dereferencer->deref($bucket->key)->getValuePointer($bucket->key);
                $bucket_key = (string)$dereferencer->deref($bucket_key_zstring);
                if ($bucket_key === $key) {
                    return $bucket;
                }
            }
            $idx = $bucket->val->u2->next;
        }
        return null;
    }

    /**
     * @param Pointer<Bucket> $pointer
     * @return Pointer<RawInt32>
     */
    private function calculateIndex(int $index, Pointer $pointer): Pointer
    {
        return new Pointer(
            RawInt32::class,
            $pointer->address + $index * 4,
            4,
        );
    }

    private function calculateHash(string $key): int
    {
        static $ffi = null;
        /** @var ?zend_hash_func_ffi $ffi */
        $ffi ??= \FFI::cdef('int zend_hash_func(const char *str, int len);');
        assert(!is_null($ffi));

        return $ffi->zend_hash_func($key, strlen($key));
    }

    public static function getCTypeName(): string
    {
        return 'zend_array';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<zend_array> $casted_cdata
         * @var Pointer<ZendArray> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
