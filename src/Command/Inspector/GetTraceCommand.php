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

use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\ProcessReader\PhpGlobalsFinder;
use PhpProfiler\ProcessReader\PhpMemoryReader\ExecutorGlobalsReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GetTraceCommand extends Command
{
    private const SLEEP_NANO_SECONDS_DEFAULT = 1000 * 1000 * 10;

    private PhpGlobalsFinder $php_globals_finder;
    private ExecutorGlobalsReader $executor_globals_reader;

    /**
     * GetTraceCommand constructor.
     *
     * @param PhpGlobalsFinder $php_globals_finder
     * @param ExecutorGlobalsReader $executor_globals_reader
     * @param string|null $name
     */
    public function __construct(
        PhpGlobalsFinder $php_globals_finder,
        ExecutorGlobalsReader $executor_globals_reader,
        string $name = null
    ) {
        parent::__construct($name);
        $this->php_globals_finder = $php_globals_finder;
        $this->executor_globals_reader = $executor_globals_reader;
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
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $pid = $input->getOption('pid');
        if (is_null($pid)) {
            $error_output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error_output->writeln('pid is not specified');
            return 1;
        }
        $pid = filter_var($pid, FILTER_VALIDATE_INT);
        if ($pid === false) {
            $error_output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error_output->writeln('pid is not integer');
            return 2;
        }

        $depth = $input->getOption('depth');
        if (is_null($depth)) {
            $depth = PHP_INT_MAX;
        }
        $depth = filter_var($depth, FILTER_VALIDATE_INT);
        if ($depth === false) {
            $error_output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error_output->writeln('depth is not integer');
            return 2;
        }

        $sleep_nano_seconds = $input->getOption('sleep-ns');
        if (is_null($sleep_nano_seconds)) {
            $sleep_nano_seconds = self::SLEEP_NANO_SECONDS_DEFAULT;
        }
        $sleep_nano_seconds = filter_var($sleep_nano_seconds, FILTER_VALIDATE_INT);
        if ($sleep_nano_seconds === false) {
            $error_output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error_output->writeln('sleep-ns is not integer');
            return 2;
        }

        $eg_address = $this->php_globals_finder->findExecutorGlobals($pid);

        $this->runPeriodically(
            $sleep_nano_seconds,
            function () use ($pid, $eg_address, $depth) {
                echo join(
                    PHP_EOL,
                    $this->executor_globals_reader->readCallTrace(
                        $pid,
                        $eg_address,
                        $depth
                    )
                ) , PHP_EOL, PHP_EOL;
            }
        );

        return 0;
    }

    private function runPeriodically(int $sleep_nano_seconds, callable $func): void
    {
        exec('stty -icanon -echo');
        $keyboard_input = fopen('php://stdin', 'r');
        stream_set_blocking($keyboard_input, false);

        $key = '';
        $count_retry = 0;
        while ($key !== 'q' and $count_retry < 10) {
            try {
                $func();
                $count_retry = 0;
                time_nanosleep(0, $sleep_nano_seconds);
            } catch (MemoryReaderException $e) {
                $count_retry++;
            }
            $key = fread($keyboard_input, 1);
        }
    }
}
