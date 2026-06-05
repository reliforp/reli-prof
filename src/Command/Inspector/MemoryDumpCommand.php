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

use Reli\Inspector\MemoryDump\MemoryDumper;
use Reli\Inspector\Settings\MemoryDumpSettings\MemoryDumpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Reli\Lib\Defer\defer;

final class MemoryDumpCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Full;
    }

    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private MemoryDumpSettingsFromConsoleInput $memory_dump_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private TargetProcessResolver $target_process_resolver,
        private PhpVersionDetector $php_version_detector,
        private ProcessStopper $process_stopper,
        private BinaryAnalysisCache $binary_analysis_cache,
        private MemoryDumper $memory_dumper,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:dump')
            ->setDescription(
                'dump memory pool from a PHP process'
                    . ' for offline analysis'
            )
        ;
        $this->memory_dump_settings_from_console_input->setOptions($this);
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->addOption(
            'no-cache',
            null,
            InputOption::VALUE_NONE,
            'disable the binary analysis cache',
        );
        $this->addMemoryLimitOption();
    }

    #[\Override]
    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->applyMemoryLimit($input, $output);
        if ($input->getOption('no-cache')) {
            $this->binary_analysis_cache->disable();
        }
        Log::info('start memory:dump command');

        $dump_settings = $this->memory_dump_settings_from_console_input
            ->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input
            ->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input
            ->createSettings($input);

        $process_specifier = $this->target_process_resolver->resolve(
            $target_process_settings,
        );

        $target_php_settings_decided = $this->php_version_detector
            ->decidePhpVersion($process_specifier, $target_php_settings);

        $eg_address = $this->php_globals_finder->findExecutorGlobals(
            $process_specifier,
            $target_php_settings_decided,
        );
        $cg_address = $this->php_globals_finder->findCompilerGlobals(
            $process_specifier,
            $target_php_settings_decided,
        );
        // basic_globals is the engine's BG(...) struct. Persisting its
        // address (and the BG bytes via MemoryDumper's Phase 1
        // interval) lets offline analysis walk the shutdown-function
        // table; without it, objects pinned by
        // register_shutdown_function show up as unaccounted heap.
        // Resolution can legitimately fail (stripped binary,
        // FrankenPHP-style static-link host that preempts the symbol,
        // a cold ZTS worker with a zero tsrm_ls_cache). findBasicGlobals
        // catches every \Throwable from findGlobals and degrades to
        // null, and EmitModulesJob short-circuits on null — same
        // observable behaviour as the pre-v3 baseline.
        $bg_address = $this->php_globals_finder->findBasicGlobals(
            $process_specifier,
            $target_php_settings_decided,
        );
        // Persist module_registry + tsrm_ls_cache in the dump header
        // so offline `inspector:memory:analyze` runs hit the phar
        // walker without depending on a live process / binary view.
        $module_registry_address = $this->php_globals_finder->findModuleRegistry(
            $process_specifier,
            $target_php_settings,
        );
        $tsrm_ls_cache_address = $this->php_globals_finder->findTsrmLsCache(
            $process_specifier,
            $target_php_settings_decided,
        );

        // Resolve global interned-string pointer arrays for exclude-heap
        // mode. These are plain BSS symbols (not TSRM globals), so
        // findSymbol is used instead of findGlobals to avoid the TSRM
        // resolution path on ZTS builds.
        /** @var list<array{address: int, count: int}> $interned_string_arrays */
        $interned_string_arrays = [];
        if ($dump_settings->exclude_heap) {
            $sym_defs = [
                'zend_one_char_string' => 256,
                'zend_known_strings'   => -1,
                'zend_empty_string'    => 1,
            ];
            foreach ($sym_defs as $sym => $count) {
                try {
                    $interned_string_arrays[] = [
                        'address' => $this->php_globals_finder->findSymbol(
                            $process_specifier,
                            $target_php_settings_decided,
                            $sym,
                        ),
                        'count' => $count,
                    ];
                } catch (\Throwable) {
                }
            }
        }

        if ($dump_settings->stop_process) {
            $this->process_stopper->stop($process_specifier->pid);
            defer(
                $scope_guard,
                fn () => $this->process_stopper->resume(
                    $process_specifier->pid,
                ),
            );
        }

        $result = $this->memory_dumper->dump(
            $process_specifier,
            $target_php_settings_decided,
            $eg_address,
            $cg_address,
            $dump_settings->output_path,
            $dump_settings->include_binary,
            !$dump_settings->exclude_heap,
            $interned_string_arrays,
            bg_address: $bg_address,
            module_registry_address: $module_registry_address,
            tsrm_ls_cache_address: $tsrm_ls_cache_address,
        );

        $output->writeln(sprintf(
            'Memory dump written to %s (%d regions, %.1f MB)',
            $result->output_path,
            $result->region_count,
            (float)$result->total_bytes / 1024.0 / 1024.0,
        ));

        Log::info('end memory:dump command');
        return 0;
    }
}
