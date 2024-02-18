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

use PhpCast\NullableCast;
use Reli\Inspector\CoreDumpReader\CoreDumpReaderFactory;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\LocationTypeAnalyzer\LocationTypeAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ObjectClassAnalyzer\ObjectClassAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionAnalyzer;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\ReliProfiler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CoreDumpCommand extends Command
{
    public function __construct(
        private MemoryProfilerSettingsFromConsoleInput $memory_profiler_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
        private MemoryLocationsCollector $memory_locations_collector,
        private CoreDumpReaderFactory $core_dump_reader_factory,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('inspector:coredump')
            ->setDescription('[experimental] get memory usage from an outer process')
        ;
        $this->memory_profiler_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->addOption(
            'pid',
            'p',
            InputOption::VALUE_REQUIRED,
            'process id'
        );
        $this->addArgument(
            'core-file',
            InputArgument::REQUIRED,
            'path to the core file'
        );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        Log::info('start core-dump command');
        $memory_profiler_settings = $this->memory_profiler_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $pid = NullableCast::toInt($input->getOption('pid'));
        if (is_null($pid)) {
            throw new \RuntimeException('pid is not specified');
        }
        $core_file = NullableCast::toString($input->getArgument('core-file'));
        if (is_null($core_file)) {
            throw new \RuntimeException('core-file is not specified');
        }
        $process_specifier = new ProcessSpecifier($pid);

        $core_dump_reader = $this->core_dump_reader_factory->createFromPath($core_file);
        $core_dump_reader->read(
            $pid,
            $target_php_settings,
            $memory_profiler_settings
        );

        return 0;
    }
}
