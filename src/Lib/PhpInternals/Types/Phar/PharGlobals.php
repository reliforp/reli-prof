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
use Reli\Lib\FFI\FFIHelper;
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

    /** Per-request transient char* fields. Address may be 0
     *  (uninitialised). Length is read from the paired *_len field;
     *  for cache_list (no length pair) the buffer is null-terminated,
     *  caller probes it. */
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $cache_list_address;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $cwd_address;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $cwd_len;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $openssl_privatekey_address;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $openssl_privatekey_len;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $last_phar_name_address;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $last_phar_name_len;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $last_alias_address;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $last_alias_len;

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
        unset($this->cache_list_address);
        unset($this->cwd_address);
        unset($this->cwd_len);
        unset($this->openssl_privatekey_address);
        unset($this->openssl_privatekey_len);
        unset($this->last_phar_name_address);
        unset($this->last_phar_name_len);
        unset($this->last_alias_address);
        unset($this->last_alias_len);
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
            'cache_list_address' => $this->cache_list_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->cache_list
            ),
            'cwd_address' => $this->cwd_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->cwd
            ),
            'cwd_len' => $this->cwd_len = $this->casted_cdata->casted->cwd_len,
            'openssl_privatekey_address' => $this->openssl_privatekey_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->openssl_privatekey
            ),
            'openssl_privatekey_len' => $this->openssl_privatekey_len
                = $this->casted_cdata->casted->openssl_privatekey_len,
            'last_phar_name_address' => $this->last_phar_name_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->last_phar_name
            ),
            'last_phar_name_len' => $this->last_phar_name_len
                = $this->casted_cdata->casted->last_phar_name_len,
            'last_alias_address' => $this->last_alias_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->last_alias
            ),
            'last_alias_len' => $this->last_alias_len = $this->casted_cdata->casted->last_alias_len,
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
