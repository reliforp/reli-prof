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

use FFI\PhpInternals\phar_entry_info_truncated;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Partial view of ext/phar's per-entry struct — only the prefix up
 * to the metadata_tracker's serialized-string pointer is modelled.
 * The real struct continues past with filename/tmp/link/fp/cfp
 * fields, but those don't materially contribute to the zend_mm gap
 * (filename/tmp/link are typically NULL or persistent; fp/cfp are
 * php_streams covered by the archive-level walker).
 */
final class PharEntryInfo implements CDataDereferencable
{
    /**
     * Address of the serialized-metadata zend_string for this entry,
     * or 0 when this entry has no cached serialization yet (the
     * common case for read-only phars whose metadata zvals are
     * already in their canonical form). Caller derefs the address
     * via {@see CollectorHelpers::collectZendStringPointer} or
     * similar when non-zero.
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public int $metadata_str_address;

    /**
     * @param CastedCData<phar_entry_info_truncated> $casted_cdata
     * @param Pointer<PharEntryInfo> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->metadata_str_address);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'metadata_str_address' => $this->metadata_str_address = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->metadata_str
            ),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'phar_entry_info_truncated';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer,
    ): static {
        /**
         * @var CastedCData<phar_entry_info_truncated> $casted_cdata
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
