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

namespace Reli\Lib\Process\MemoryMap;

use Reli\Lib\File\FileReaderInterface;

final class ProcessMemoryMapReader
{
    public function __construct(
        private FileReaderInterface $file_reader,
    ) {
    }
    public function read(int $process_id): string
    {
        return $this->file_reader->readAll("/proc/{$process_id}/maps");
    }
}
