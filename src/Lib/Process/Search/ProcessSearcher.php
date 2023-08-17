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

namespace Reli\Lib\Process\Search;

use Reli\Lib\File\FileReaderInterface;
use Reli\Lib\Process\ProcFileSystem\CommandLineEnumerator;
use Reli\Lib\Process\ProcFileSystem\ThreadEnumerator;

final class ProcessSearcher implements ProcessSearcherInterface
{
    public function __construct(
        private FileReaderInterface $file_reader,
        private ThreadEnumerator $thread_enumerator,
    ) {
    }

    /**
     * @param non-empty-string $regex
     * @return int[]
     */
    public function searchByRegex(string $regex): array
    {
        $result = [];

        foreach (new CommandLineEnumerator($this->file_reader) as $pid => $command_line) {
            if (preg_match($regex, $command_line)) {
                $result = \array_merge($result, iterator_to_array(
                    $this->thread_enumerator->getThreadIds($pid)
                ));
            }
        }

        return $result;
    }
}
