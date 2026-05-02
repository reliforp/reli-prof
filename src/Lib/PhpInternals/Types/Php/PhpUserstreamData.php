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

namespace Reli\Lib\PhpInternals\Types\Php;

use FFI\PhpInternals\php_userstream_data_t;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\Zend\InlineCDataCreatorTrait;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * @psalm-consistent-constructor
 * @psalm-suppress ClassMustBeFinal
 */
class PhpUserstreamData implements PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $object;

    /**
     * @param CastedCData<php_userstream_data_t> $casted_cdata
     * @param Pointer<PhpUserstreamData> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->object);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'object' => $this->object = $this->createInlineDereferencable('object', Zval::class),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_userstream_data_t';
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<php_userstream_data_t> $casted_cdata
         * @var Pointer<self> $pointer
         */
        $self = new static($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
