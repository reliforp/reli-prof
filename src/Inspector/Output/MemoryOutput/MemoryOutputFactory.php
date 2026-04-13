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
use Reli\Inspector\Output\MemoryOutput\PdoDriver\PdoDriverInterface;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\PostgreSqlDriver;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

final class MemoryOutputFactory
{
    /**
     * Create a streaming sink that writes directly to the final output DB
     * when the output format is a database, or to a temp SQLite otherwise.
     *
     * @return array{PdoMemoryOutput, PdoContextTreeSink, int, \PDO, string|null}
     *         [pdo_output, sink, run_id, db, temp_path (null if writing to final DB)]
     */
    public function createStreamingSink(
        MemoryProfilerSettings $settings,
        ?RegionBoundaries $region_boundaries = null,
    ): array {
        $driver = $this->createPdoDriver($settings);
        $temp_path = null;
        if ($driver === null) {
            // Non-DB format: use temp SQLite
            $tmp_base = tempnam(sys_get_temp_dir(), 'reli_stream_');
            if ($tmp_base === false) {
                throw new \RuntimeException('Failed to create temporary file');
            }
            $temp_path = $tmp_base . '.sqlite3';
            @unlink($tmp_base);
            $driver = new SqliteDriver($temp_path);
        }
        $pdo_output = new PdoMemoryOutput($driver, $region_boundaries);
        [$sink, $run_id, $db] = $pdo_output->createStreamingSink();
        return [$pdo_output, $sink, $run_id, $db, $temp_path];
    }

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
            'report' => ReportMemoryOutput::text($region_boundaries, $settings->output_path),
            'report-json' => ReportMemoryOutput::json(
                $settings->pretty_print,
                $region_boundaries,
                $settings->output_path,
            ),
            'binary' => new BinaryMemoryOutput(
                $settings->output_path ?? throw new \RuntimeException(
                    '--output is required when using binary format'
                ),
                $region_boundaries,
            ),
            default => throw new \RuntimeException(
                "unsupported output format: {$settings->output_format}"
                . " (supported: json, sqlite3, binary, mysql, postgresql, report, report-json)"
            ),
        };
    }

    /**
     * Check if the given output format is the binary (.rmem) format.
     */
    public static function isBinaryFormat(MemoryProfilerSettings $settings): bool
    {
        return $settings->output_format === 'binary';
    }

    /**
     * Create a binary streaming sink.
     *
     * @return array{BinaryMemoryOutput, BinaryContextTreeSink}
     */
    public function createBinaryStreamingSink(
        MemoryProfilerSettings $settings,
        ?RegionBoundaries $region_boundaries = null,
    ): array {
        $output_path = $settings->output_path ?? throw new \RuntimeException(
            '--output is required when using binary format'
        );
        $binary_output = new BinaryMemoryOutput($output_path, $region_boundaries);
        $sink = $binary_output->createStreamingSink();
        return [$binary_output, $sink];
    }

    /**
     * Returns a PdoDriver for DB output formats, or null for non-DB formats.
     */
    private function createPdoDriver(MemoryProfilerSettings $settings): ?PdoDriverInterface
    {
        return match ($settings->output_format) {
            'sqlite3' => new SqliteDriver(
                $settings->output_path ?? throw new \RuntimeException(
                    '--output is required when using sqlite3 format'
                ),
            ),
            'mysql' => new MySqlDriver(
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
            'postgresql' => new PostgreSqlDriver(
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
            default => null,
        };
    }
}
