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

use FFI\PhpInternals\phar_archive_data_truncated;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\PhpInternals\Types\Php\PhpStream;
use Reli\Lib\PhpInternals\Types\Zend\InlineCDataCreatorTrait;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Partial view of ext/phar's internal `_phar_archive_data` struct —
 * only the prefix up to and including the inline `manifest` HashTable
 * is modeled. The real struct continues with more HashTables
 * (`virtual_dirs`, `mounted_dirs`), the phar file pointer, the
 * signature, etc., but the file-path zend_strings reli's pointer
 * tracer otherwise misses live exclusively in `manifest`.
 *
 * Matches `_phar_archive_data_truncated` in v84.h.
 */
final class PharArchiveData implements PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<RawString>
     */
    public Pointer $fname;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $manifest;
    /**
     * Per-archive hash of every virtual directory path inside the phar
     * (`.box`, `.box/vendor`, `.box/vendor/composer`, …). Keys are
     * zend_strings, values are empty markers — walking emits the keys.
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public ZendArray $virtual_dirs;
    /**
     * Per-archive hash of `Phar::mount()` mappings. Usually empty;
     * when populated, keys are virtual paths and values are char*
     * pointers to the mount-target filename.
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public ZendArray $mounted_dirs;
    /**
     * Underlying php_stream for the .phar file (typically STDIO).
     * Address may be 0 for not-yet-opened archives.
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public int $fp_address;
    /**
     * Decompressed-content cache stream (typically MEMORY or TEMP).
     * Address may be 0 when no compressed entries have been touched.
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public int $ufp_address;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $fname_len;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $ext_address;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $ext_len;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $alias_address;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $alias_len;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $signature_address;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $sig_len;

    /**
     * @param CastedCData<phar_archive_data_truncated> $casted_cdata
     * @param Pointer<PharArchiveData> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->fname);
        unset($this->manifest);
        unset($this->virtual_dirs);
        unset($this->mounted_dirs);
        unset($this->fp_address);
        unset($this->ufp_address);
        unset($this->fname_len);
        unset($this->ext_address);
        unset($this->ext_len);
        unset($this->alias_address);
        unset($this->alias_len);
        unset($this->signature_address);
        unset($this->sig_len);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'fname' => $this->fname = new Pointer(
                RawString::class,
                FFIHelper::castPointerToInt($this->casted_cdata->casted->fname),
                256,
            ),
            'manifest' => $this->manifest = $this->createInlineDereferencable(
                'manifest',
                ZendArray::class,
            ),
            'virtual_dirs' => $this->virtual_dirs = $this->createInlineDereferencable(
                'virtual_dirs',
                ZendArray::class,
            ),
            'mounted_dirs' => $this->mounted_dirs = $this->createInlineDereferencable(
                'mounted_dirs',
                ZendArray::class,
            ),
            'fp_address' => $this->fp_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->fp
            ),
            'ufp_address' => $this->ufp_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->ufp
            ),
            'fname_len' => $this->fname_len = $this->casted_cdata->casted->fname_len,
            'ext_address' => $this->ext_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->ext
            ),
            'ext_len' => $this->ext_len = $this->casted_cdata->casted->ext_len,
            'alias_address' => $this->alias_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->alias
            ),
            'alias_len' => $this->alias_len = $this->casted_cdata->casted->alias_len,
            'signature_address' => $this->signature_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->signature
            ),
            'sig_len' => $this->sig_len = $this->casted_cdata->casted->sig_len,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'phar_archive_data_truncated';
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<phar_archive_data_truncated> $casted_cdata
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
