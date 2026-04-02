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
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
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
            ->setDescription('[experimental] get memory usage from an outer process')
        ;
        $this->memory_profiler_settings_from_console_input->setOptions($this);
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
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

        // Set up streaming sink: context tree is emitted to a temp SQLite
        // during collection, releasing each branch immediately after emission.
        $output_factory = new MemoryOutputFactory();
        $region_boundaries = null;

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
                $target_php_settings_version_decided,
                $eg_address,
                $cg_address,
                $memory_profiler_settings->memory_exhaustion_error_details,
                $bg_address,
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
                    'php_version' => $target_php_settings_version_decided->php_version,
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

            // Finalize the streaming DB (summary, indexes, views)
            $pdo_output->finalizeStreaming($db, $run_id, $sink, $summary);

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
        } finally {
            if (file_exists($tmp_path)) {
                @unlink($tmp_path);
            }
        }

        Log::info('end memory command');
        return 0;
    }
}
