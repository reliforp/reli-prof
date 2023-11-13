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

use Reli\Lib\PhpInternals\Types\Zend\V73\ZendArray as BaseZendArray;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendArray extends BaseZendArray implements Dereferencable
{
    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'arData' => $this->arData = $this->casted_cdata->casted->arData !== null
                ? Pointer::fromCData(
                    Bucket::class,
                    $this->casted_cdata->casted->arData,
                )
                : null
            ,
            default => parent::__get($field_name),
        };
    }

    public function getDataSize(): int
    {
        return $this->nTableSize * self::BUCKET_SIZE_IN_BYTES;
    }
}
