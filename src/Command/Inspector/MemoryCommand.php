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

namespace Reli\Command\Inspector;

use Reli\Inspector\Output\MemoryOutput\MemoryAnalysisResult;
use Reli\Inspector\Output\MemoryOutput\MemoryOutputFactory;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\CollectedMemories;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionsSummary;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Reli\ReliProfiler;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Reli\Lib\Defer\defer;

final class MemoryCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Full;
    }

    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private MemoryProfilerSettingsFromConsoleInput $memory_profiler_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private TargetProcessResolver $target_process_resolver,
        private PhpVersionDetector $php_version_detector,
        private MemoryLocationsCollector $memory_locations_collector,
        private ProcessStopper $process_stopper,
        private BinaryAnalysisCache $binary_analysis_cache,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory')
            ->setDescription('get memory usage from a target process')
        ;
        $this->memory_profiler_settings_from_console_input->setOptions($this);
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
        $this->addMemoryLimitOption();
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);
        if ($input->getOption('no-cache')) {
            $this->binary_analysis_cache->disable();
        }
        Log::info('start memory command');
        $memory_profiler_settings = $this->memory_profiler_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);

        $process_specifier = $this->target_process_resolver->resolve($target_process_settings);

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
        // Pass the pre-version-decision settings here on purpose:
        // findModuleRegistry's binding accepts 'auto', and Psalm's flow
        // analysis widens the narrowed `$target_php_settings_version_decided`
        // type whenever it's threaded through such a call, breaking
        // downstream narrowed-version expectations.
        $module_registry_address = $this->php_globals_finder->findModuleRegistry(
            $process_specifier,
            $target_php_settings
        );
        // For ZTS targets we also need TSRM's per-thread cache base
        // so the module-globals walker can resolve `ts_rsrc_id` slots
        // (e.g. phar's globals_id) into actual per-thread addresses.
        // NTS targets return null cheaply and the walker skips the
        // ZTS path.
        $tsrm_ls_cache_address = $this->php_globals_finder->findTsrmLsCache(
            $process_specifier,
            $target_php_settings_version_decided,
        );

        if ($memory_profiler_settings->stop_process) {
            $this->process_stopper->stop($process_specifier->pid);
            defer($scope_guard, fn () => $this->process_stopper->resume($process_specifier->pid));
        }

        $output_factory = new MemoryOutputFactory();

        if (MemoryOutputFactory::isRmemFormat($memory_profiler_settings)) {
            $output_path = $memory_profiler_settings->output_path
                ?? throw new \RuntimeException('--output is required when using rmem format');
            [$binary_output, $sink] = $output_factory->createBinaryStreamingSinkAtPath($output_path);
            $this->collectBinary(
                $process_specifier,
                $target_php_settings_version_decided,
                $eg_address,
                $cg_address,
                $bg_address,
                $memory_profiler_settings,
                $binary_output,
                $sink,
                $module_registry_address,
                $tsrm_ls_cache_address,
            );
            Log::info('end memory command');
            return 0;
        }

        // All non-rmem formats — DB (sqlite3 / mysql / postgresql) and
        // export (json / report / report-json) — collect into a temp
        // .rmem and then convert to the requested format. The DB path
        // pays a one-time wall-clock cost over direct-write but cuts
        // target pause time, since SQL INSERT and CREATE INDEX run
        // after the target is resumed.
        $tmp_base = tempnam(sys_get_temp_dir(), 'reli_mem_rmem_');
        if ($tmp_base === false) {
            throw new \RuntimeException('Failed to create temporary file for rmem intermediate');
        }
        $temp_rmem = $tmp_base . '.rmem';
        @unlink($tmp_base);
        try {
            [$binary_output, $sink] = $output_factory->createBinaryStreamingSinkAtPath($temp_rmem);
            $summary = $this->collectBinary(
                $process_specifier,
                $target_php_settings_version_decided,
                $eg_address,
                $cg_address,
                $bg_address,
                $memory_profiler_settings,
                $binary_output,
                $sink,
                $module_registry_address,
                $tsrm_ls_cache_address,
            );

            $result = new MemoryAnalysisResult(
                summary: $summary,
                context: null,
                pre_populated_rmem_path: $temp_rmem,
            );
            $memory_output = $output_factory->create($memory_profiler_settings);
            $memory_output->output($result);
        } finally {
            if (file_exists($temp_rmem)) {
                @unlink($temp_rmem);
            }
        }

        Log::info('end memory command');
        return 0;
    }

    /**
     * Drive the binary streaming sink to completion. Returns the summary
     * passed to finalizeStreaming.
     *
     * @param TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     * @return array<int, array<string, mixed>>
     */
    private function collectBinary(
        \Reli\Lib\Process\ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address,
        ?int $bg_address,
        \Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings $memory_profiler_settings,
        \Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput $binary_output,
        \Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink $sink,
        ?int $module_registry_address = null,
        ?int $tsrm_ls_cache_address = null,
    ): array {
        $collected_memories = $this->memory_locations_collector->collectAll(
            $process_specifier,
            $target_php_settings,
            $eg_address,
            $cg_address,
            $memory_profiler_settings->memory_exhaustion_error_details,
            $bg_address,
            $sink,
            module_registry_address: $module_registry_address,
            tsrm_ls_cache_address: $tsrm_ls_cache_address,
        );

        $region_result = $sink->computeRegionSumsAndOverhead();

        $summary = $this->buildSummary(
            $collected_memories,
            $region_result['sums'],
            $region_result['overhead'],
            $target_php_settings,
        );
        unset($collected_memories);

        $binary_output->finalizeStreaming($sink, $summary);
        return $summary;
    }

    /**
     * @param array<string, int> $region_sums
     * @return array<int, array<string, mixed>>
     */
    private function buildSummary(
        CollectedMemories $collected_memories,
        array $region_sums,
        int $allocation_overhead,
        TargetPhpSettings $target_php_settings,
    ): array {
        $heap_total = $collected_memories->chunk_memory_locations->getTotalSize()
            + $collected_memories->huge_memory_locations->getTotalSize();
        $chunk_total = $collected_memories->chunk_memory_locations->getTotalSize();
        $huge_total = $collected_memories->huge_memory_locations->getTotalSize();
        $vm_stack_total = $collected_memories->vm_stack_memory_locations->getTotalSize();
        $compiler_arena_total = $collected_memories->compiler_arena_memory_locations->getTotalSize();

        $chunk_usage = $region_sums['zend_mm_heap'] ?? 0;
        $huge_usage = $region_sums['zend_mm_huge'] ?? 0;

        $summary_base = [
            'zend_mm_heap_total' => $heap_total,
            'zend_mm_heap_usage' => $chunk_usage + $huge_usage
                + $vm_stack_total + $compiler_arena_total + $allocation_overhead,
            'zend_mm_chunk_total' => $chunk_total,
            'zend_mm_chunk_usage' => $chunk_usage
                + $vm_stack_total + $compiler_arena_total + $allocation_overhead,
            'zend_mm_huge_total' => $huge_total,
            'zend_mm_huge_usage' => $huge_usage,
            'vm_stack_total' => $vm_stack_total,
            'vm_stack_usage' => $region_sums['vm_stack'] ?? 0,
            'compiler_arena_total' => $compiler_arena_total,
            'compiler_arena_usage' => $region_sums['compiler_arena'] ?? 0,
            'possible_allocation_overhead_total' => $allocation_overhead,
            'possible_array_overhead_total' => 0,
        ];

        return [
            $summary_base
            + [
                'memory_get_usage' => $collected_memories->memory_get_usage_size,
                'memory_get_real_usage' => $collected_memories->memory_get_usage_real_size,
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
                    'bin_shape_counts'
                        => $collected_memories->bin_walk_result->toSummaryShapeCountsArray(),
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
