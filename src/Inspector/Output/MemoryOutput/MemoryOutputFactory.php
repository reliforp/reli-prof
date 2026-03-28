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

use Reli\Inspector\Output\MemoryOutput\PdoDriver\MySqlDriver;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\PostgreSqlDriver;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ParallelContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

final class MemoryOutputFactory
{
    public function create(
        MemoryProfilerSettings $settings,
        ?RegionBoundaries $region_boundaries = null,
    ): MemoryOutputInterface {
        $parallel = ParallelContextAnalyzer::isAvailable();

        return match ($settings->output_format) {
            'json' => new JsonMemoryOutput($settings->pretty_print, $settings->output_path),
            // SQLite: temp-file merge overhead negates parallelism gains.
            'sqlite3' => new PdoMemoryOutput(
                new SqliteDriver(
                    $settings->output_path ?? throw new \RuntimeException(
                        '--output is required when using sqlite3 format'
                    ),
                ),
                $region_boundaries,
            ),
            'mysql' => new PdoMemoryOutput(
                new MySqlDriver(
                    $settings->db_host,
                    $settings->db_port ?? 3306,
                    $settings->db_name ?? throw new \RuntimeException(
                        '--db-name is required when using mysql format'
                    ),
                    $settings->db_user ?? throw new \RuntimeException(
                        '--db-user is required when using mysql format'
                    ),
                    $settings->db_password ?? '',
                ),
                $region_boundaries,
                $parallel,
            ),
            'postgresql' => new PdoMemoryOutput(
                new PostgreSqlDriver(
                    $settings->db_host,
                    $settings->db_port ?? 5432,
                    $settings->db_name ?? throw new \RuntimeException(
                        '--db-name is required when using postgresql format'
                    ),
                    $settings->db_user ?? throw new \RuntimeException(
                        '--db-user is required when using postgresql format'
                    ),
                    $settings->db_password ?? '',
                ),
                $region_boundaries,
                $parallel,
            ),
            default => throw new \RuntimeException(
                "unsupported output format: {$settings->output_format} (supported: json, sqlite3, mysql, postgresql)"
            ),
        };
    }
}
