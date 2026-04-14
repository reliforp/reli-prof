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

use FFI\CInteger;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendValue
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $lval;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public float $dval;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ?Pointer $counted;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendString>|null
     */
    public ?Pointer $str;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendArray>|null
     */
    public ?Pointer $arr;
    /** @psalm-suppress PropertyNotSetInConstructor */
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendObject>|null
     */
    public ?Pointer $obj;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendResource>|null
     */
    public ?Pointer $res;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendReference>|null
     */
    public ?Pointer $ref;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ?Pointer $ast;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ?Pointer $zv;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ?Pointer $ptr;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendClassEntry>|null
     */
    public ?Pointer $ce;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendFunction>|null
     */
    public ?Pointer $func;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendValueWw $ww;

    /** @param CastedCData<\FFI\PhpInternals\zend_value> $cdata */
    public function __construct(
        private CastedCData $cdata
    ) {
        unset($this->lval);
        unset($this->dval);
        unset($this->counted);
        unset($this->str);
        unset($this->arr);
        unset($this->obj);
        unset($this->res);
        unset($this->ref);
        unset($this->ast);
        unset($this->zv);
        unset($this->ptr);
        unset($this->ce);
        unset($this->func);
        unset($this->ww);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'lval' => $this->lval = $this->cdata->casted->lval,
            'dval' => $this->dval = $this->cdata->casted->dval,
            'str' => $this->str = $this->cdata->casted->str !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->cdata->casted->str,
                )
                : null
            ,
            'arr' => $this->arr = $this->cdata->casted->arr !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->cdata->casted->arr,
                )
                : null
            ,
            'obj' => $this->obj = $this->cdata->casted->obj !== null
                ? Pointer::fromCData(
                    ZendObject::class,
                    $this->cdata->casted->obj,
                )
                : null
            ,
            'ce' => $this->ce = $this->cdata->casted->ce !== null
                ? Pointer::fromCData(
                    ZendClassEntry::class,
                    $this->cdata->casted->ce,
                )
                : null
            ,
            'func' => $this->func = $this->cdata->casted->func !== null
                ? Pointer::fromCData(
                    ZendFunction::class,
                    $this->cdata->casted->func,
                )
                : null
            ,
            'ref' => $this->ref = $this->cdata->casted->ref !== null
                ? Pointer::fromCData(
                    ZendReference::class,
                    $this->cdata->casted->ref,
                )
                : null
            ,
            'res' => $this->res = $this->cdata->casted->res !== null
                ? Pointer::fromCData(
                    ZendResource::class,
                    $this->cdata->casted->res,
                )
                : null
            ,
        };
    }

    /**
     * @template T of Dereferencable
     * @param class-string<T> $class_name
     * @return Pointer<T>
     */
    public function getAsPointer(string $class_name, int $size): Pointer
    {
        return new Pointer(
            $class_name,
            $this->cdata->casted->lval,
            $size,
        );
    }
}
