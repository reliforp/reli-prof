<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Command\Inspector;

use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReader;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\ProcessReader\PhpGlobalsFinder;
use PhpProfiler\ProcessReader\PhpMemoryReader\ExecutorGlobalsReader;
use PhpProfiler\ProcessReader\PhpSymbolReaderCreator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GetCurrentFunctionNameCommand extends Command
{
    public function configure(): void
    {
        $this->setName('inspector:current_function')
            ->setDescription('periodically get running function name from an outer process or thread')
            ->addOption('pid', 'p', InputOption::VALUE_REQUIRED, 'process id');
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

        $memory_reader = new MemoryReader();
        $php_globals_finder = new PhpGlobalsFinder(
            (new PhpSymbolReaderCreator($memory_reader))->create($pid)
        );

        $eg_address = $php_globals_finder->findExecutorGlobals();
        $eg_reader = new ExecutorGlobalsReader(
            $memory_reader,
            new ZendTypeReader(ZendTypeReader::V74)
        );

        exec('stty -icanon -echo');
        $keyboard_input = fopen('php://stdin', 'r');
        stream_set_blocking($keyboard_input, false);

        $key = '';
        $count_retry = 0;
        while ($key !== 'q' and $count_retry < 10) {
            try {
                echo $eg_reader->readCurrentFunctionName($pid, $eg_address) , PHP_EOL;
                $count_retry = 0;
                time_nanosleep(0, 1000 * 1000 * 10);
            } catch (MemoryReaderException $e) {
                $count_retry++;
            }
            $key = fread($keyboard_input, 1);
        }

        return 0;
    }
}
