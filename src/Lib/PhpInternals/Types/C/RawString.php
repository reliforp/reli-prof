<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\PhpInternals\Types\C;

use FFI\CData;
use PhpProfiler\Lib\PhpInternals\CastedCData;
use PhpProfiler\Lib\Process\Pointer\Dereferencable;
use PhpProfiler\Lib\Process\Pointer\Pointer;

final class RawString implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public string $value;

    /** @param CastedCData<CData> $cdata */
    public function __construct(
        private CastedCData $cdata,
        private int $len,
    ) {
        unset($this->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function __get(string $field_name): string
    {
        return match ($field_name) {
            'value' => $this->value = substr(
                \FFI::string($this->cdata->casted),
                0,
                $this->len
            ),
        };
    }

    public static function getCTypeName(): string
    {
        return 'char[0]';
    }

    /** @param CastedCData<CData> $casted_cdata */
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        return new self($casted_cdata, $pointer->size);
    }
}
