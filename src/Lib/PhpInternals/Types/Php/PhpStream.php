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

use FFI\CData;
use FFI\PhpInternals\php_stream;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PhpStream implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $ops;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $abstract;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $res;

    /**
     * @param CastedCData<php_stream> $casted_cdata
     * @param Pointer<PhpStream> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->ops);
        unset($this->abstract);
        unset($this->res);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'ops' => $this->ops = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->ops
            ),
            'abstract' => $this->abstract = $this->casted_cdata->casted->abstract,
            'res' => $this->res = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->res
            ),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_stream';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<php_stream> $casted_cdata
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
