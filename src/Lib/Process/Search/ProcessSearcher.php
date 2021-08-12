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

namespace PhpProfiler\Lib\Process\Search;

use PhpProfiler\Lib\File\FileReaderInterface;
use PhpProfiler\Lib\Process\ProcFileSystem\CommandLineEnumerator;

final class ProcessSearcher implements ProcessSearcherInterface
{
    public function __construct(
        private FileReaderInterface $file_reader
    ) {
    }

    /**
     * @param string $regex
     * @return int[]
     */
    public function searchByRegex(string $regex): array
    {
        $result = [];

        foreach (new CommandLineEnumerator($this->file_reader) as $pid => $command_line) {
            if (preg_match($regex, $command_line)) {
                $result[] = $pid;
            }
        }

        return $result;
    }
}
