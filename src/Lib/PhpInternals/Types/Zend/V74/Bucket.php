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

namespace Reli\Lib\PhpInternals\Types\Zend\V74;

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\Zend\Bucket as BaseBucket;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class Bucket extends BaseBucket implements Dereferencable
{
    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'val' => $this->val = new Zval(
                new CastedCData(
                    $this->casted_cdata->casted->val,
                    $this->casted_cdata->casted->val,
                ),
                new Pointer(
                    Zval::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('val'),
                    \FFI::sizeof($this->casted_cdata->casted->val),
                ),
            ),
            default => parent::__get($field_name),
        };
    }
}
