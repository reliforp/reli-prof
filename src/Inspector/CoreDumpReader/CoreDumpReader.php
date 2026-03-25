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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\LocationTypeAnalyzer\LocationTypeAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ObjectClassAnalyzer\ObjectClassAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
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

        $collected_memories = $this->memory_locations_collector->collectAll(
            $process_specifier,
            $target_php_settings_version_decided,
            $eg_address,
            $cg_address,
            $memory_profiler_settings->memory_exhaustion_error_details,
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
                'php_version' => $target_php_settings_version_decided->php_version,
                'analyzer' => ReliProfiler::toolSignature(),
            ]
        ];

        $is_sqlite = $memory_profiler_settings->output_format === 'sqlite3';

        $location_types_summary = null;
        $class_objects_summary = null;
        if (!$is_sqlite) {
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
