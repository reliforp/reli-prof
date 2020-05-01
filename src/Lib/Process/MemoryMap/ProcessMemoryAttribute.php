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
 * Class ProcessMemoryAttribute
 * @package PhpProfiler\ProcessReader
 */
final class ProcessMemoryAttribute
{
    public bool $read;
    public bool $write;
    public bool $execute;
    public bool $protected;

    /**
     * ProcessMemoryAttribute constructor.
     * @param bool $read
     * @param bool $write
     * @param bool $execute
     * @param bool $protected
     */
    public function __construct(bool $read, bool $write, bool $execute, bool $protected)
    {
        $this->read = $read;
        $this->write = $write;
        $this->execute = $execute;
        $this->protected = $protected;
    }
}
