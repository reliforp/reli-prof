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

use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\PhpSpy\PhpSpyProcessPool;
use Reli\Inspector\Daemon\Searcher\Context\PhpSearcherContextCreator;
use Reli\Inspector\Settings\DaemonSettings\DaemonSettingsFromConsoleInput;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use Reli\Inspector\Settings\PhpSpySettings\PhpSpySettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Lib\PhpSpy\PhpSpyFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PhpSpyDaemonCommand extends Command
{
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
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
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
        $output_stream = \STDOUT;
        if (is_string($output_path)) {
            $stream = fopen($output_path, 'w');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open output file: ' . $output_path);
            }
            $output_stream = $stream;
        }

        $process_pool = new PhpSpyProcessPool($this->phpspy_finder);

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
        );

        $interrupted = false;
        pcntl_signal(SIGINT, function () use (&$interrupted) {
            $interrupted = true;
        });
        pcntl_signal(SIGTERM, function () use (&$interrupted) {
            $interrupted = true;
        });

        $output->writeln('<info>searching for target processes...</info>');

        while (!$interrupted) {
            pcntl_signal_dispatch();

            // Receive updated process list from searcher (non-blocking via short timeout)
            try {
                $update_message = $searcher_context->receivePidList();
                $target_list = $update_message->target_process_list;

                // Get current target PIDs
                /** @var list<int> $target_pids */
                $target_pids = [];
                foreach ($target_list->getArray() as $descriptor) {
                    if ($descriptor === TargetProcessDescriptor::getInvalid()) {
                        continue;
                    }
                    $target_pids[] = $descriptor->pid;

                    // Start phpspy for new processes
                    if (!$process_pool->hasProcess($descriptor->pid)) {
                        $output->writeln(
                            '<info>attaching phpspy to pid ' . $descriptor->pid
                            . ' (EG: 0x' . dechex($descriptor->eg_address) . ')</info>',
                            OutputInterface::VERBOSITY_VERBOSE,
                        );
                        $process_pool->attach(
                            $descriptor->pid,
                            $descriptor->eg_address,
                            $get_trace_settings->depth,
                            $phpspy_settings,
                        );
                    }
                }

                // Detach phpspy from processes that are no longer targets
                foreach ($process_pool->getActivePids() as $active_pid) {
                    if (!in_array($active_pid, $target_pids, true)) {
                        $output->writeln(
                            '<info>detaching phpspy from pid ' . $active_pid . '</info>',
                            OutputInterface::VERBOSITY_VERBOSE,
                        );
                        $process_pool->detach($active_pid);
                    }
                }
            } catch (\Throwable $e) {
                // Searcher may not have results yet, continue
            }

            // Passthrough output from all running phpspy processes
            $process_pool->passthroughAll($output_stream);

            usleep(1000); // 1ms poll interval
        }

        $output->writeln('<info>stopping all phpspy processes...</info>');
        $process_pool->stopAll();

        if ($output_stream !== \STDOUT) {
            fclose($output_stream);
        }

        return 0;
    }
}
