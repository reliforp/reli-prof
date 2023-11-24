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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ObjectClassAnalyzer;

final class ObjectClassAnalyzerResult
{
    /**
     * @psalm-type PerClassUsage = array{count: int, memory_usage: int}
     * @param array<string, PerClassUsage> $per_class_usage
     */
    public function __construct(
        public array $per_class_usage,
    ) {
    }
}
