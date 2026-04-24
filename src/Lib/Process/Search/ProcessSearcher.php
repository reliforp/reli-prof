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
use Reli\Lib\Process\ProcFileSystem\ThreadNameReader;

final class ProcessSearcher implements ProcessSearcherInterface
{
    public function __construct(
        private FileReaderInterface $file_reader,
        private ThreadEnumerator $thread_enumerator,
        private ThreadNameReader $thread_name_reader = new ThreadNameReader(),
    ) {
    }

    /**
     * @param non-empty-string $regex
     * @param non-empty-string|null $thread_name_regex
     * @return int[]
     */
    #[\Override]
    public function searchByRegex(string $regex, ?string $thread_name_regex = null): array
    {
        $result = [];

        foreach (new CommandLineEnumerator($this->file_reader) as $pid => $command_line) {
            if (!preg_match($regex, $command_line)) {
                continue;
            }
            foreach ($this->thread_enumerator->getThreadIds($pid) as $tid) {
                if ($thread_name_regex !== null && !$this->threadNameMatches($tid, $thread_name_regex)) {
                    continue;
                }
                $result[] = $tid;
            }
        }

        return $result;
    }

    /** @param non-empty-string $regex */
    private function threadNameMatches(int $tid, string $regex): bool
    {
        $comm = $this->thread_name_reader->read($tid);
        if ($comm === null) {
            return false;
        }
        return preg_match($regex, $comm) === 1;
    }
}
