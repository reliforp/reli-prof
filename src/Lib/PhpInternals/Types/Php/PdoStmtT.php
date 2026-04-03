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

use FFI\PhpInternals\pdo_stmt_t;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PdoStmtT implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $driver_data;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $column_count;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $columns;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $bound_params;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $bound_columns;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $query_string;

    /**
     * @param CastedCData<pdo_stmt_t> $casted_cdata
     * @param Pointer<PdoStmtT> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->driver_data);
        unset($this->column_count);
        unset($this->columns);
        unset($this->bound_params);
        unset($this->bound_columns);
        unset($this->query_string);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'driver_data' => $this->driver_data = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->driver_data
            ),
            'column_count' => $this->column_count = $this->casted_cdata->casted->column_count,
            'columns' => $this->columns = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->columns
            ),
            'bound_params' => $this->bound_params = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->bound_params
            ),
            'bound_columns' => $this->bound_columns = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->bound_columns
            ),
            'query_string' => $this->query_string = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->query_string
            ),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'pdo_stmt_t';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<pdo_stmt_t> $casted_cdata
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
