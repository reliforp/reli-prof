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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

final class MemoryOutputFactory
{
    public function create(
        string $format,
        bool $pretty_print,
        ?string $output_path,
        ?RegionBoundaries $region_boundaries = null,
    ): MemoryOutputInterface {
        return match ($format) {
            'json' => new JsonMemoryOutput($pretty_print, $output_path),
            'sqlite3' => new SqliteMemoryOutput(
                $output_path ?? throw new \RuntimeException(
                    '--output is required when using sqlite3 format'
                ),
                $region_boundaries,
            ),
            default => throw new \RuntimeException(
                "unsupported output format: {$format} (supported: json, sqlite3)"
            ),
        };
    }
}
