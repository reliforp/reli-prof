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

/** @psalm-consistent-constructor */
class ZendLiveRange implements Dereferencable
{
    public const ZEND_LIVE_TMPVAR = 0;
    public const ZEND_LIVE_LOOP = 1;
    public const ZEND_LIVE_SILENCE = 2;
    public const ZEND_LIVE_ROPE = 3;
    public const ZEND_LIVE_NEW = 4;
    public const ZEND_LIVE_MASK = 7;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $var;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $start;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $end;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_live_range> $casted_cdata
     * @param Pointer<ZendLiveRange> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->var);
        unset($this->start);
        unset($this->end);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'var' => $this->var = $this->casted_cdata->casted->var,
            'start' => $this->start = $this->casted_cdata->casted->start,
            'end' => $this->end = $this->casted_cdata->casted->end,
        };
    }

    public function getTmpVarNum(): int
    {
        return $this->var & ~self::ZEND_LIVE_MASK;
    }

    public function isInRange(int $offset): bool
    {
        return $this->start <= $offset and $offset < $this->end;
    }

    public static function getCTypeName(): string
    {
        return 'zend_live_range';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_live_range> $casted_cdata
         * @var Pointer<ZendLiveRange> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendLiveRange> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
