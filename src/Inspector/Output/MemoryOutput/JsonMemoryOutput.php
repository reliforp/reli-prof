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

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Report\BinaryReportDataProvider;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

final class JsonMemoryOutput implements MemoryOutputInterface
{
    public function __construct(
        private bool $pretty_print = false,
        private ?string $output_path = null,
        private ?RegionBoundaries $region_boundaries = null,
    ) {
    }

    #[\Override]
    public function output(MemoryAnalysisResult $result): void
    {
        if ($result->pre_populated_rmem_path !== null) {
            $this->streamJsonFromRmem($result->pre_populated_rmem_path, $result);
            return;
        }

        // No pre-populated rmem (typically a direct-construction
        // result from a test or programmatic caller with an
        // in-memory context tree). Stream the tree into a temp
        // .rmem first, then export from there — same code path as
        // the pre-populated case, just with the rmem materialised
        // on the fly.
        $tmp_base = tempnam(sys_get_temp_dir(), 'reli_json_rmem_');
        if ($tmp_base === false) {
            throw new \RuntimeException('Failed to create temporary file for JSON export');
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

            $rerouted = new MemoryAnalysisResult(
                summary: $result->summary,
                context: null,
                location_types_summary: $result->location_types_summary,
                class_objects_summary: $result->class_objects_summary,
                pre_populated_rmem_path: $tmp_path,
            );
            $this->streamJsonFromRmem($tmp_path, $rerouted);
        } finally {
            if (file_exists($tmp_path)) {
                @unlink($tmp_path);
            }
        }
    }

    private function streamJsonFromRmem(string $rmem_path, MemoryAnalysisResult $result): void
    {
        $reader = BinaryReader::open($rmem_path);
        $summary = $result->summary;
        $location_types_summary = $result->location_types_summary
            ?? BinaryReportDataProvider::computeLocationTypesSummary($reader);
        $class_objects_summary = $result->class_objects_summary
            ?? BinaryReportDataProvider::computeClassObjectsSummary($reader);

        $exporter = new StreamingJsonFromRmemExporter($reader, $this->pretty_print);

        $this->exportToTarget(
            fn ($fp) => $exporter->export(
                $summary,
                $location_types_summary,
                $class_objects_summary,
                $fp,
            ),
        );
    }

    /**
     * @param callable(resource): void $writer
     */
    private function exportToTarget(callable $writer): void
    {
        if ($this->output_path !== null) {
            $fp = fopen($this->output_path, 'w');
            if ($fp === false) {
                throw new \RuntimeException("Cannot open output file: {$this->output_path}");
            }
            try {
                $writer($fp);
            } finally {
                fclose($fp);
            }
        } else {
            $fp = fopen('php://stdout', 'w');
            if ($fp === false) {
                throw new \RuntimeException('Cannot open stdout');
            }
            $writer($fp);
        }
    }
}
