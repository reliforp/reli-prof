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
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;

/**
 * php_sxe_object wraps a SimpleXMLElement/SimpleXMLIterator instance.
 * Layout:
 *   { php_libxml_node_ptr *node;
 *     php_libxml_ref_obj  *document;
 *     HashTable           *properties;
 *     ... (xpath, iter, tmp, fptr_count)
 *     zend_object          zo;   // at the end }
 *
 * The object-store pointer targets &sxe->zo, so the outer-struct base is
 * obtained by subtracting the offset of `zo`.
 *
 * @psalm-consistent-constructor
 * @psalm-suppress ClassMustBeFinal
 */
class PhpSxeObject implements PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendObject $std;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendArray>|null
     */
    public ?Pointer $properties;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $node_address;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $document_address;

    /**
     * @param CastedCData<\FFI\PhpInternals\php_sxe_object> $casted_cdata
     * @param Pointer<PhpSxeObject> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->std);
        unset($this->properties);
        unset($this->node_address);
        unset($this->document_address);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'std' => $this->std = new ZendObject(
                $this->casted_cdata->createSubView($this->casted_cdata->casted->zo),
                new Pointer(
                    ZendObject::class,
                    $this->pointer->address
                    +
                    FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('zo'),
                    FFI::typeof($this->casted_cdata->casted->zo)->getSize(),
                ),
            ),
            'properties' => $this->properties =
                $this->casted_cdata->casted->properties !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->casted_cdata->casted->properties,
                )
                : null
            ,
            'node_address' => $this->node_address =
                FFIHelper::castPointerToInt($this->casted_cdata->casted->node),
            'document_address' => $this->document_address =
                FFIHelper::castPointerToInt($this->casted_cdata->casted->document),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_sxe_object';
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\php_sxe_object> $casted_cdata
         * @var Pointer<PhpSxeObject> $pointer
         */
        $self = new static($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /** @return Pointer<PhpSxeObject> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    /**
     * @param Pointer<ZendObject> $pointer
     * @return Pointer<PhpSxeObject>
     */
    public static function getPointerFromZendObjectPointer(
        Pointer $pointer,
        ZendTypeReader $zend_type_reader,
    ): Pointer {
        $struct_size = $zend_type_reader->sizeOf('php_sxe_object');
        [$zo_offset] = $zend_type_reader->getOffsetAndSizeOfMember('php_sxe_object', 'zo');
        return new Pointer(
            PhpSxeObject::class,
            $pointer->address - $zo_offset,
            $struct_size,
        );
    }
}
