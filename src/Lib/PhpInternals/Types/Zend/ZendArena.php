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

use Reli\Lib\FFI\Cast;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

/** @psalm-consistent-constructor */
class ZendArena implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $ptr;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $end;

    /** @var Pointer<ZendArena>|null */
    private ?Pointer $prev;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_arena> $casted_cdata
     * @param Pointer<ZendArena> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->ptr);
        unset($this->end);
        unset($this->prev);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'ptr' => $this->ptr = Cast::castPointerToInt(
                $this->casted_cdata->casted->ptr
            ),
            'end' => $this->end = Cast::castPointerToInt(
                $this->casted_cdata->casted->end
            ),
            'prev' => $this->prev = $this->casted_cdata->casted->prev !== null
                ? Pointer::fromCData(
                    ZendArena::class,
                    $this->casted_cdata->casted->prev,
                )
                : null
            ,
        };
    }

    public function getSize(): int
    {
        return $this->end - $this->ptr;
    }

    /**
     * @return iterable<array-key, ZendArena>
     */
    public function iterateChain(Dereferencer $dereferencer): iterable
    {
        yield $this;
        $arena = $this;
        while ($arena->prev !== null) {
            $arena = $dereferencer->deref($arena->prev);
            yield $arena;
        }
    }

    public function getSizeOfChain(Dereferencer $dereferencer): int
    {
        $size = 0;
        foreach ($this->iterateChain($dereferencer) as $arena) {
            $size += $arena->getSize();
        }
        return $size;
    }

    public static function getCTypeName(): string
    {
        return 'zend_arena';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_arena> $casted_cdata
         * @var Pointer<ZendArena> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendArena> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
