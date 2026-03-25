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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;

final class MemoryAnalysisResult
{
    /**
     * @param array<int, array<string, mixed>> $summary
     * @param array<string, array{count: int, memory_usage: int}>|null $location_types_summary
     * @param array<string, array{count: int, memory_usage: int}>|null $class_objects_summary
     */
    public function __construct(
        public readonly array $summary,
        public readonly ReferenceContext $context,
        public readonly ?array $location_types_summary = null,
        public readonly ?array $class_objects_summary = null,
    ) {
    }
}
