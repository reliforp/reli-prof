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

use Reli\Lib\PhpInternals\Types\Zend\Bucket;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray as BaseZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
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

    /** @return iterable<array-key|Pointer<ZendString>, Zval> */
    public function getItemIteratorWithZendStringKeyIfAssoc(Dereferencer $array_dereferencer): iterable
    {
        if (!$this->isUninitialized()) {
            foreach ($this->getBucketIterator($array_dereferencer) as $i => $bucket) {
                if ($bucket->val->isUndef()) {
                    continue;
                }
                yield $bucket->key ?? $i => $bucket->val;
            }
        }
    }

    public function getDataSize(): int
    {
        return $this->nTableSize * self::BUCKET_SIZE_IN_BYTES;
    }
}
