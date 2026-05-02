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

use FFI\PhpInternals\php_netstream_data_t;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PhpNetstreamData implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $socket;

    /**
     * @param CastedCData<php_netstream_data_t> $casted_cdata
     * @param Pointer<PhpNetstreamData> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->socket);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'socket' => $this->socket = $this->casted_cdata->casted->socket,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_netstream_data_t';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<php_netstream_data_t> $casted_cdata
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
