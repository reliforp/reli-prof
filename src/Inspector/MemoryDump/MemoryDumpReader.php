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
        private bool $disable_bin_walk = false,
        private ?int $module_registry_address = null,
        private ?int $tsrm_ls_cache_address = null,
    ) {
    }

    public function read(
        MemoryProfilerSettings $memory_profiler_settings
    ): void {
        if (MemoryOutputFactory::isRmemFormat($memory_profiler_settings)) {
            $this->readBinary($memory_profiler_settings);
        } else {
            // All non-rmem formats — DB (sqlite3 / mysql / postgresql) and
            // export (json / report / report-json) — collect into a temp
            // .rmem and then convert to the requested format. The DB path
            // pays a one-time wall-clock cost over direct-write but cuts
            // target pause time, since SQL INSERT and CREATE INDEX run
            // after the target is resumed.
            $this->readBinaryThenConvert($memory_profiler_settings);
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
        $output_path = $memory_profiler_settings->output_path
            ?? throw new \RuntimeException('--output is required when using rmem format');

        $output_factory = new MemoryOutputFactory();
        [$binary_output, $sink] = $output_factory->createBinaryStreamingSinkAtPath($output_path);
        $this->collectBinary($memory_profiler_settings, $binary_output, $sink);
    }

    /**
     * Non-DB, non-rmem path (json / report / report-json).
     *
     * Streams the context tree into a temp .rmem file, then hands the
     * path to the output backend, which converts it to the final
     * format. Replaces the previous "temp SQLite intermediate"
     * behaviour; the rmem intermediate is faster for JSON output
     * (no N+1 SQL queries) and avoids the CREATE INDEX cost the SQLite
     * intermediate paid only to throw away.
     */
    private function readBinaryThenConvert(MemoryProfilerSettings $memory_profiler_settings): void
    {
        $tmp_base = tempnam(sys_get_temp_dir(), 'reli_dump_rmem_');
        if ($tmp_base === false) {
            throw new \RuntimeException('Failed to create temporary file for rmem intermediate');
        }
        $temp_path = $tmp_base . '.rmem';
        @unlink($tmp_base);

        try {
            $output_factory = new MemoryOutputFactory();
            [$binary_output, $sink] = $output_factory->createBinaryStreamingSinkAtPath($temp_path);
            $summary = $this->collectBinary($memory_profiler_settings, $binary_output, $sink);

            $result = new MemoryAnalysisResult(
                summary: $summary,
                context: null,
                pre_populated_rmem_path: $temp_path,
            );

            $memory_output = $output_factory->create($memory_profiler_settings);
            $memory_output->output($result);
        } finally {
            if (file_exists($temp_path)) {
                @unlink($temp_path);
            }
        }
    }

    /**
     * Drive the binary streaming sink to completion. Returns the summary
     * passed to finalizeStreaming so callers can re-use it (e.g. when
     * constructing a MemoryAnalysisResult for a follow-up converter).
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectBinary(
        MemoryProfilerSettings $memory_profiler_settings,
        \Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput $binary_output,
        \Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink $sink,
    ): array {
        $process_specifier = new ProcessSpecifier($this->pid);

        /** @var TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
        $target_php_settings = new TargetPhpSettings(php_version: $this->php_version);

        $collected_memories = $this->memory_locations_collector->collectAll(
            $process_specifier,
            $target_php_settings,
            $this->eg_address,
            $this->cg_address,
            $memory_profiler_settings->memory_exhaustion_error_details,
            $this->bg_address,
            $sink,
            $this->fast_path,
            $this->disable_bin_walk,
            module_registry_address: $this->module_registry_address,
            tsrm_ls_cache_address: $this->tsrm_ls_cache_address,
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

        $analyzed_regions = $region_analyzer->analyze();

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
        return $summary;
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
                'memory_get_peak_usage_real' => $collected_memories->memory_get_peak_usage_real,
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
            + ($collected_memories->bin_walk_result !== null
                ? [
                    // Non-scalar values are JSON-encoded by the summary
                    // writer (PDO + binary paths both call json_encode).
                    'bin_walk' => $collected_memories->bin_walk_result->toSummaryHistogramArray(),
                    'bin_walk_periodic_groups'
                        => $collected_memories->bin_walk_result->toSummaryPeriodicGroupsArray(),
                    'bin_shape_counts'
                        => $collected_memories->bin_walk_result->toSummaryShapeCountsArray(),
                ]
                : []
            )
            + ['region_map' => $collected_memories->getRegionMap()->toSummaryArray()]
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
