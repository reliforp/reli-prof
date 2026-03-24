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

use FFI\PhpInternals\zend_string;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\FieldReader;
use Reli\Lib\Process\Pointer\LazyDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendString implements LazyDereferencable
{
    public const ZEND_STRING_HEADER_SIZE = 24;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendRefcountedH $gc;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $h;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $len;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<RawString>
     */
    public Pointer $val;

    private ?FieldReader $field_reader = null;

    /**
     * @param CastedCData<zend_string>|null $casted_cdata
     * @param Pointer<ZendString> $pointer
     */
    public function __construct(
        private ?CastedCData $casted_cdata,
        private int $offset_to_val,
        private Pointer $pointer,
    ) {
        unset($this->gc);
        unset($this->h);
        unset($this->len);
        unset($this->val);
    }

    public static function fromLazy(FieldReader $field_reader, Pointer $pointer): static
    {
        $self = new self(null, self::ZEND_STRING_HEADER_SIZE, $pointer);
        $self->field_reader = $field_reader;
        return $self;
    }

    public function __get(string $field_name)
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
            'len' => $this->len = $this->field_reader->readIntField(
                $this->pointer,
                'len',
            ),
            'h' => $this->h = $this->field_reader->readIntField(
                $this->pointer,
                'h',
            ),
            default => throw new \LogicException(
                "Field '{$field_name}' is not available in lazy deref mode for ZendString"
            ),
        };
    }

    private function getFieldEager(string $field_name): mixed
    {
        assert($this->casted_cdata !== null);
        return match ($field_name) {
            'gc' => $this->gc = new ZendRefcountedH(
                $this->casted_cdata->casted->gc,
            ),
            'h' => $this->h = $this->casted_cdata->casted->h,
            'len' => $this->len = $this->casted_cdata->casted->len,
            'val' => $this->val = Pointer::fromCData(
                RawString::class,
                $this->casted_cdata->casted->val,
            )
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_string';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<zend_string> $casted_cdata
         * @var Pointer<ZendString> $pointer
         */
        // an almost safe assumption I think
        return new self($casted_cdata, self::ZEND_STRING_HEADER_SIZE, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    /**
     * @return Pointer<RawString>
     */
    public function getValuePointer(int $max_size = 256): Pointer
    {
        return new Pointer(
            RawString::class,
            $this->getPointer()->address + $this->offset_to_val,
            \min($this->len, $max_size)
        );
    }

    public function getSize(): int
    {
        return $this->len + self::ZEND_STRING_HEADER_SIZE;
    }

    public function toString(Dereferencer $dereferencer, int $max_size = 256): string
    {
        if ($this->len === 0) {
            return '';
        }
        return (string)$dereferencer->deref(
            $this->getValuePointer($max_size)
        );
    }
}
