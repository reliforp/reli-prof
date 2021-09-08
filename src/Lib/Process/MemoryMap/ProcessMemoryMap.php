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

final class ProcessMemoryMap
{
    /** @param ProcessMemoryArea[] $memory_areas */
    public function __construct(
        private array $memory_areas,
    ) {
    }

    /** @return ProcessMemoryArea[] */
    public function findByNameRegex(string $regex): array
    {
        $result = [];
        foreach ($this->memory_areas as $memory_area) {
            if (preg_match($regex, $memory_area->name)) {
                $result[] = $memory_area;
            }
        }
        return $result;
    }
}
