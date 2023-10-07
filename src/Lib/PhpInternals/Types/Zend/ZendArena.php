<?php

namespace Reli\Lib\PhpInternals\Types\Zend;

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

class ZendArena implements Dereferencable
{
    public int $ptr;
    public int $end;

    /** @var Pointer<ZendArena>|null */
    private ?Pointer $prev;
    public function __construct(
        private CastedCData $casted_cdata,
    ) {
        unset($this->prev);
        $this->ptr = \FFI::cast('long', $this->casted_cdata->casted->ptr)->cdata;
        $this->end = \FFI::cast('long', $this->casted_cdata->casted->end)->cdata;
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
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

    public function iterateChain(Dereferencer $dereferencer): \Generator
    {
        $arena = $this;
        while ($arena !== null) {
            yield $arena;
            if (!is_null($arena->prev)) {
                $arena = $dereferencer->deref($arena->prev);
            } else {
                $arena = null;
            }
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
        return new static($casted_cdata);
    }
}