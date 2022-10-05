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

namespace PhpProfiler\Lib\File;

/**
 * workaround for a problem that PHP cannot open files in /proc/<pid>/root/
 */
final class CatFileReader implements FileReaderInterface
{
    public function readAll(string $path): string
    {
        /** @psalm-suppress InvalidArgument */
        $process = proc_open(
            [
                'cat',
                $path
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        $contents = stream_get_contents($pipes[1]);
        proc_close($process);

        return $contents;
    }
}
