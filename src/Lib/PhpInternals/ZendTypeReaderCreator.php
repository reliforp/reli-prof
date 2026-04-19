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

final class ZendTypeReaderCreator
{
    /** @var array<string, ZendTypeReader> */
    private array $cache = [];

    /** @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
    public function create(string $php_version): ZendTypeReader
    {
        return $this->cache[$php_version] ??= new ZendTypeReader($php_version);
    }
}
