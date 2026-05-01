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
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'output file path (default: stdout)'
        );
        $this->addOption(
            'format',
            'F',
            InputOption::VALUE_REQUIRED,
            'output format: phpspy (passthrough, default) or rbt',
            'phpspy',
        );
        $this->addOption(
            'compress',
            null,
            InputOption::VALUE_NONE,
            'gzip-compress the output (rbt format only)',
        );
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

        // Verify phpspy is available before starting
        $phpspy_path = $this->phpspy_finder->find($phpspy_settings->phpspy_path);
        $output->writeln('<info>using phpspy: ' . $phpspy_path . '</info>');

        /** @var string|null $output_path */
        $output_path = $input->getOption('output');
        /** @var string $format */
        $format = $input->getOption('format');
        $compress = (bool) $input->getOption('compress');

        if ($format !== 'phpspy' && $format !== 'rbt') {
            throw new \InvalidArgumentException(
                "unknown format '{$format}': expected 'phpspy' or 'rbt'"
            );
        }
        if ($compress && $format !== 'rbt') {
            throw new \InvalidArgumentException('--compress requires --format=rbt');
        }

        if ($format === 'rbt') {
            return $this->runRbt(
                $output,
                $no_cache,
                $daemon_settings,
                $get_trace_settings,
                $target_php_settings,
                $phpspy_settings,
                $output_path,
                $compress,
            );
        }

        return $this->runPassthrough(
            $output,
            $no_cache,
            $daemon_settings,
            $get_trace_settings,
            $target_php_settings,
            $phpspy_settings,
            $output_path,
        );
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

    private function runRbt(
        OutputInterface $output,
        bool $no_cache,
        DaemonSettings $daemon_settings,
        GetTraceSettings $get_trace_settings,
        TargetPhpSettings $target_php_settings,
        PhpSpySettings $phpspy_settings,
        ?string $output_path,
        bool $compress,
    ): int {
        $sink = new PhpSpyRbtSink(
            $output_path,
            $compress,
            PhpSpyRbtSink::derivePeriodUs($phpspy_settings),
        );
        $writer = $sink->getWriter();
        $write_sample = static function (int $pid, ParsedCallTrace $trace) use ($writer): void {
            $writer->writePidTrace($trace, $pid);
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
