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

use FFI\PhpInternals\phar_entry_info;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * View of ext/phar's per-entry struct (`phar_entry_info`). The whole
 * struct is one emalloc per file in the manifest; the fields modelled
 * here are the ones that drive heap attribution:
 *  - `filename` / `filename_len`: the entry's path, usually a separate
 *    char* allocation (but may point into a cached manifest buffer, so
 *    the caller dedups against already-attributed locations);
 *  - `metadata_str`: the serialized-metadata zend_string (8.0+ only —
 *    the field does not exist in the pre-8.0 layout, so its read is
 *    guarded by a field-existence check).
 */
final class PharEntryInfo implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $filename_address;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $filename_len;
    /**
     * Address of the serialized-metadata zend_string, or 0 when absent
     * (no cached serialization, or pre-8.0 where the field is gone).
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public int $metadata_str_address;

    /**
     * @param CastedCData<phar_entry_info> $casted_cdata
     * @param Pointer<PharEntryInfo> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->filename_address);
        unset($this->filename_len);
        unset($this->metadata_str_address);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'filename_address' => $this->filename_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->filename
            ),
            'filename_len' => $this->filename_len = $this->casted_cdata->casted->filename_len,
            'metadata_str_address' => $this->metadata_str_address = $this->readMetadataStr(),
        };
    }

    private function readMetadataStr(): int
    {
        $ctype = \FFI::typeof($this->casted_cdata->casted);
        if (in_array('metadata_str', $ctype->getStructFieldNames(), true)) {
            return FFIHelper::castPointerToInt($this->casted_cdata->casted->metadata_str);
        }
        return 0;
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'phar_entry_info';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer,
    ): static {
        /**
         * @var CastedCData<phar_entry_info> $casted_cdata
         * @var Pointer<PharEntryInfo> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
