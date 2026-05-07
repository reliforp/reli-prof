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

namespace Reli\Command\PhpSpy;

use Reli\Converter\ParsedCallTrace;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\PhpSpy\PhpSpyProcessPool;
use Reli\Inspector\Daemon\Searcher\Context\PhpSearcherContextCreator;
use Reli\Inspector\Daemon\Searcher\Controller\PhpSearcherControllerInterface;
use Reli\Inspector\Settings\DaemonSettings\DaemonSettings;
use Reli\Inspector\Settings\DaemonSettings\DaemonSettingsFromConsoleInput;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use Reli\Inspector\Settings\OutputSettings\OutputSettings;
use Reli\Inspector\Settings\OutputSettings\TraceOutputPathResolver;
use Reli\Inspector\Settings\PhpSpySettings\PhpSpyOutputSettingsFromConsoleInput;
use Reli\Inspector\Settings\PhpSpySettings\PhpSpySettings;
use Reli\Inspector\Settings\PhpSpySettings\PhpSpySettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Lib\PhpSpy\PhpSpyFinder;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PhpSpyDaemonCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Full;
    }

    public function __construct(
        private PhpSearcherContextCreator $php_searcher_context_creator,
        private PhpSpyFinder $phpspy_finder,
        private DaemonSettingsFromConsoleInput $daemon_settings_from_console_input,
        private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private PhpSpySettingsFromConsoleInput $phpspy_settings_from_console_input,
        private PhpSpyOutputSettingsFromConsoleInput $output_settings_from_console_input,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('phpspy:daemon')
            ->setDescription(
                'concurrently get call traces from multiple processes using phpspy as the tracer backend'
                . ' (reli resolves EG addresses for ZTS support, then delegates tracing to phpspy)'
            )
        ;
        $this->daemon_settings_from_console_input->setOptions($this);
        $this->get_trace_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->phpspy_settings_from_console_input->setOptions($this);
        $this->output_settings_from_console_input->setOptions($this);
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
        $this->addMemoryLimitOption();
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);
        $no_cache = (bool)$input->getOption('no-cache');
        $daemon_settings = $this->daemon_settings_from_console_input->createSettings($input);
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $phpspy_settings = $this->phpspy_settings_from_console_input->createSettings($input);
        $output_settings = $this->output_settings_from_console_input->createSettings($input);

        $allowed_formats = ['phpspy', 'rbt', 'rbt-bundled'];
        if (!in_array($output_settings->output_format, $allowed_formats, true)) {
            throw new \InvalidArgumentException(
                "unknown --output-format '{$output_settings->output_format}':"
                . " expected 'phpspy', 'rbt' or 'rbt-bundled'"
            );
        }
        if (
            $output_settings->output_format === 'phpspy'
            && ($output_settings->rbt_compress || $output_settings->hasRbtTimestamps())
        ) {
            throw new \InvalidArgumentException(
                '--rbt-compress / --rbt-timestamps require --output-format=rbt or rbt-bundled'
            );
        }

        // Verify phpspy is available before starting
        $phpspy_path = $this->phpspy_finder->find($phpspy_settings->phpspy_path);
        $output->writeln('<info>using phpspy: ' . $phpspy_path . '</info>');

        return match ($output_settings->output_format) {
            'rbt' => $this->runRbtPerWorker(
                $output,
                $no_cache,
                $daemon_settings,
                $get_trace_settings,
                $target_php_settings,
                $phpspy_settings,
                $output_settings,
            ),
            'rbt-bundled' => $this->runRbtBundled(
                $output,
                $no_cache,
                $daemon_settings,
                $get_trace_settings,
                $target_php_settings,
                $phpspy_settings,
                $output_settings,
            ),
            default => $this->runPassthrough(
                $output,
                $no_cache,
                $daemon_settings,
                $get_trace_settings,
                $target_php_settings,
                $phpspy_settings,
                $output_settings->output_path,
            ),
        };
    }

    private function runPassthrough(
        OutputInterface $output,
        bool $no_cache,
        DaemonSettings $daemon_settings,
        GetTraceSettings $get_trace_settings,
        TargetPhpSettings $target_php_settings,
        PhpSpySettings $phpspy_settings,
        ?string $output_path,
    ): int {
        $output_stream = \STDOUT;
        if (is_string($output_path)) {
            $stream = fopen($output_path, 'w');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open output file: ' . $output_path);
            }
            $output_stream = $stream;
        }

        $process_pool = new PhpSpyProcessPool($this->phpspy_finder);
        $interrupted = $this->installSignalHandlers();
        $this->startSearcher($daemon_settings, $target_php_settings, $no_cache);

        $output->writeln('<info>searching for target processes...</info>');

        while (!$interrupted->value) {
            pcntl_signal_dispatch();
            $this->reconcileTargets($output, $process_pool, $get_trace_settings, $phpspy_settings);
            $process_pool->passthroughAll($output_stream);
            usleep(1000);
        }

        $output->writeln('<info>stopping all phpspy processes...</info>');
        $process_pool->stopAll();

        if ($output_stream !== \STDOUT) {
            fclose($output_stream);
        }

        return 0;
    }

    private function runRbtBundled(
        OutputInterface $output,
        bool $no_cache,
        DaemonSettings $daemon_settings,
        GetTraceSettings $get_trace_settings,
        TargetPhpSettings $target_php_settings,
        PhpSpySettings $phpspy_settings,
        OutputSettings $output_settings,
    ): int {
        $sink = new PhpSpyRbtSink(
            $output_settings->output_path,
            $output_settings->rbt_compress,
            PhpSpyRbtSink::derivePeriodUs($phpspy_settings),
            $output_settings->hasRbtTimestamps(),
        );
        $writer = $sink->getWriter();
        $delta_clock = $output_settings->hasRbtTimestamps() ? new HrtimeDeltaClock() : null;
        $write_sample = static function (int $pid, ParsedCallTrace $trace) use ($writer, $delta_clock): void {
            $writer->writePidTrace($trace, $pid, $delta_clock?->advance() ?? 0);
        };

        $process_pool = new PhpSpyProcessPool($this->phpspy_finder);
        $interrupted = $this->installSignalHandlers();
        $this->startSearcher($daemon_settings, $target_php_settings, $no_cache);

        $output->writeln('<info>searching for target processes...</info>');

        try {
            while (!$interrupted->value) {
                pcntl_signal_dispatch();
                $this->reconcileTargets($output, $process_pool, $get_trace_settings, $phpspy_settings);
                $process_pool->consumeAll($write_sample);
                usleep(1000);
            }

            $output->writeln('<info>stopping all phpspy processes...</info>');
            $process_pool->consumeAll($write_sample);
            $process_pool->flushParsers($write_sample);
        } finally {
            $process_pool->stopAll();
            $sink->close();
        }

        return 0;
    }

    private function runRbtPerWorker(
        OutputInterface $output,
        bool $no_cache,
        DaemonSettings $daemon_settings,
        GetTraceSettings $get_trace_settings,
        TargetPhpSettings $target_php_settings,
        PhpSpySettings $phpspy_settings,
        OutputSettings $output_settings,
    ): int {
        $output_dir = TraceOutputPathResolver::resolveRbtOutputDir($output_settings->output_path);
        if ($output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln("rbt output: {$output_dir}");
        } else {
            $output->writeln("<info>rbt output: {$output_dir}</info>");
        }

        $period_us = PhpSpyRbtSink::derivePeriodUs($phpspy_settings);
        $has_timestamps = $output_settings->hasRbtTimestamps();
        $compress = $output_settings->rbt_compress;
        $ext = $compress ? '.rbt.gz' : '.rbt';

        /** @var array<int, PhpSpyRbtSink> pid => sink */
        $sinks = [];
        /** @var array<int, HrtimeDeltaClock> pid => clock */
        $clocks = [];

        $write_sample = static function (
            int $pid,
            ParsedCallTrace $trace,
        ) use (
            &$sinks,
            &$clocks,
            $output_dir,
            $ext,
            $compress,
            $period_us,
            $has_timestamps,
        ): void {
            if (!isset($sinks[$pid])) {
                $path = rtrim($output_dir, '/') . "/target_{$pid}{$ext}";
                $sinks[$pid] = new PhpSpyRbtSink($path, $compress, $period_us, $has_timestamps);
                $sinks[$pid]->getWriter()->writeMetadata('pid', (string)$pid);
                $clocks[$pid] = new HrtimeDeltaClock();
            }
            $sinks[$pid]->getWriter()->writeTrace($trace, $clocks[$pid]->advance());
        };

        $process_pool = new PhpSpyProcessPool($this->phpspy_finder);
        $interrupted = $this->installSignalHandlers();
        $this->startSearcher($daemon_settings, $target_php_settings, $no_cache);

        $output->writeln('<info>searching for target processes...</info>');

        $close_finished_workers = static function () use (&$sinks, &$clocks, $process_pool): void {
            foreach ($sinks as $pid => $sink) {
                if (!$process_pool->hasProcess($pid)) {
                    $sink->close();
                    unset($sinks[$pid], $clocks[$pid]);
                }
            }
        };

        try {
            while (!$interrupted->value) {
                pcntl_signal_dispatch();
                $this->reconcileTargets($output, $process_pool, $get_trace_settings, $phpspy_settings);
                $process_pool->consumeAll($write_sample);
                $close_finished_workers();
                usleep(1000);
            }

            $output->writeln('<info>stopping all phpspy processes...</info>');
            $process_pool->consumeAll($write_sample);
            $process_pool->flushParsers($write_sample);
        } finally {
            $process_pool->stopAll();
            // Anything still open belongs to a worker that never observed a
            // not-running transition (e.g. interrupted mid-stream).
            foreach ($sinks as $sink) {
                $sink->close();
            }
        }

        return 0;
    }

    private function installSignalHandlers(): InterruptFlag
    {
        $flag = new InterruptFlag();
        pcntl_signal(SIGINT, static function () use ($flag): void {
            $flag->value = true;
        });
        pcntl_signal(SIGTERM, static function () use ($flag): void {
            $flag->value = true;
        });
        return $flag;
    }

    private function startSearcher(
        DaemonSettings $daemon_settings,
        TargetPhpSettings $target_php_settings,
        bool $no_cache,
    ): void {
        $searcher_context = $this->php_searcher_context_creator->create();
        $searcher_context->start();
        $my_pid = getmypid();
        if ($my_pid === false) {
            throw new \RuntimeException('Failed to get current process ID');
        }
        $searcher_context->sendTarget(
            $daemon_settings->target_regex,
            $target_php_settings,
            $my_pid,
            $no_cache,
            needs_compiler_globals: false,
            thread_name_regex: $daemon_settings->target_thread_regex,
        );
        $this->searcher_context = $searcher_context;
    }

    private ?PhpSearcherControllerInterface $searcher_context = null;

    private function reconcileTargets(
        OutputInterface $output,
        PhpSpyProcessPool $process_pool,
        GetTraceSettings $get_trace_settings,
        PhpSpySettings $phpspy_settings,
    ): void {
        if ($this->searcher_context === null) {
            return;
        }
        try {
            $update_message = $this->searcher_context->receivePidList();
            $target_list = $update_message->target_process_list;

            /** @var list<int> $target_pids */
            $target_pids = [];
            foreach ($target_list->getArray() as $descriptor) {
                if ($descriptor === TargetProcessDescriptor::getInvalid()) {
                    continue;
                }
                $target_pids[] = $descriptor->pid;

                if (!$process_pool->hasProcess($descriptor->pid)) {
                    $output->writeln(
                        '<info>attaching phpspy to pid ' . $descriptor->pid
                        . ' (EG: 0x' . dechex($descriptor->eg_address) . ')</info>',
                        OutputInterface::VERBOSITY_VERBOSE,
                    );
                    $process_pool->attach(
                        $descriptor->pid,
                        $descriptor->eg_address,
                        $descriptor->sg_address,
                        $get_trace_settings->depth,
                        $phpspy_settings,
                    );
                }
            }

            foreach ($process_pool->getActivePids() as $active_pid) {
                if (!in_array($active_pid, $target_pids, true)) {
                    $output->writeln(
                        '<info>detaching phpspy from pid ' . $active_pid . '</info>',
                        OutputInterface::VERBOSITY_VERBOSE,
                    );
                    $process_pool->detach($active_pid);
                }
            }
        } catch (\Throwable) {
            // Searcher may not have results yet, continue
        }
    }
}
