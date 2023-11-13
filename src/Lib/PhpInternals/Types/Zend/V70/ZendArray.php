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

namespace Reli\Lib\PhpInternals\Types\Zend\V70;

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
            foreach ($this->getBucketIterator($array_dereferencer) as $key => $bucket) {
                if ($bucket->val->isUndef()) {
                    continue;
                }
                yield $bucket->key ?? $key => $bucket->val;
            }
        }
    }

    public function dumpFlags(): string
    {
        $flags = $this->flags;
        $flag_names = [];
        if ($flags & (1 << 0)) {
            $flag_names[] = 'HASH_FLAG_PERSISTENT';
        }
        if ($flags & (1 << 1)) {
            $flag_names[] = 'HASH_FLAG_APPLY_PROTECTION';
        }
        if ($flags & (1 << 2)) {
            $flag_names[] = 'HASH_FLAG_PACKED';
        }
        if ($flags & (1 << 3)) {
            $flag_names[] = 'HASH_FLAG_INITIALIZED';
        }
        if ($flags & (1 << 4)) {
            $flag_names[] = 'HASH_FLAG_STATIC_KEYS';
        }
        if ($flags & (1 << 5)) {
            $flag_names[] = 'HASH_FLAG_HAS_EMPTY_IND';
        }
        if ($flags & (1 << 6)) {
            $flag_names[] = 'HASH_FLAG_ALLOW_COW_VIOLATION';
        }

        return implode(' | ', $flag_names);
    }

    public function isUninitialized(): bool
    {
        return !($this->flags & (1 << 3));
    }

    public function getDataSize(): int
    {
        return $this->nTableSize * self::BUCKET_SIZE_IN_BYTES;
    }
}
