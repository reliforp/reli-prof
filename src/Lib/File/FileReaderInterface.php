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

namespace Reli\Lib\File;

interface FileReaderInterface
{
    public function readAll(string $path): string;

    /**
     * Read up to $size bytes starting at $offset. Implementations may return
     * fewer bytes than requested if the file ends before $offset + $size.
     * Returns an empty string when the file cannot be opened or the offset
     * is past EOF.
     */
    public function readSlice(string $path, int $offset, int $size): string;
}
