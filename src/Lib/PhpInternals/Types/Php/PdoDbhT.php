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

use FFI\PhpInternals\pdo_dbh_t;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PdoDbhT implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $driver_data;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $data_source;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $data_source_len;

    /**
     * @param CastedCData<pdo_dbh_t> $casted_cdata
     * @param Pointer<PdoDbhT> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->driver_data);
        unset($this->data_source);
        unset($this->data_source_len);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'driver_data' => $this->driver_data = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->driver_data
            ),
            'data_source' => $this->data_source = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->data_source
            ),
            'data_source_len' => $this->data_source_len = $this->casted_cdata->casted->data_source_len,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'pdo_dbh_t';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<pdo_dbh_t> $casted_cdata
         * @var Pointer<self> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
