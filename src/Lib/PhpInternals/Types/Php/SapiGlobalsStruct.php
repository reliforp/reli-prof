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

use FFI\PhpInternals\sapi_globals_struct;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

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
