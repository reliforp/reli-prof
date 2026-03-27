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

use Reli\Inspector\MemoryDump\MemoryDumpWriter;
use Reli\Inspector\Settings\MemoryDumpSettings\MemoryDumpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\MemoryReader\RecordingMemoryReader;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Reli\Lib\Defer\defer;

final class MemoryDumpCommand extends Command
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private MemoryDumpSettingsFromConsoleInput $memory_dump_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private TargetProcessResolver $target_process_resolver,
        private PhpVersionDetector $php_version_detector,
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private PhpZendMemoryManagerChunkFinder $chunk_finder,
        private ProcessStopper $process_stopper,
        private BinaryAnalysisCache $binary_analysis_cache,
        private ProcessMemoryMapCreatorInterface $process_memory_map_creator,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:dump')
            ->setDescription('[experimental] dump memory pool from a PHP process for offline analysis')
        ;
        $this->memory_dump_settings_from_console_input->setOptions($this);
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
        Log::info('start memory:dump command');

        $dump_settings = $this->memory_dump_settings_from_console_input->createSettings($input);
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

        if ($dump_settings->stop_process) {
            $this->process_stopper->stop($process_specifier->pid);
            defer($scope_guard, fn () => $this->process_stopper->resume($process_specifier->pid));
        }

        // Run the analysis pipeline with a recording memory reader.
        // This captures exactly the memory regions the analysis actually reads,
        // without needing to predict which addresses are needed.
        $recording_reader = new RecordingMemoryReader($this->memory_reader);
        $collector = new MemoryLocationsCollector(
            $recording_reader,
            $this->zend_type_reader_creator,
            $this->chunk_finder,
        );

        $collector->collectAll(
            $process_specifier,
            $target_php_settings_version_decided,
            $eg_address,
            $cg_address,
        );

        $regions = $recording_reader->getRecordedRegions();

        // Optionally include read-only binary segments
        if ($dump_settings->include_binary) {
            $php_regex = $target_php_settings_version_decided->php_regex;
            $memory_map = $this->process_memory_map_creator->getProcessMemoryMap(
                $process_specifier->pid,
            );
            $php_ro_areas = $memory_map->findByNameRegex($php_regex);
            foreach ($php_ro_areas as $area) {
                if (!$area->attribute->write && $area->attribute->read && $area->name !== '') {
                    $addr = (int)hexdec($area->begin);
                    $size = (int)hexdec($area->end) - $addr;
                    if ($size > 0) {
                        try {
                            $data = $this->memory_reader->read(
                                $process_specifier->pid,
                                $addr,
                                $size,
                            );
                            $regions[] = [
                                'address' => $addr,
                                'size' => $size,
                                'data' => \FFI::string($data, $size),
                            ];
                        } catch (\Throwable $e) {
                            Log::info(
                                "skipping binary region at 0x" . dechex($addr)
                                . ": " . $e->getMessage()
                            );
                        }
                    }
                }
            }
        }

        $memory_map = $this->process_memory_map_creator->getProcessMemoryMap(
            $process_specifier->pid,
        );
        $all_areas = $memory_map->findByNameRegex('.*');

        $writer = new MemoryDumpWriter();
        $writer->write(
            $dump_settings->output_path,
            $process_specifier->pid,
            $target_php_settings_version_decided->php_version,
            $eg_address,
            $cg_address,
            $all_areas,
            $regions,
        );

        $total_size = 0;
        foreach ($regions as $region) {
            $total_size += $region['size'];
        }

        $output->writeln(sprintf(
            'Memory dump written to %s (%d regions, %.1f MB)',
            $dump_settings->output_path,
            count($regions),
            (float)$total_size / 1024.0 / 1024.0,
        ));

        Log::info('end memory:dump command');
        return 0;
    }
}
