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
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\PhpProcessReader\PhpGlobalsFinder;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class GetEgAddressCommand
 * @package PhpProfiler\Command\Inspector
 */
final class GetEgAddressCommand extends Command
{
    private PhpGlobalsFinder $php_globals_finder;

    /**
     * GetEgAddressCommand constructor.
     *
     * @param PhpGlobalsFinder $php_globals_finder
     * @param string|null $name
     */
    public function __construct(
        PhpGlobalsFinder $php_globals_finder,
        string $name = null
    ) {
        parent::__construct($name);
        $this->php_globals_finder = $php_globals_finder;
    }

    public function configure(): void
    {
        $this->setName('inspector:eg_address')
            ->setDescription('get EG address from an outer process or thread')
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
            $this->writeError('pid is not specified', $output);
            return 1;
        }
        $pid = filter_var($pid, FILTER_VALIDATE_INT);
        if ($pid === false) {
            $this->writeError('pid is not integer', $output);
            return 2;
        }

        $output->writeln('0x' . dechex($this->php_globals_finder->findExecutorGlobals($pid)));

        return 0;
    }

    public function writeError(string $message, OutputInterface $output): void
    {
        $error_output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $error_output->writeln($message);
    }
}
