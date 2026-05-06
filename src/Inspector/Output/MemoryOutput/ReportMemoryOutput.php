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

use Reli\Inspector\Output\MemoryOutput\Report\Formatter\JsonReportFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\Formatter\ReportFormatterInterface;
use Reli\Inspector\Output\MemoryOutput\Report\Formatter\TextReportFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\ReportGenerator;
use Reli\Inspector\Output\MemoryOutput\Report\ReportResult;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
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
        if ($result->pre_populated_rmem_path !== null) {
            $generator = new ReportGenerator();
            $report = $generator->generateFromBinary($result->pre_populated_rmem_path);
            $this->writeFormatted($report);
            return;
        }

        // No pre-populated rmem (typically a direct-construction
        // result with an in-memory context tree). Stream the tree
        // into a temp .rmem first, then run the same generator.
        $tmp_base = tempnam(sys_get_temp_dir(), 'reli_report_rmem_');
        if ($tmp_base === false) {
            throw new \RuntimeException('Failed to create temporary file for report generation');
        }
        $tmp_path = $tmp_base . '.rmem';
        @unlink($tmp_base);
        try {
            $binary_output = new BinaryMemoryOutput($tmp_path, $this->region_boundaries);
            $sink = $binary_output->createStreamingSink();
            if ($result->context !== null) {
                $analyzer = new ContextAnalyzer();
                $analyzer->analyze($result->context, $sink);
            }
            $binary_output->finalizeStreaming($sink, $result->summary);

            $generator = new ReportGenerator();
            $report = $generator->generateFromBinary($tmp_path);
            $this->writeFormatted($report);
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

    private function writeFormatted(ReportResult $report): void
    {
        $formatted = $this->formatter->format($report);
        if ($this->output_path !== null) {
            file_put_contents($this->output_path, $formatted);
        } else {
            echo $formatted;
        }
    }
}
