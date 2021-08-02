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

namespace PhpProfiler\Lib\Elf\Structure\Elf64;

/**
 * Class Elf64StringTable
 * @package PhpProfiler\Lib\Elf
 */
final class Elf64StringTable
{
    /**
     * Elf64StringTable constructor.
     */
    public function __construct(
        private string $raw_data
    ) {
    }

    public function lookup(int $start_offset): string
    {
        $end_offset = strpos($this->raw_data, "\0", $start_offset);
        if ($end_offset === false) {
            return '';
        }
        return substr($this->raw_data, $start_offset, $end_offset - $start_offset);
    }
}
