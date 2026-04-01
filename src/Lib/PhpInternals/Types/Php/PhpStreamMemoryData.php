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

final class PhpStreamMemoryData implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $data;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $fpos;

    /**
     * @param CastedCData<\FFI\PhpInternals\php_stream_memory_data> $casted_cdata
     * @param Pointer<PhpStreamMemoryData> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->data);
        unset($this->fpos);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'data' => $this->data = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->data
            ),
            'fpos' => $this->fpos = $this->casted_cdata->casted->fpos,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_stream_memory_data';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /** @var CastedCData<\FFI\PhpInternals\php_stream_memory_data> $casted_cdata */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
