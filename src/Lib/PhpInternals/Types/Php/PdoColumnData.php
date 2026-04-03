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

use FFI\PhpInternals\pdo_column_data;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PdoColumnData implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $name;

    /**
     * @param CastedCData<pdo_column_data> $casted_cdata
     * @param Pointer<PdoColumnData> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->name);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'name' => $this->name = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->name
            ),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'pdo_column_data';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<pdo_column_data> $casted_cdata
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
