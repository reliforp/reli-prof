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

namespace Reli\Lib\PhpInternals\Types\Zend;

use FFI\PhpInternals\zend_executor_globals;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendExecutorGlobals implements Dereferencable
{
    /** @var Pointer<ZendExecuteData>|null */
    public ?Pointer $current_execute_data;

    /**
     * @param CastedCData<zend_executor_globals> $casted_cdata
     * @param Pointer<ZendExecutorGlobals> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->current_execute_data);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'current_execute_data' => $this->casted_cdata->casted->current_execute_data !== null
                ? Pointer::fromCData(
                    ZendExecuteData::class,
                    $this->casted_cdata->casted->current_execute_data,
                )
                : null
            ,
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_executor_globals';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<zend_executor_globals> $casted_cdata
         * @var Pointer<ZendExecutorGlobals> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendExecutorGlobals> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
