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

use FFI\PhpInternals\MYSQLND_RES_BUFFERED;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class MysqlndResBuffered implements CDataDereferencable
{
    /**
     * @param CastedCData<MYSQLND_RES_BUFFERED> $casted_cdata
     * @param Pointer<MysqlndResBuffered> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'MYSQLND_RES_BUFFERED';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<MYSQLND_RES_BUFFERED> $casted_cdata
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
