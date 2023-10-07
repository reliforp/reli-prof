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
use FFI\CInteger;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

class ZendValue
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $lval;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public float $dval;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Pointer $counted;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendString>
     */
    public Pointer $str;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendArray>
     */
    public Pointer $arr;
    /** @psalm-suppress PropertyNotSetInConstructor */
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendObject>
     */
    public Pointer $obj;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Pointer $res;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Pointer $ref;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Pointer $ast;
    /** @psalm-suppress PropertyNotSetInConstructor */
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Pointer $zv;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Pointer $ptr;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendClassEntry>
     */
    public Pointer $ce;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendFunction>
     */
    public Pointer $func;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendValueWw $ww;

    /** @param \FFI\PhpInternals\zend_value $cdata */
    public function __construct(
        private CData $cdata
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
            'lval' => $this->lval = $this->cdata->lval,
            'dval' => $this->dval = $this->cdata->dval,
            'str' => $this->str = Pointer::fromCData(
                ZendString::class,
                $this->cdata->str,
            ),
            'arr' => $this->str = Pointer::fromCData(
                ZendArray::class,
                $this->cdata->arr,
            ),
            'obj' => $this->str = Pointer::fromCData(
                ZendObject::class,
                $this->cdata->obj,
            ),
            'ce' => $this->str = Pointer::fromCData(
                ZendClassEntry::class,
                $this->cdata->ce,
            ),
            'func' => $this->str = Pointer::fromCData(
                ZendFunction::class,
                $this->cdata->func,
            ),
        };
    }

    /** @param class-string<Dereferencable> $class_name */
    public function getAsPointer(string $class_name, int $size): Pointer
    {
        return new Pointer(
            $class_name,
            $this->cdata->lval,
            $size,
        );
    }
}
