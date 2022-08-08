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

namespace PhpProfiler\Lib\PhpInternals\Types\Zend;

use FFI\PhpInternals\zend_execute_data;
use PhpProfiler\Lib\PhpInternals\CastedCData;
use PhpProfiler\Lib\Process\Pointer\Dereferencable;
use PhpProfiler\Lib\Process\Pointer\Dereferencer;
use PhpProfiler\Lib\Process\Pointer\Pointer;

final class ZendExecuteData implements Dereferencable
{
    /** @var Pointer<ZendFunction>|null */
    public ?Pointer $func;

    /** @var Pointer<ZendExecuteData>|null */
    public ?Pointer $prev_execute_data;

    /** @var Pointer<ZendOp>|null */
    public ?Pointer $opline;

    /** @param CastedCData<zend_execute_data> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
    ) {
        unset($this->func);
        unset($this->prev_execute_data);
        unset($this->opline);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'func' => $this->func =
                $this->casted_cdata->casted->func !== null
                ? Pointer::fromCData(
                    ZendFunction::class,
                    $this->casted_cdata->casted->func,
                )
                : null
            ,
            'prev_execute_data' => $this->prev_execute_data =
                $this->casted_cdata->casted->prev_execute_data !== null
                ? Pointer::fromCData(
                    ZendExecuteData::class,
                    $this->casted_cdata->casted->prev_execute_data,
                )
                : null
            ,
            'opline' => $this->opline =
                $this->casted_cdata->casted->opline !== null
                ? Pointer::fromCData(
                    ZendOp::class,
                    $this->casted_cdata->casted->opline
                )
                : null
            ,
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_execute_data';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /** @var CastedCData<zend_execute_data> $casted_cdata */
        return new self($casted_cdata);
    }

    public function getFunctionName(Dereferencer $dereferencer): ?string
    {
        if (is_null($this->func)) {
            return null;
        }
        $func = $dereferencer->deref($this->func);
        return $func->getFullyQualifiedFunctionName($dereferencer);
    }
}
