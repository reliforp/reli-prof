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

use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\Report\Formatter\JsonReportFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\Formatter\ReportFormatterInterface;
use Reli\Inspector\Output\MemoryOutput\Report\Formatter\TextReportFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\ReportGenerator;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

final class ReportMemoryOutput implements MemoryOutputInterface
{
    public function __construct(
        private ReportFormatterInterface $formatter,
        private ?RegionBoundaries $region_boundaries = null,
        private ?string $output_path = null,
    ) {
    }

    #[\Override]
    public function output(MemoryAnalysisResult $result): void
    {
        // Write to a temporary SQLite file first
        $tmp_base = tempnam(sys_get_temp_dir(), 'reli_report_');
        if ($tmp_base === false) {
            throw new \RuntimeException('Failed to create temporary file for report generation');
        }
        $tmp_path = $tmp_base . '.sqlite3';
        @unlink($tmp_base);
        try {
            $sqlite_driver = new SqliteDriver($tmp_path);
            $pdo_output = new PdoMemoryOutput($sqlite_driver, $this->region_boundaries);
            $pdo_output->output($result);

            // Now generate the report from the SQLite DB
            $db = new \PDO("sqlite:{$tmp_path}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $generator = new ReportGenerator();
            $report = $generator->generateFromDb($db, 1);
            $formatted = $this->formatter->format($report);

            if ($this->output_path !== null) {
                file_put_contents($this->output_path, $formatted);
            } else {
                echo $formatted;
            }
        } finally {
            if (file_exists($tmp_path)) {
                @unlink($tmp_path);
            }
        }
    }

    public static function text(
        ?RegionBoundaries $region_boundaries = null,
        ?string $output_path = null,
    ): self {
        return new self(new TextReportFormatter(), $region_boundaries, $output_path);
    }

    public static function json(
        bool $pretty_print = false,
        ?RegionBoundaries $region_boundaries = null,
        ?string $output_path = null,
    ): self {
        return new self(new JsonReportFormatter($pretty_print), $region_boundaries, $output_path);
    }
}
