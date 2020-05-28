<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Command\Inspector;

use PhpProfiler\Command\CommandSettingsException;
use PhpProfiler\Command\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Command\Inspector\Settings\LoopSettings;
use PhpProfiler\Command\Inspector\Settings\TargetProcessSettings;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\PhpProcessReader\PhpGlobalsFinder;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\PhpProcessReader\PhpMemoryReader\ExecutorGlobalsReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class GetTraceCommand extends Command
{
    private PhpGlobalsFinder $php_globals_finder;
    private ExecutorGlobalsReader $executor_globals_reader;
    private TraceLoopProvider $loop_provider;

    /**
     * GetTraceCommand constructor.
     *
     * @param PhpGlobalsFinder $php_globals_finder
     * @param ExecutorGlobalsReader $executor_globals_reader
     * @param TraceLoopProvider $loop_provider
     */
    public function __construct(
        PhpGlobalsFinder $php_globals_finder,
        ExecutorGlobalsReader $executor_globals_reader,
        TraceLoopProvider $loop_provider
    ) {
        parent::__construct();
        $this->php_globals_finder = $php_globals_finder;
        $this->executor_globals_reader = $executor_globals_reader;
        $this->loop_provider = $loop_provider;
    }

    public function configure(): void
    {
        $this->setName('inspector:trace')
            ->setDescription('periodically get call trace from an outer process or thread')
            ->addOption('pid', 'p', InputOption::VALUE_REQUIRED, 'process id')
            ->addOption('depth', 'd', InputOption::VALUE_OPTIONAL, 'max depth')
            ->addOption(
                'sleep-ns',
                's',
                InputOption::VALUE_OPTIONAL,
                'nanoseconds between traces (default: 1000 * 1000 * 10)'
            )
            ->addOption(
                'max-retries',
                'r',
                InputOption::VALUE_OPTIONAL,
                'max retries on contiguous errors of read (default: 10)'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws ElfParserException
     * @throws TlsFinderException
     * @throws CommandSettingsException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $target_process_settings = TargetProcessSettings::fromConsoleInput($input);
        $loop_settings = LoopSettings::fromConsoleInput($input);
        $get_trace_settings = GetTraceSettings::fromConsoleInput($input);

        $eg_address = $this->php_globals_finder->findExecutorGlobals($target_process_settings);

        $this->loop_provider->getMainLoop(
            function () use ($get_trace_settings, $target_process_settings, $eg_address, $output): bool {
                $call_trace = $this->executor_globals_reader->readCallTrace(
                    $target_process_settings->pid,
                    $eg_address,
                    $get_trace_settings->depth
                );
                $output->writeln(join(PHP_EOL, $call_trace) . PHP_EOL);
                return true;
            },
            $loop_settings
        )->invoke();

        return 0;
    }
}
