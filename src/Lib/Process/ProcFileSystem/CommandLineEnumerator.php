<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Process\ProcFileSystem;

use IteratorAggregate;
use PhpProfiler\Lib\File\FileReaderInterface;

final class CommandLineEnumerator implements IteratorAggregate
{
    public function __construct(
        private FileReaderInterface $file_reader
    ) {
    }

    /** @return \Generator<int, string> */
    public function getIterator()
    {
        /**
         * @var string $full_path
         * @var \SplFileInfo $item
         */
        foreach (new \GlobIterator('/proc/*/cmdline') as $full_path => $item) {
            if (file_exists($full_path) === false) {
                continue;
            }
            $command_line = $this->file_reader->readAll($full_path);
            if ($command_line === '') {
                continue;
            }
            if (!is_numeric(basename($item->getPath()))) {
                continue;
            }
            yield (int)basename($item->getPath()) => preg_replace('/\0/', ' ', $command_line);
        }
    }
}
