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

use FFI;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;

/**
 * @psalm-consistent-constructor
 * @psalm-suppress ClassMustBeFinal
 */
class ZendClosure implements Dereferencable, PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendObject $std;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendFunction $func;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $this_ptr;

    /** @var Pointer<ZendClassEntry>|null */
    public ?Pointer $called_scope;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_closure> $casted_cdata
     * @param Pointer<ZendClosure> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->std);
        unset($this->func);
        unset($this->this_ptr);
        unset($this->called_scope);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'std' => $this->std = new ZendObject(
                new CastedCData(
                    $this->casted_cdata->casted->std,
                    $this->casted_cdata->casted->std,
                ),
                new Pointer(
                    ZendObject::class,
                    $this->pointer->address,
                    FFI::typeof($this->casted_cdata->casted->std)->getSize(),
                ),
            ),
            'func' => $this->func = new ZendFunction(
                new CastedCData(
                    $this->casted_cdata->casted->func,
                    $this->casted_cdata->casted->func,
                ),
                new Pointer(
                    ZendFunction::class,
                    $this->pointer->address
                    +
                    FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('func'),
                    FFI::typeof($this->casted_cdata->casted->func)->getSize(),
                ),
            ),
            'this_ptr' => $this->this_ptr = $this->createInlineDereferencable('this_ptr', Zval::class),
            'called_scope' => $this->called_scope = $this->casted_cdata->casted->called_scope !== null
                ? Pointer::fromCData(
                    ZendClassEntry::class,
                    $this->casted_cdata->casted->called_scope,
                )
                : null
            ,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_closure';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer,
        ?PointedTypeResolver $pointed_type_resolver = null,
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_closure> $casted_cdata
         * @var Pointer<ZendClosure> $pointer
         */
        $self = new static($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /** @return Pointer<ZendClosure> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    /**
     * @param Pointer<ZendObject> $pointer
     * @return Pointer<ZendClosure>
     */
    public static function getPointerFromZendObjectPointer(
        Pointer $pointer,
        ZendTypeReader $zend_type_reader,
    ): Pointer {
        return new Pointer(
            ZendClosure::class,
            $pointer->address,
            $zend_type_reader->sizeOf('zend_closure'),
        );
    }
}
