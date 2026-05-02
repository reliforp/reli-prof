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

use FFI\PhpInternals\php_stdio_stream_data;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PhpStdioStreamData implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $fd;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $temp_name;

    /**
     * @param CastedCData<php_stdio_stream_data> $casted_cdata
     * @param Pointer<PhpStdioStreamData> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->fd);
        unset($this->temp_name);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'fd' => $this->fd = $this->casted_cdata->casted->fd,
            'temp_name' => $this->temp_name = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->temp_name
            ),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_stdio_stream_data';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<php_stdio_stream_data> $casted_cdata
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
