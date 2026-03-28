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
use Reli\Inspector\Output\MemoryOutput\PdoDriver\PdoDriverInterface;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\CollectedMemories;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\LocationTypeAnalyzer\LocationTypeAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ObjectClassAnalyzer\ObjectClassAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ParallelCollectAnalyzer;
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
    ) {
    }

    public function read(
        MemoryProfilerSettings $memory_profiler_settings
    ): void {
        $process_specifier = new ProcessSpecifier($this->pid);

        /** @var TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
        $target_php_settings = new TargetPhpSettings(php_version: $this->php_version);

        $is_db = in_array($memory_profiler_settings->output_format, ['sqlite3', 'mysql', 'postgresql'], true);
        $use_parallel = $is_db && ParallelCollectAnalyzer::isAvailable();

        if ($use_parallel) {
            $this->readParallel($process_specifier, $target_php_settings, $memory_profiler_settings);
        } else {
            $this->readSequential($process_specifier, $target_php_settings, $memory_profiler_settings);
        }
    }

    /**
     * @param TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     */
    private function readSequential(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        MemoryProfilerSettings $memory_profiler_settings,
    ): void {
        $collected_memories = $this->memory_locations_collector->collectAll(
            $process_specifier,
            $target_php_settings,
            $this->eg_address,
            $this->cg_address,
            $memory_profiler_settings->memory_exhaustion_error_details,
        );

        $this->outputCollectedMemories($collected_memories, $target_php_settings, $memory_profiler_settings);
    }

    /**
     * Parallel path: prepare → fork workers (collect + analyze) → main reads pipes → DB.
     *
     * @param TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     */
    private function readParallel(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        MemoryProfilerSettings $memory_profiler_settings,
    ): void {
        // Phase 1: sequential pre-processing (small heap).
        $prep = $this->memory_locations_collector->prepare(
            $process_specifier,
            $target_php_settings,
            $this->eg_address,
            $this->cg_address,
        );

        $region_boundaries = new RegionBoundaries(
            $prep->chunk_memory_locations,
            $prep->huge_memory_locations,
            $prep->vm_stack_memory_locations,
            $prep->compiler_arena_memory_locations,
        );

        // Build summary from prep metadata (region analysis is skipped in
        // parallel mode — the DB computes summaries via GROUP BY).
        $summary = [
            [
                'memory_get_usage' => $prep->memory_get_usage_size,
                'memory_get_real_usage' => $prep->memory_get_usage_real_size,
                'cached_chunks_size' => $prep->cached_chunks_size,
            ]
            + [
                'php_version' => $target_php_settings->php_version,
                'analyzer' => ReliProfiler::toolSignature(),
            ]
        ];

        // Phase 2: set up DB and fork workers.
        $output_factory = new MemoryOutputFactory();
        /** @var PdoMemoryOutput $memory_output */
        $memory_output = $output_factory->create(
            $memory_profiler_settings,
            $region_boundaries,
        );

        $memory_output->outputParallelCollect(
            $summary,
            $this->memory_locations_collector,
            $prep,
            $region_boundaries,
            $memory_profiler_settings->memory_exhaustion_error_details,
        );
    }

    /**
     * @param TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     */
    private function outputCollectedMemories(
        CollectedMemories $collected_memories,
        TargetPhpSettings $target_php_settings,
        MemoryProfilerSettings $memory_profiler_settings,
    ): void {
        $region_analyzer = new RegionAnalyzer(
            $collected_memories->chunk_memory_locations,
            $collected_memories->huge_memory_locations,
            $collected_memories->vm_stack_memory_locations,
            $collected_memories->compiler_arena_memory_locations,
        );

        $analyzed_regions = $region_analyzer->analyze(
            $collected_memories->memory_locations,
        );

        $summary = [
            $analyzed_regions->summary->toArray()
            + [
                'memory_get_usage' => $collected_memories->memory_get_usage_size,
                'memory_get_real_usage' => $collected_memories->memory_get_usage_real_size,
                'cached_chunks_size' => $collected_memories->cached_chunks_size,
            ]
            + [
                'heap_memory_analyzed_percentage' =>
                    (float)$analyzed_regions->summary->zend_mm_heap_usage
                    /
                    (float)$collected_memories->memory_get_usage_size * 100.0
                ,
            ]
            + [
                'php_version' => $target_php_settings->php_version,
                'analyzer' => ReliProfiler::toolSignature(),
            ]
        ];

        $is_db = in_array($memory_profiler_settings->output_format, ['sqlite3', 'mysql', 'postgresql'], true);

        $location_types_summary = null;
        $class_objects_summary = null;
        if (!$is_db) {
            $location_type_analyzer = new LocationTypeAnalyzer();
            $location_types_summary = $location_type_analyzer->analyze(
                $analyzed_regions->regional_memory_locations->locations_in_zend_mm_heap,
            )->per_type_usage;

            $object_class_analyzer = new ObjectClassAnalyzer();
            $class_objects_summary = $object_class_analyzer->analyze(
                $analyzed_regions->regional_memory_locations->locations_in_zend_mm_heap,
            )->per_class_usage;
        }

        $region_boundaries = new RegionBoundaries(
            $collected_memories->chunk_memory_locations,
            $collected_memories->huge_memory_locations,
            $collected_memories->vm_stack_memory_locations,
            $collected_memories->compiler_arena_memory_locations,
        );
        $top_reference_context = $collected_memories->top_reference_context;

        unset($collected_memories, $analyzed_regions, $region_analyzer);

        $result = new MemoryAnalysisResult(
            $summary,
            $top_reference_context,
            $location_types_summary,
            $class_objects_summary,
        );

        $output_factory = new MemoryOutputFactory();
        $memory_output = $output_factory->create(
            $memory_profiler_settings,
            $region_boundaries,
        );
        $memory_output->output($result);
    }
}
