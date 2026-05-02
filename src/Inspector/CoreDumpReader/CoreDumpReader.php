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

namespace Reli\Inspector\CoreDumpReader;

use Reli\Inspector\Output\MemoryOutput\MemoryAnalysisResult;
use Reli\Inspector\Output\MemoryOutput\MemoryOutputFactory;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\CollectedMemories;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionsSummary;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\ReliProfiler;

final class CoreDumpReader
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
        private MemoryLocationsCollector $memory_locations_collector,
    ) {
    }

    public function read(
        int $pid,
        TargetPhpSettings $target_php_settings,
        MemoryProfilerSettings $memory_profiler_settings
    ): void {
        $process_specifier = new ProcessSpecifier($pid);

        $target_php_settings_version_decided = $this->php_version_detector->decidePhpVersion(
            $process_specifier,
            $target_php_settings
        );

        $eg_address = $this->php_globals_finder->findExecutorGlobals(
            $process_specifier,
            $target_php_settings_version_decided
        );
        $cg_address = $this->php_globals_finder->findCompilerGlobals(
            $process_specifier,
            $target_php_settings_version_decided
        );
        $bg_address = $this->php_globals_finder->findBasicGlobals(
            $process_specifier,
            $target_php_settings_version_decided
        );

        if (MemoryOutputFactory::isRmemFormat($memory_profiler_settings)) {
            $this->readBinary(
                $process_specifier,
                $target_php_settings_version_decided,
                $eg_address,
                $cg_address,
                $bg_address,
                $memory_profiler_settings,
            );
            return;
        }

        $this->readPdo(
            $process_specifier,
            $target_php_settings_version_decided,
            $eg_address,
            $cg_address,
            $bg_address,
            $memory_profiler_settings,
        );
    }

    /**
     * Original PDO-based streaming path (SQLite / MySQL / PostgreSQL / JSON / report).
     *
     * @param TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     */
    private function readPdo(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address,
        ?int $bg_address,
        MemoryProfilerSettings $memory_profiler_settings,
    ): void {
        $output_factory = new MemoryOutputFactory();
        [$pdo_output, $sink, $run_id, $db, $temp_path] = $output_factory->createStreamingSink(
            $memory_profiler_settings,
        );

        try {
            $collected_memories = $this->memory_locations_collector->collectAll(
                $process_specifier,
                $target_php_settings,
                $eg_address,
                $cg_address,
                $memory_profiler_settings->memory_exhaustion_error_details,
                $bg_address,
                $sink,
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
     * then assembles the final .rmem. No SQLite intermediate. Mirrors
     * the readBinary path in MemoryDumpReader so a coredump-driven
     * pipeline produces the exact same .rmem layout downstream tools
     * (`rmem:explore`, `rmem:query`, `rmem:serve`, `rmem:mcp`,
     * `inspector:memory:report`, `inspector:memory:compare`) consume.
     *
     * @param TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     */
    private function readBinary(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address,
        ?int $bg_address,
        MemoryProfilerSettings $memory_profiler_settings,
    ): void {
        $output_factory = new MemoryOutputFactory();
        [$binary_output, $sink] = $output_factory->createBinaryStreamingSink(
            $memory_profiler_settings,
        );

        $collected_memories = $this->memory_locations_collector->collectAll(
            $process_specifier,
            $target_php_settings,
            $eg_address,
            $cg_address,
            $memory_profiler_settings->memory_exhaustion_error_details,
            $bg_address,
            $sink,
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

        // Region sums come from the location temp file rather than a
        // SQL DB on this path; correctedToArray() applies them when
        // available, falling back to the uncorrected analyser summary.
        $region_sums = $sink->computeRegionSumsAndOverhead()['sums'];
        $summary_base = $region_sums !== []
            ? $analyzed_regions->summary->correctedToArray($region_sums)
            : $analyzed_regions->summary->toArray();

        $summary = $this->buildSummary(
            $summary_base,
            $collected_memories,
            $target_php_settings,
        );

        unset($collected_memories, $analyzed_regions, $region_analyzer);

        $binary_output->finalizeStreaming($sink, $summary);
    }

    /**
     * Build the summary array shared by both PDO and rmem paths.
     *
     * @param array<string, mixed> $summary_base
     * @param TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     * @return array<int, array<string, mixed>>
     */
    private function buildSummary(
        array $summary_base,
        CollectedMemories $collected_memories,
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
            + ($collected_memories->bin_walk_result !== null
                ? [
                    'bin_walk' => $collected_memories->bin_walk_result->toSummaryHistogramArray(),
                    'bin_walk_periodic_groups'
                        => $collected_memories->bin_walk_result->toSummaryPeriodicGroupsArray(),
                ]
                : []
            )
            + ['region_map' => $collected_memories->getRegionMap()->toSummaryArray()]
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
