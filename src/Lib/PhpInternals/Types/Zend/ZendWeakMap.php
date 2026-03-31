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
class ZendWeakMap implements PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendObject $std;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $ht;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_weakmap> $casted_cdata
     * @param Pointer<ZendWeakMap> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->std);
        unset($this->ht);
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
            'ht' => $this->ht = $this->createInlineDereferencable('ht', ZendArray::class),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_weakmap';
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_weakmap> $casted_cdata
         * @var Pointer<ZendWeakMap> $pointer
         */
        $self = new static($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /** @return Pointer<ZendWeakMap> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    /**
     * @param Pointer<ZendObject> $pointer
     * @return Pointer<ZendWeakMap>
     */
    public static function getPointerFromZendObjectPointer(
        Pointer $pointer,
        ZendTypeReader $zend_type_reader,
    ): Pointer {
        return new Pointer(
            ZendWeakMap::class,
            $pointer->address,
            $zend_type_reader->sizeOf('zend_weakmap'),
        );
    }
}
