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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

final class MemoryOutputFactory
{
    public function create(
        MemoryProfilerSettings $settings,
        ?RegionBoundaries $region_boundaries = null,
    ): MemoryOutputInterface {
        return match ($settings->output_format) {
            'json' => new JsonMemoryOutput($settings->pretty_print, $settings->output_path, $region_boundaries),
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
            ),
            'meminfo' => new PhpMeminfoMemoryOutput(
                $settings->pretty_print,
                $settings->output_path,
                $region_boundaries,
            ),
            'report' => ReportMemoryOutput::text($region_boundaries, $settings->output_path),
            'report-json' => ReportMemoryOutput::json(
                $settings->pretty_print,
                $region_boundaries,
                $settings->output_path,
            ),
            'rmem' => new BinaryMemoryOutput(
                $settings->output_path ?? throw new \RuntimeException(
                    '--output is required when using rmem format'
                ),
                $region_boundaries,
            ),
            default => throw new \RuntimeException(
                "unsupported output format: {$settings->output_format}"
                . " (supported: json, sqlite3, rmem, mysql, postgresql, report, report-json, meminfo)"
            ),
        };
    }

    /**
     * Check if the given output format is the rmem (.rmem) format.
     */
    public static function isRmemFormat(MemoryProfilerSettings $settings): bool
    {
        return $settings->output_format === 'rmem';
    }

    /**
     * Check if the given output format writes directly to a database
     * (sqlite3 / mysql / postgresql). DB formats keep the legacy
     * PDO-based streaming path; everything else (json / report /
     * report-json) routes through an rmem intermediate.
     */
    public static function isDbFormat(MemoryProfilerSettings $settings): bool
    {
        return match ($settings->output_format) {
            'sqlite3', 'mysql', 'postgresql' => true,
            default => false,
        };
    }

    /**
     * Create a binary streaming sink that writes to a caller-provided
     * temporary .rmem path. Used for non-DB, non-rmem output formats
     * (json / report / report-json) where the rmem file is an
     * intermediate that is converted to the final format and then
     * deleted.
     *
     * @return array{BinaryMemoryOutput, BinaryContextTreeSink}
     */
    public function createBinaryStreamingSinkAtPath(
        string $output_path,
        ?RegionBoundaries $region_boundaries = null,
    ): array {
        $binary_output = new BinaryMemoryOutput($output_path, $region_boundaries);
        $sink = $binary_output->createStreamingSink();
        return [$binary_output, $sink];
    }
}
