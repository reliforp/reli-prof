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

namespace Reli\Lib\PhpInternals\Types\Zend\V80;

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals as BaseZendExecutorGlobals;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendExecutorGlobals extends BaseZendExecutorGlobals implements Dereferencable
{
    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'symbol_table' => $this->symbol_table = new ZendArray(
                new CastedCData(
                    $this->casted_cdata->casted->symbol_table,
                    $this->casted_cdata->casted->symbol_table
                ),
                new Pointer(
                    ZendArray::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('symbol_table'),
                    \FFI::sizeof($this->casted_cdata->casted->symbol_table),
                ),
            ),
            'included_files' => $this->included_files = new ZendArray(
                new CastedCData(
                    $this->casted_cdata->casted->included_files,
                    $this->casted_cdata->casted->included_files
                ),
                new Pointer(
                    ZendArray::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('included_files'),
                    \FFI::sizeof($this->casted_cdata->casted->included_files),
                ),
            ),
            default => parent::__get($field_name),
        };
    }
}
