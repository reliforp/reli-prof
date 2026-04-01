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

use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PhpStreamTempData implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $innerstream;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $smax;

    /**
     * @param CastedCData<\FFI\PhpInternals\php_stream_temp_data> $casted_cdata
     * @param Pointer<PhpStreamTempData> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->innerstream);
        unset($this->smax);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'innerstream' => $this->innerstream = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->innerstream
            ),
            'smax' => $this->smax = $this->casted_cdata->casted->smax,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_stream_temp_data';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /** @var CastedCData<\FFI\PhpInternals\php_stream_temp_data> $casted_cdata */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
