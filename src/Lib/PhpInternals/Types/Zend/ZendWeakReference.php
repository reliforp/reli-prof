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
 * In PHP internals, the zend_weakref struct has the layout:
 *   { zend_object *referent; zend_object std; }
 * The custom field (referent) comes BEFORE the embedded zend_object.
 * The object store pointer points to &wr->std, so we must subtract
 * the offset of std to get the struct base.
 *
 * @psalm-consistent-constructor
 * @psalm-suppress ClassMustBeFinal
 */
class ZendWeakReference implements PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendObject $std;

    /** @var Pointer<ZendObject>|null */
    public ?Pointer $referent;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_weakref> $casted_cdata
     * @param Pointer<ZendWeakReference> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->std);
        unset($this->referent);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'std' => $this->std = new ZendObject(
                $this->casted_cdata->createSubView($this->casted_cdata->casted->std),
                new Pointer(
                    ZendObject::class,
                    $this->pointer->address
                    +
                    FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('std'),
                    FFI::typeof($this->casted_cdata->casted->std)->getSize(),
                ),
            ),
            'referent' => $this->referent =
                $this->casted_cdata->casted->referent !== null
                ? Pointer::fromCData(
                    ZendObject::class,
                    $this->casted_cdata->casted->referent,
                )
                : null
            ,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_weakref';
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_weakref> $casted_cdata
         * @var Pointer<ZendWeakReference> $pointer
         */
        $self = new static($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /** @return Pointer<ZendWeakReference> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    /**
     * @param Pointer<ZendObject> $pointer
     * @return Pointer<ZendWeakReference>
     */
    public static function getPointerFromZendObjectPointer(
        Pointer $pointer,
        ZendTypeReader $zend_type_reader,
    ): Pointer {
        $struct_size = $zend_type_reader->sizeOf('zend_weakref');
        [$std_offset] = $zend_type_reader->getOffsetAndSizeOfMember('zend_weakref', 'std');
        return new Pointer(
            ZendWeakReference::class,
            $pointer->address - $std_offset,
            $struct_size,
        );
    }
}
