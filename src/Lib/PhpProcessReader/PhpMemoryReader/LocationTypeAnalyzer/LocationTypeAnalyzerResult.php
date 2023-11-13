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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\LocationTypeAnalyzer;

use Reli\Lib\Process\MemoryLocation;

final class LocationTypeAnalyzerResult
{
    /**
     * @psalm-type PerTypeUsage = array{count: int, memory_usage: int}
     * @param array<string, PerTypeUsage> $per_type_usage
     */
    public function __construct(
        public array $per_type_usage,
    ) {
    }
}
