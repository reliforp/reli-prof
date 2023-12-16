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

use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\LocationTypeAnalyzer\LocationTypeAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ObjectClassAnalyzer\ObjectClassAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionAnalyzer;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Reli\ReliProfiler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('inspector:memory')
            ->setDescription('[experimental] get memory usage from an outer process')
        ;
        $this->memory_profiler_settings_from_console_input->setOptions($this);
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        Log::info('start memory command');
        $memory_profiler_settings = $this->memory_profiler_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);

        $process_specifier = $this->target_process_resolver->resolve($target_process_settings);

        $target_php_settings_version_decided = $this->php_version_detector->decidePhpVersion(
            $process_specifier,
            $target_php_settings
        );

        if ($memory_profiler_settings->stop_process) {
            $this->process_stopper->stop($process_specifier->pid);
            defer($scope_guard, fn () => $this->process_stopper->resume($process_specifier->pid));
        }

        $eg_address = $this->php_globals_finder->findExecutorGlobals($process_specifier, $target_php_settings);
        $cg_address = $this->php_globals_finder->findCompilerGlobals($process_specifier, $target_php_settings);

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
        $location_type_analyzer = new LocationTypeAnalyzer();
        $heap_location_type_summary = $location_type_analyzer->analyze(
            $analyzed_regions->regional_memory_locations->locations_in_zend_mm_heap,
        );

        $object_class_analyzer = new ObjectClassAnalyzer();
        $object_class_summary = $object_class_analyzer->analyze(
            $analyzed_regions->regional_memory_locations->locations_in_zend_mm_heap,
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
                    $analyzed_regions->summary->zend_mm_heap_usage
                    /
                    $collected_memories->memory_get_usage_size * 100
                ,
            ]
            + [
                'php_version' => $target_php_settings_version_decided->php_version,
                'analyzer' => ReliProfiler::toolSignature(),
            ]
        ];

        $context_analyzer = new ContextAnalyzer();
        $analyzed_context = $context_analyzer->analyze(
            $collected_memories->top_reference_context,
        );

        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($memory_profiler_settings->pretty_print) {
            $flags |= JSON_PRETTY_PRINT;
        }
        echo json_encode(
            [
                'summary' => $summary,
                "location_types_summary" => $heap_location_type_summary->per_type_usage,
                'class_objects_summary' => $object_class_summary->per_class_usage,
                'context' => $analyzed_context,
            ],
            $flags,
            2147483647
        );
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(json_last_error_msg());
        }

        Log::info('end memory command');
        return 0;
    }
}
