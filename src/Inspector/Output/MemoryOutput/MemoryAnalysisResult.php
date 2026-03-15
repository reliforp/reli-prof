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

namespace Reli\Inspector\Output\MemoryOutput;

final class MemoryAnalysisResult
{
    /**
     * @param array<int, array<string, mixed>> $summary
     * @param array<string, array{count: int, memory_usage: int}> $location_types_summary
     * @param array<string, array{count: int, memory_usage: int}> $class_objects_summary
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly array $summary,
        public readonly array $location_types_summary,
        public readonly array $class_objects_summary,
        public readonly array $context,
    ) {
    }
}
