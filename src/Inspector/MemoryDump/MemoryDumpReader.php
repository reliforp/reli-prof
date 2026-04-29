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

namespace Reli\Inspector\MemoryDump;

use Reli\Inspector\Output\MemoryOutput\MemoryAnalysisResult;
use Reli\Inspector\Output\MemoryOutput\MemoryOutputFactory;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\CollectedMemories;
use Reli\Inspector\MemoryDump\FastPath\FastPathReader;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionsSummary;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\ReliProfiler;

final class MemoryDumpReader
{
    /** @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
    public function __construct(
        private MemoryLocationsCollector $memory_locations_collector,
        private int $pid,
        private string $php_version,
        private int $eg_address,
        private int $cg_address,
        private ?int $bg_address = null,
        private ?int $rss_bytes = null,
        private ?FastPathReader $fast_path = null,
    ) {
    }

    public function read(
        MemoryProfilerSettings $memory_profiler_settings
    ): void {
        if (MemoryOutputFactory::isRmemFormat($memory_profiler_settings)) {
            $this->readBinary($memory_profiler_settings);
        } else {
            $this->readPdo($memory_profiler_settings);
        }
    }

    /**
     * Original PDO-based streaming path (SQLite / MySQL / PostgreSQL / JSON / report).
     */
    private function readPdo(MemoryProfilerSettings $memory_profiler_settings): void
    {
        $process_specifier = new ProcessSpecifier($this->pid);

        /** @var TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
        $target_php_settings = new TargetPhpSettings(php_version: $this->php_version);

        $output_factory = new MemoryOutputFactory();
        [$pdo_output, $sink, $run_id, $db, $temp_path] = $output_factory->createStreamingSink(
            $memory_profiler_settings,
        );

        try {
            $collected_memories = $this->memory_locations_collector->collectAll(
                $process_specifier,
                $target_php_settings,
                $this->eg_address,
                $this->cg_address,
                $memory_profiler_settings->memory_exhaustion_error_details,
                $this->bg_address,
                $sink,
                $this->fast_path,
            );

            $region_boundaries = new RegionBoundaries(
                $collected_memories->chunk_memory_locations,
                $collected_memories->huge_memory_locations,
                $collected_memories->vm_stack_memory_locations,
                $collected_memories->compiler_arena_memory_locations,
            );

            $region_analyzer = new RegionAnalyzer(
                $collected_memories->chunk_memory_locations,
                $collected_memories->huge_memory_locations,
                $collected_memories->vm_stack_memory_locations,
                $collected_memories->compiler_arena_memory_locations,
            );

            $analyzed_regions = $region_analyzer->analyze(
                $collected_memories->memory_locations,
            );

            $sink->flush();
            $region_boundaries->backfillRegions($db, $run_id);
            $_region_result = RegionsSummary::queryRegionSums($db, $run_id);
            $region_sums = $_region_result['sums'];
            $summary_base = $region_sums !== []
                ? $analyzed_regions->summary->correctedToArray($region_sums)
                : $analyzed_regions->summary->toArray();

            $summary = $this->buildSummary(
                $summary_base,
                $collected_memories,
                $this->rss_bytes,
                $target_php_settings,
            );

            unset($collected_memories, $analyzed_regions, $region_analyzer);

            $pdo_output->finalizeStreaming($db, $run_id, $sink, $summary);

            if ($temp_path !== null) {
                $result = new MemoryAnalysisResult(
                    $summary,
                    null,
                    null,
                    null,
                    $db,
                    $run_id,
                );

                $memory_output = $output_factory->create(
                    $memory_profiler_settings,
                    $region_boundaries,
                );
                $memory_output->output($result);
            }
        } finally {
            if ($temp_path !== null && file_exists($temp_path)) {
                @unlink($temp_path);
            }
        }
    }

    /**
     * Binary (.rmem) streaming path.
     *
     * Collects with a BinaryContextTreeSink that streams to temp files,
     * then assembles the final .rmem. No SQLite intermediate.
     */
    private function readBinary(MemoryProfilerSettings $memory_profiler_settings): void
    {
        $process_specifier = new ProcessSpecifier($this->pid);

        /** @var TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
        $target_php_settings = new TargetPhpSettings(php_version: $this->php_version);

        $output_factory = new MemoryOutputFactory();
        [$binary_output, $sink] = $output_factory->createBinaryStreamingSink(
            $memory_profiler_settings,
        );

        $collected_memories = $this->memory_locations_collector->collectAll(
            $process_specifier,
            $target_php_settings,
            $this->eg_address,
            $this->cg_address,
            $memory_profiler_settings->memory_exhaustion_error_details,
            $this->bg_address,
            $sink,
            $this->fast_path,
        );

        // RegionBoundaries is already set on the sink by collectAll()
        // (MemoryLocationsCollector builds it from chunk/huge/vm_stack/
        // compiler_arena before the emit loop starts), so all locations
        // are written with correct region_id inline — no backfill needed.

        $region_analyzer = new RegionAnalyzer(
            $collected_memories->chunk_memory_locations,
            $collected_memories->huge_memory_locations,
            $collected_memories->vm_stack_memory_locations,
            $collected_memories->compiler_arena_memory_locations,
        );

        $analyzed_regions = $region_analyzer->analyze(
            $collected_memories->memory_locations,
        );

        // Use corrected summary if region sums are available from the
        // backfilled locations. Compute region sums directly from the
        // location temp file since we don't have a SQL DB.
        $region_sums = $sink->computeRegionSumsAndOverhead()['sums'];
        $summary_base = $region_sums !== []
            ? $analyzed_regions->summary->correctedToArray($region_sums)
            : $analyzed_regions->summary->toArray();
        $summary = $this->buildSummary(
            $summary_base,
            $collected_memories,
            $this->rss_bytes,
            $target_php_settings,
        );

        unset($collected_memories, $analyzed_regions, $region_analyzer);

        $binary_output->finalizeStreaming($sink, $summary);
    }

    /**
     * Build the summary array shared by both PDO and rmem paths.
     *
     * @param array<string, mixed> $summary_base
     * @return array<int, array<string, mixed>>
     */
    private function buildSummary(
        array $summary_base,
        CollectedMemories $collected_memories,
        ?int $rss_bytes,
        TargetPhpSettings $target_php_settings,
    ): array {
        return [
            $summary_base
            + [
                'memory_get_usage' => $collected_memories->memory_get_usage_size,
                'memory_get_real_usage' => $collected_memories->memory_get_usage_real_size,
                'memory_get_peak_usage' => $collected_memories->memory_get_peak_usage,
                'memory_limit' => $collected_memories->memory_limit,
                'cached_chunks_size' => $collected_memories->cached_chunks_size,
                'chunks_count' => $collected_memories->chunks_count,
                'peak_chunks_count' => $collected_memories->peak_chunks_count,
                'cached_chunks_count' => $collected_memories->cached_chunks_count,
                'last_chunks_delete_boundary' => $collected_memories->last_chunks_delete_boundary,
                'last_chunks_delete_count' => $collected_memories->last_chunks_delete_count,
                'chunks_total_free_bytes' => $collected_memories->chunks_total_free_bytes,
                'chunks_mostly_empty_count' => $collected_memories->chunks_mostly_empty_count,
            ]
            + ($rss_bytes !== null ? ['rss' => $rss_bytes] : [])
            + [
                'heap_memory_analyzed_percentage' =>
                    (float)$summary_base['zend_mm_heap_usage']
                    /
                    (float)$collected_memories->memory_get_usage_size * 100.0
                ,
            ]
            + [
                'php_version' => $target_php_settings->php_version,
                'analyzer' => ReliProfiler::toolSignature(),
            ]
        ];
    }
}
