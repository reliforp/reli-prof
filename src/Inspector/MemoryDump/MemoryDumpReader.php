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
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;
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
    ) {
    }

    public function read(
        MemoryProfilerSettings $memory_profiler_settings
    ): void {
        $process_specifier = new ProcessSpecifier($this->pid);

        /** @var TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
        $target_php_settings = new TargetPhpSettings(php_version: $this->php_version);

        $tmp_base = tempnam(sys_get_temp_dir(), 'reli_stream_');
        if ($tmp_base === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }
        $tmp_path = $tmp_base . '.sqlite3';
        @unlink($tmp_base);

        try {
            $sqlite_driver = new SqliteDriver($tmp_path);
            $pdo_output = new PdoMemoryOutput($sqlite_driver, null);
            [$sink, $run_id, $db] = $pdo_output->createStreamingSink();

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

            $region_boundaries = new RegionBoundaries(
                $collected_memories->chunk_memory_locations,
                $collected_memories->huge_memory_locations,
                $collected_memories->vm_stack_memory_locations,
                $collected_memories->compiler_arena_memory_locations,
            );

            unset($collected_memories, $analyzed_regions, $region_analyzer);

            $pdo_output->finalizeStreaming($db, $run_id, $sink, $summary);

            $result = new MemoryAnalysisResult(
                $summary,
                null,
                null,
                null,
                $db,
                $run_id,
            );

            $output_factory = new MemoryOutputFactory();
            $memory_output = $output_factory->create(
                $memory_profiler_settings,
                $region_boundaries,
            );
            $memory_output->output($result);
        } finally {
            if (file_exists($tmp_path)) {
                @unlink($tmp_path);
            }
        }
    }
}
