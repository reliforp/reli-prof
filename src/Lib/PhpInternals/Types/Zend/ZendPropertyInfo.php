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
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * @psalm-consistent-constructor
 */
final class ZendPropertyInfo implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $offset;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $flags;

    /** @var Pointer<ZendString> */
    public ?Pointer $name;

    /** @var Pointer<ZendString> */
    public ?Pointer $doc_comment;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_property_info> $casted_cdata
     * @param Pointer<self> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->offset);
        unset($this->flags);
        unset($this->name);
        unset($this->doc_comment);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'offset' => $this->offset = $this->casted_cdata->casted->offset,
            'flags' => $this->flags = $this->casted_cdata->casted->flags,
            'name' => $this->name = $this->casted_cdata->casted->name !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->casted_cdata->casted->name,
                )
                : null
            ,
            'doc_comment' => $this->doc_comment = $this->casted_cdata->casted->doc_comment !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->casted_cdata->casted->doc_comment,
                )
                : null
            ,
        };
    }

    /**
     * ZEND_ACC_STATIC changed across PHP versions:
     * - PHP 7.0-7.3: ZEND_ACC_STATIC = 0x01
     * - PHP 7.4+:     ZEND_ACC_STATIC = 0x10 (1 << 4)
     */
    public function isStatic(bool $php74_or_later = true): bool
    {
        $mask = $php74_or_later ? (1 << 4) : 0x01;
        return (bool)($this->flags & $mask);
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_property_info';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_property_info> $casted_cdata
         * @var Pointer<self> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
