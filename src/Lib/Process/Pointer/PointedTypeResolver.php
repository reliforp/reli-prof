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

namespace Reli\Lib\Process\Pointer;

interface PointedTypeResolver
{
    /**
     * @template T of Dereferencable
     * @param class-string<T> $type_name
     * @return class-string<T>
     */
    public function resolve(string $type_name): string;
}
