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

/**
 * workaround for a problem that PHP cannot open files in /proc/<pid>/root/
 */
final class CatFileReader implements FileReaderInterface
{
    #[\Override]
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
        if ($process === false) {
            throw new \RuntimeException("Failed to open process for: {$path}");
        }
        $contents = stream_get_contents($pipes[1]);
        proc_close($process);

        if ($contents === false) {
            throw new \RuntimeException("Failed to read contents of: {$path}");
        }

        return $contents;
    }

    #[\Override]
    public function readSlice(string $path, int $offset, int $size): string
    {
        // CatFileReader is the proc_open(cat) fallback; we don't shell out
        // to dd here, so just slice the full read. Callers in the cold-attach
        // hot path use NativeFileReader anyway.
        $all = $this->readAll($path);
        if ($offset < 0 || $offset >= strlen($all)) {
            return '';
        }
        return substr($all, $offset, $size);
    }
}
