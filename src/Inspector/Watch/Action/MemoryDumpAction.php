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

namespace Reli\Inspector\Watch\Action;

use Reli\Inspector\Output\MemoryOutput\MemoryAnalysisResult;
use Reli\Inspector\Output\MemoryOutput\MemoryOutputFactory;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\LocationTypeAnalyzer\LocationTypeAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ObjectClassAnalyzer\ObjectClassAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\ReliProfiler;

final class MemoryDumpAction implements ActionInterface
{
    public function __construct(
        private MemoryLocationsCollector $memory_locations_collector,
        /** @var TargetPhpSettings<'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'|'v82'|'v83'|'v84'|'v85'> */
        private TargetPhpSettings $target_php_settings,
        private int $eg_address,
        private int $cg_address,
        private string $output_dir,
        private string $output_format,
        private DiskUsageTracker $disk_tracker,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'memory-dump';
    }

    #[\Override]
    public function execute(
        TriggerEvent $event,
        ProcessSpecifier $process,
        WatchContext $context,
    ): void {
        if (!$this->disk_tracker->canWrite()) {
            Log::debug('memory-dump skipped: disk limit reached');
            return;
        }

        $output_path = sprintf(
            '%s/watch-%d-%s.%s',
            rtrim($this->output_dir, '/'),
            $process->pid,
            date('Ymd-His', (int)$event->timestamp),
            $this->output_format === 'sqlite3' ? 'sqlite3' : 'json',
        );

        try {
            $collected = $this->memory_locations_collector->collectAll(
                $process,
                $this->target_php_settings,
                $this->eg_address,
                $this->cg_address,
            );

            $region_analyzer = new RegionAnalyzer(
                $collected->chunk_memory_locations,
                $collected->huge_memory_locations,
                $collected->vm_stack_memory_locations,
                $collected->compiler_arena_memory_locations,
            );

            $analyzed = $region_analyzer->analyze(
                $collected->memory_locations,
            );

            $summary = [
                $analyzed->summary->toArray()
                + [
                    'memory_get_usage'
                        => $collected->memory_get_usage_size,
                    'memory_get_real_usage'
                        => $collected->memory_get_usage_real_size,
                    'cached_chunks_size'
                        => $collected->cached_chunks_size,
                ]
                + [
                    'heap_memory_analyzed_percentage' =>
                        (float)$analyzed->summary->zend_mm_heap_usage
                        / (float)$collected->memory_get_usage_size
                        * 100.0,
                ]
                + [
                    'php_version'
                        => $this->target_php_settings->php_version,
                    'analyzer' => ReliProfiler::toolSignature(),
                    'trigger' => $event->trigger_name,
                ]
            ];

            $is_db = in_array(
                $this->output_format,
                ['sqlite3', 'mysql', 'postgresql'],
                true,
            );

            $location_types_summary = null;
            $class_objects_summary = null;
            if (!$is_db) {
                $location_type_analyzer = new LocationTypeAnalyzer();
                $location_types_summary = $location_type_analyzer
                    ->analyze(
                        $analyzed
                            ->regional_memory_locations
                            ->locations_in_zend_mm_heap,
                    )
                    ->per_type_usage;

                $object_class_analyzer = new ObjectClassAnalyzer();
                $class_objects_summary = $object_class_analyzer
                    ->analyze(
                        $analyzed
                            ->regional_memory_locations
                            ->locations_in_zend_mm_heap,
                    )
                    ->per_class_usage;
            }

            $region_boundaries = new RegionBoundaries(
                $collected->chunk_memory_locations,
                $collected->huge_memory_locations,
                $collected->vm_stack_memory_locations,
                $collected->compiler_arena_memory_locations,
            );
            $top_reference_context = $collected->top_reference_context;
            unset($collected, $analyzed, $region_analyzer);

            $result = new MemoryAnalysisResult(
                $summary,
                $top_reference_context,
                $location_types_summary,
                $class_objects_summary,
            );

            $profiler_settings = new MemoryProfilerSettings(
                stop_process: false,
                pretty_print: true,
                output_format: $this->output_format,
                output_path: $output_path,
            );

            $output_factory = new MemoryOutputFactory();
            $memory_output = $output_factory->create(
                $profiler_settings,
                $region_boundaries,
            );
            $memory_output->output($result);

            $this->disk_tracker->recordFile($output_path);
            Log::info('memory-dump saved', ['path' => $output_path]);
        } catch (\Throwable $e) {
            Log::debug('memory-dump failed', [
                'pid' => $process->pid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
