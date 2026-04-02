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
     * @param \PDO|null $pre_populated_db When the context tree was already streamed
     *                                    to a database during collection, pass the
     *                                    connection here. Output handlers can then
     *                                    skip the in-memory tree walk.
     */
    public function __construct(
        public readonly array $summary,
        public readonly ?ReferenceContext $context,
        public readonly ?array $location_types_summary = null,
        public readonly ?array $class_objects_summary = null,
        public readonly ?\PDO $pre_populated_db = null,
        public readonly ?int $pre_populated_run_id = null,
    ) {
    }
}
