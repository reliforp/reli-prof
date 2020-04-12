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


use PhpProfiler\Lib\Process\MemoryReader;
use PhpProfiler\ProcessReader\PhpGlobalsFinder;
use PhpProfiler\ProcessReader\PhpSymbolReaderCreator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class GetEgAddressCommand
 * @package PhpProfiler\Command\Inspector
 */
class GetEgAddressCommand extends Command
{
    public function configure()
    {
        $this->setName('inspector:eg_address')
            ->setDescription('get EG address from an outer process or thread')
            ->addOption('pid', 'p',InputOption::VALUE_REQUIRED, 'process id');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \PhpProfiler\Lib\Process\MemoryReaderException
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $pid = $input->getOption('pid');
        if (is_null($pid)) {
            $error_output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error_output->writeln('pid is not specified');
            return 1;
        }

        $memory_reader = new MemoryReader();
        $php_globals_finder = new PhpGlobalsFinder(
            $memory_reader,
            (new PhpSymbolReaderCreator($memory_reader))->create($pid)
        );

        $output->writeln('0x' . dechex($php_globals_finder->findExecutorGlobals()));

        return 0;
    }
}