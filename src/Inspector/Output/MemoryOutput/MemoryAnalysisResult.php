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
     * @param string|null $pre_populated_rmem_path When the context tree was already
     *                                             streamed to an .rmem file during
     *                                             collection, pass its path here.
     *                                             Output handlers read from the
     *                                             rmem file instead of walking the
     *                                             in-memory tree. This is the
     *                                             canonical streaming path; the
     *                                             optional `$context` is only used
     *                                             by direct-construction tests that
     *                                             pre-build a tree in memory.
     */
    public function __construct(
        public readonly array $summary,
        public readonly ?ReferenceContext $context,
        public readonly ?array $location_types_summary = null,
        public readonly ?array $class_objects_summary = null,
        public readonly ?string $pre_populated_rmem_path = null,
    ) {
    }
}
