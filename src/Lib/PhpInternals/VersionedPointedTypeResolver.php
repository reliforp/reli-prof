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

namespace Reli\Lib\PhpInternals;

use Reli\Lib\PhpInternals\Types\Zend\Bucket;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\Process\Pointer\PointedTypeResolver;

final class VersionedPointedTypeResolver implements PointedTypeResolver
{
    /** @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
    public function __construct(
        private string $php_version,
    ) {
    }

    #[\Override]
    public function resolve(string $type_name): string
    {
        return match ($this->php_version) {
            ZendTypeReader::V70,
            ZendTypeReader::V71,
            ZendTypeReader::V72 => match ($type_name) {
                Bucket::class => Types\Zend\V70\Bucket::class,
                ZendArray::class => Types\Zend\V70\ZendArray::class,
                Zval::class => Types\Zend\V70\Zval::class,
                default => $type_name,
            },
            ZendTypeReader::V73 => match ($type_name) {
                Bucket::class => Types\Zend\V73\Bucket::class,
                ZendArray::class => Types\Zend\V73\ZendArray::class,
                Zval::class => Types\Zend\V73\Zval::class,
                default => $type_name,
            },
            ZendTypeReader::V74 => match ($type_name) {
                Bucket::class => Types\Zend\V74\Bucket::class,
                ZendArray::class => Types\Zend\V74\ZendArray::class,
                Zval::class => Types\Zend\V74\Zval::class,
                default => $type_name,
            },
            ZendTypeReader::V80,
            ZendTypeReader::V81 => match ($type_name) {
                ZendArray::class => Types\Zend\V80\ZendArray::class,
                default => $type_name,
            },
            default => $type_name,
        };
    }
}
