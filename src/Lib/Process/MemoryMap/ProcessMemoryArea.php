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

namespace PhpProfiler\Lib\Process\MemoryMap;

/**
 * Class ProcessMemoryArea
 * @package PhpProfiler\ProcessReader
 */
final class ProcessMemoryArea
{
    public string $begin;
    public string $end;
    public string $file_offset;
    public ProcessMemoryAttribute $attribute;
    public string $name;

    public function __construct(
        string $begin,
        string $end,
        string $file_offset,
        ProcessMemoryAttribute $attribute,
        string $name
    ) {
        $this->begin = $begin;
        $this->end = $end;
        $this->file_offset = $file_offset;
        $this->attribute = $attribute;
        $this->name = $name;
    }

    public function isInRange(int $address): bool
    {
        return $address >= hexdec($this->begin)
            and $address <= hexdec($this->end);
    }
}
