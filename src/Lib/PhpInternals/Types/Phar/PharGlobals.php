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

namespace Reli\Lib\PhpInternals\Types\Phar;

use FFI\PhpInternals\phar_globals_truncated;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\Zend\InlineCDataCreatorTrait;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Partial view of ext/phar's MODULE_GLOBALS struct — only the inline
 * HashTables we walk are modelled. Every other field is present in
 * the truncated C declaration so the offsets line up; we just don't
 * expose accessors for them. See `phar_globals_truncated` in v84.h.
 */
final class PharGlobals implements PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $phar_persist_map;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $phar_fname_map;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $phar_alias_map;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $mime_types;

    /**
     * @param CastedCData<phar_globals_truncated> $casted_cdata
     * @param Pointer<PharGlobals> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->phar_persist_map);
        unset($this->phar_fname_map);
        unset($this->phar_alias_map);
        unset($this->mime_types);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'phar_persist_map' => $this->phar_persist_map = $this->createInlineDereferencable(
                'phar_persist_map',
                ZendArray::class,
            ),
            'phar_fname_map' => $this->phar_fname_map = $this->createInlineDereferencable(
                'phar_fname_map',
                ZendArray::class,
            ),
            'phar_alias_map' => $this->phar_alias_map = $this->createInlineDereferencable(
                'phar_alias_map',
                ZendArray::class,
            ),
            'mime_types' => $this->mime_types = $this->createInlineDereferencable(
                'mime_types',
                ZendArray::class,
            ),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'phar_globals_truncated';
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<phar_globals_truncated> $casted_cdata
         * @var Pointer<self> $pointer
         */
        $self = new self($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
