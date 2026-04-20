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
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionsSummary;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Reli\ReliProfiler;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Reli\Lib\Defer\defer;

final class MemoryCommand extends Command
{
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
            ->setDescription('get memory usage from an outer process')
        ;
        $this->memory_profiler_settings_from_console_input->setOptions($this);
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
        $this->addOption(
            'memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'set PHP memory_limit for analysis (e.g. 2G, 512M)',
        );
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $memory_limit */
        $memory_limit = $input->getOption('memory-limit');
        if (is_string($memory_limit) && $memory_limit !== '') {
            ini_set('memory_limit', $memory_limit);
        }
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

        if ($memory_profiler_settings->stop_process) {
            $this->process_stopper->stop($process_specifier->pid);
            defer($scope_guard, fn () => $this->process_stopper->resume($process_specifier->pid));
        }

        // Set up streaming sink: for DB formats (sqlite3, mysql, postgresql)
        // write directly to the output DB; for others use a temp SQLite.
        $output_factory = new MemoryOutputFactory();

        [$pdo_output, $sink, $run_id, $db, $temp_path] = $output_factory->createStreamingSink(
            $memory_profiler_settings,
        );

        try {
            $collected_memories = $this->memory_locations_collector->collectAll(
                $process_specifier,
                $target_php_settings_version_decided,
                $eg_address,
                $cg_address,
                $memory_profiler_settings->memory_exhaustion_error_details,
                $bg_address,
                $sink,
            );

            // Region classification happens inline at emit time (PdoContextTreeSink
            // has RegionBoundaries set by the collector before the job loop).
            // Query the DB for region sums — no backfill needed.
            $sink->flush();
            $region_result = RegionsSummary::queryRegionSums($db, $run_id);
            $region_sums = $region_result['sums'];
            $allocation_overhead = $region_result['overhead'];

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

            $summary = [
                $summary_base
                + [
                    'memory_get_usage' => $collected_memories->memory_get_usage_size,
                    'memory_get_real_usage' => $collected_memories->memory_get_usage_real_size,
                    'cached_chunks_size' => $collected_memories->cached_chunks_size,
                ]
                + [
                    'heap_memory_analyzed_percentage' =>
                        (float)$summary_base['zend_mm_heap_usage']
                        /
                        (float)$collected_memories->memory_get_usage_size * 100.0
                    ,
                ]
                + [
                    'php_version' => $target_php_settings_version_decided->php_version,
                    'analyzer' => ReliProfiler::toolSignature(),
                ]
            ];

            unset($collected_memories);

            // Finalize the streaming DB (summary, indexes, views)
            $pdo_output->finalizeStreaming($db, $run_id, $sink, $summary);

            // For DB formats the data is already in the output DB; done.
            // For JSON/report, stream from the temp SQLite DB.
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
                );
                $memory_output->output($result);
            }
        } finally {
            if ($temp_path !== null && file_exists($temp_path)) {
                @unlink($temp_path);
            }
        }

        Log::info('end memory command');
        return 0;
    }
}
