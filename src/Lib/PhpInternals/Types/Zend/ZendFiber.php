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
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;

/**
 * @psalm-consistent-constructor
 * @psalm-suppress ClassMustBeFinal
 */
class ZendFiber implements PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendObject $std;

    /** @var Pointer<ZendExecuteData>|null */
    public ?Pointer $execute_data;

    /** @var Pointer<ZendExecuteData>|null */
    public ?Pointer $stack_bottom;

    /** @var Pointer<ZendVmStack>|null */
    public ?Pointer $vm_stack;

    /** @var Pointer<ZendFiberStack>|null */
    public ?Pointer $context_stack;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_fiber> $casted_cdata
     * @param Pointer<ZendFiber> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->std);
        unset($this->execute_data);
        unset($this->stack_bottom);
        unset($this->vm_stack);
        unset($this->context_stack);
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
            'execute_data' => $this->execute_data =
                $this->casted_cdata->casted->execute_data !== null
                ? Pointer::fromCData(
                    ZendExecuteData::class,
                    $this->casted_cdata->casted->execute_data,
                )
                : null
            ,
            'stack_bottom' => $this->stack_bottom =
                $this->casted_cdata->casted->stack_bottom !== null
                ? Pointer::fromCData(
                    ZendExecuteData::class,
                    $this->casted_cdata->casted->stack_bottom,
                )
                : null
            ,
            'vm_stack' => $this->vm_stack =
                $this->casted_cdata->casted->vm_stack !== null
                ? Pointer::fromCData(
                    ZendVmStack::class,
                    $this->casted_cdata->casted->vm_stack,
                )
                : null
            ,
            'context_stack' => $this->context_stack =
                $this->casted_cdata->casted->context->stack !== null
                ? Pointer::fromCData(
                    ZendFiberStack::class,
                    $this->casted_cdata->casted->context->stack,
                )
                : null
            ,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_fiber';
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_fiber> $casted_cdata
         * @var Pointer<ZendFiber> $pointer
         */
        $self = new static($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /** @return Pointer<ZendFiber> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    /**
     * @param Pointer<ZendObject> $pointer
     * @return Pointer<ZendFiber>
     */
    public static function getPointerFromZendObjectPointer(
        Pointer $pointer,
        ZendTypeReader $zend_type_reader,
    ): Pointer {
        return new Pointer(
            ZendFiber::class,
            $pointer->address,
            $zend_type_reader->sizeOf('zend_fiber'),
        );
    }
}
