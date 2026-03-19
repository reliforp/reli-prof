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

use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencable;

final class VersionedTypeMap
{
    /**
     * @template T of Dereferencable
     * @param class-string<T> $base_type
     * @return class-string<T>
     */
    public static function resolve(string $php_version, string $base_type): string
    {
        return match ($php_version) {
            ZendTypeReader::V70,
            ZendTypeReader::V71,
            ZendTypeReader::V72 => match ($base_type) {
                Bucket::class => V70\Bucket::class,
                ZendArray::class => V70\ZendArray::class,
                Zval::class => V70\Zval::class,
                ZendExecutorGlobals::class => V70\ZendExecutorGlobals::class,
                default => $base_type,
            },
            ZendTypeReader::V73 => match ($base_type) {
                Bucket::class => V73\Bucket::class,
                ZendArray::class => V73\ZendArray::class,
                Zval::class => V73\Zval::class,
                ZendExecutorGlobals::class => V73\ZendExecutorGlobals::class,
                default => $base_type,
            },
            ZendTypeReader::V74 => match ($base_type) {
                Bucket::class => V74\Bucket::class,
                ZendArray::class => V74\ZendArray::class,
                Zval::class => V74\Zval::class,
                ZendExecutorGlobals::class => V74\ZendExecutorGlobals::class,
                default => $base_type,
            },
            ZendTypeReader::V80,
            ZendTypeReader::V81 => match ($base_type) {
                ZendArray::class => V80\ZendArray::class,
                ZendExecutorGlobals::class => V80\ZendExecutorGlobals::class,
                default => $base_type,
            },
            default => $base_type,
        };
    }
}
