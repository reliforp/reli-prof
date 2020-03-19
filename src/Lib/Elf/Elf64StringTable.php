<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace PhpProfiler\Lib\Elf;

/**
 * Class Elf64StringTable
 * @package PhpProfiler\Lib\Elf
 */
class Elf64StringTable
{
    /** @var string */
    public string $raw_data;

    /**
     * Elf64StringTable constructor.
     * @param string $raw_data
     */
    public function __construct(string $raw_data)
    {
        $this->raw_data = $raw_data;
    }

    public function lookup(int $start_offset)
    {
        $end_offset = strpos($this->raw_data, "\0", $start_offset);
        return substr($this->raw_data, $start_offset, $end_offset - $start_offset);
    }
}