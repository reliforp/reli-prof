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
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

class Zval implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendValue $value;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZvalU1 $u1;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZvalU2 $u2;

    /** @param \FFI\PhpInternals\zval $cdata */
    public function __construct(
        private CastedCData $casted_cdata
    ) {
        unset($this->value);
        unset($this->u1);
        unset($this->u2);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'value' => $this->value = new ZendValue($this->casted_cdata->casted->value),
            'u1' => $this->u1 = new ZvalU1($this->casted_cdata->casted->u1),
            'u2' => $this->u2 = new ZvalU2($this->casted_cdata->casted->u2),
        };
    }

    public function getType(): string
    {
        return $this->u1->getType();
    }

    public function isArray(): bool
    {
        return $this->getType() === 'IS_ARRAY';
    }

    public function isObject(): bool
    {
        return $this->getType() === 'IS_OBJECT';
    }

    public function isString(): bool
    {
        return $this->getType() === 'IS_STRING';
    }

    public function isLong(): bool
    {
        return $this->getType() === 'IS_LONG';
    }

    public function isDouble(): bool
    {
        return $this->getType() === 'IS_DOUBLE';
    }

    public function isBool(): bool
    {
        return $this->getType() === 'IS_TRUE' || $this->getType() === 'IS_FALSE';
    }

    public function isNull(): bool
    {
        return $this->getType() === 'IS_NULL';
    }

    public function isScalar(): bool
    {
        return $this->isLong() || $this->isDouble() || $this->isBool() || $this->isNull();
    }

    public function isUndef()
    {
        return $this->getType() === 'IS_UNDEF';
    }

    public static function getCTypeName(): string
    {
        return 'zval';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /** @var CastedCData<zval> $casted_cdata */
        return new self($casted_cdata);
    }
}
