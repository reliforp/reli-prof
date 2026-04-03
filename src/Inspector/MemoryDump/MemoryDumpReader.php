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
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Inspector\Watch\RssReader;
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
    ) {
    }

    public function read(
        MemoryProfilerSettings $memory_profiler_settings
    ): void {
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

            $rss_reader = new RssReader();
            $rss_bytes = $rss_reader->read($this->pid);

            $sink->flush();
            $region_sums = RegionsSummary::queryRegionSums($db, $run_id);
            $summary_base = $region_sums !== []
                ? $analyzed_regions->summary->correctedToArray($region_sums)
                : $analyzed_regions->summary->toArray();

            $summary = [
                $summary_base
                + [
                    'memory_get_usage' => $collected_memories->memory_get_usage_size,
                    'memory_get_real_usage' => $collected_memories->memory_get_usage_real_size,
                    'memory_get_peak_usage' => $collected_memories->memory_get_peak_usage,
                    'memory_limit' => $collected_memories->memory_limit,
                    'cached_chunks_size' => $collected_memories->cached_chunks_size,
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

            $region_boundaries = new RegionBoundaries(
                $collected_memories->chunk_memory_locations,
                $collected_memories->huge_memory_locations,
                $collected_memories->vm_stack_memory_locations,
                $collected_memories->compiler_arena_memory_locations,
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
}
