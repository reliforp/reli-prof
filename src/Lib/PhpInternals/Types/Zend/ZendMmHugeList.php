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
use Reli\Lib\Process\Pointer\Pointer;

final class ZendMmHugeList implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $ptr;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $size;

    /** @var Pointer<ZendMmHugeList>|null */
    public ?Pointer $next;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_mm_huge_list> $casted_cdata
     * @param Pointer<ZendMmHugeList> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->ptr);
        unset($this->size);
        unset($this->next);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'ptr' => $this->ptr = $this->casted_cdata->casted->ptr,
            'size' => $this->size = $this->casted_cdata->casted->size,
            'next' => $this->next = $this->casted_cdata->casted->next !== null
                ? Pointer::fromCData(
                    self::class,
                    $this->casted_cdata->casted->next,
                )
                : null
            ,
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_mm_huge_list';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_mm_huge_list> $casted_cdata
         * @var Pointer<ZendMmHugeList> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendMmHugeList> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
