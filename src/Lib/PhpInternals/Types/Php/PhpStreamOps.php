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

use FFI\PhpInternals\php_stream_ops;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PhpStreamOps implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $label;

    /**
     * @param CastedCData<php_stream_ops> $casted_cdata
     * @param Pointer<PhpStreamOps> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->label);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'label' => $this->label = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->label
            ),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_stream_ops';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<php_stream_ops> $casted_cdata
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
