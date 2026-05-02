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

use FFI\PhpInternals\php_glob_stream_data_tail;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PhpGlobStreamDataTail implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $path;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $path_len;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $pattern;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $pattern_len;

    /**
     * @param CastedCData<php_glob_stream_data_tail> $casted_cdata
     * @param Pointer<PhpGlobStreamDataTail> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->path);
        unset($this->path_len);
        unset($this->pattern);
        unset($this->pattern_len);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'path' => $this->path = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->path
            ),
            'path_len' => $this->path_len = $this->casted_cdata->casted->path_len,
            'pattern' => $this->pattern = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->pattern
            ),
            'pattern_len' => $this->pattern_len = $this->casted_cdata->casted->pattern_len,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_glob_stream_data_tail';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<php_glob_stream_data_tail> $casted_cdata
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
