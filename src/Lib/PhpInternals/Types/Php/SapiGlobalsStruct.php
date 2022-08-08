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

namespace PhpProfiler\Lib\PhpInternals\Types\Php;

use FFI\PhpInternals\sapi_globals_struct;
use PhpProfiler\Lib\PhpInternals\CastedCData;
use PhpProfiler\Lib\Process\Pointer\Dereferencable;
use PhpProfiler\Lib\Process\Pointer\Pointer;

final class SapiGlobalsStruct implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public float $global_request_time;

    /** @param CastedCData<sapi_globals_struct> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
    ) {
        unset($this->global_request_time);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'global_request_time' => $this->global_request_time = $this->casted_cdata->casted->global_request_time,
        };
    }

    public static function getCTypeName(): string
    {
        return 'sapi_globals_struct';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /** @var CastedCData<sapi_globals_struct> $casted_cdata */
        return new self($casted_cdata);
    }
}
