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
use Reli\Inspector\Settings\SidecarSettings\SidecarSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Sidecar\SidecarDumpHandler;
use Reli\Inspector\Sidecar\SidecarServer;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\Inspector\Watch\HeapStatsReader;
use Reli\Inspector\Watch\RssReader;
use Reli\Lib\Directory\AppDirectory;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SidecarCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Full;
    }

    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
        private MemoryDumper $memory_dumper,
        private CallTraceReader $call_trace_reader,
        private HeapStatsReader $heap_stats_reader,
        private RssReader $rss_reader,
        private ProcessStopper $process_stopper,
        private BinaryAnalysisCache $binary_analysis_cache,
        private SidecarSettingsFromConsoleInput $sidecar_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:sidecar')
            ->setDescription(
                'run a sidecar daemon that listens on a Unix domain socket'
                    . ' for memory dump requests from PHP processes'
            )
        ;
        $this->sidecar_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->addOption(
            'no-cache',
            null,
            InputOption::VALUE_NONE,
            'disable the binary analysis cache',
        );
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('no-cache')) {
            $this->binary_analysis_cache->disable();
        }

        $settings = $this->sidecar_settings_from_console_input->createSettings($input);

        if ($settings->memory_limit !== null) {
            ini_set('memory_limit', $settings->memory_limit);
        }

        AppDirectory::ensureDirectoryExists($settings->output_dir);
        AppDirectory::ensureDirectoryExists(dirname($settings->socket_path));

        $disk_tracker = new DiskUsageTracker(
            $settings->disk_usage_limit_bytes,
            $settings->output_dir,
        );

        $dump_handler = new SidecarDumpHandler(
            php_globals_finder: $this->php_globals_finder,
            php_version_detector: $this->php_version_detector,
            memory_dumper: $this->memory_dumper,
            call_trace_reader: $this->call_trace_reader,
            heap_stats_reader: $this->heap_stats_reader,
            rss_reader: $this->rss_reader,
            process_stopper: $this->process_stopper,
            disk_tracker: $disk_tracker,
            output_dir: $settings->output_dir,
            include_binary: $settings->include_binary,
            session_tags: $settings->tags,
        );

        $server = new SidecarServer($dump_handler);
        $server->run($settings->socket_path, $output);

        return 0;
    }
}
