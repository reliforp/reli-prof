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

use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use PhpProfiler\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TargetProcessSettings;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\PhpProcessReader\PhpGlobalsFinder;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
     */
    public function __construct(
        PhpGlobalsFinder $php_globals_finder
    ) {
        parent::__construct();
        $this->php_globals_finder = $php_globals_finder;
    }

    public function configure(): void
    {
        $this->setName('inspector:eg_address')
            ->setDescription('get EG address from an outer process or thread')
            ->addOption('pid', 'p', InputOption::VALUE_REQUIRED, 'process id')
            ->addOption(
                'php-regex',
                null,
                InputOption::VALUE_OPTIONAL,
                'regex to find the php binary loaded in the target process'
            )
            ->addOption(
                'libpthread-regex',
                null,
                InputOption::VALUE_OPTIONAL,
                'regex to find the libpthread.so loaded in the target process'
            )
            ->addOption(
                'php-version',
                null,
                InputOption::VALUE_OPTIONAL,
                'php version of the target'
            )
            ->addOption(
                'php-path',
                null,
                InputOption::VALUE_OPTIONAL,
                'path to the php binary (only needed for chrooted ZTS target)'
            )
            ->addOption(
                'libpthread-path',
                null,
                InputOption::VALUE_OPTIONAL,
                'path to the libpthread.so (only needed for chrooted ZTS target)'
            )
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws ElfParserException
     * @throws TlsFinderException
     * @throws InspectorSettingsException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $target_process_settings = TargetProcessSettings::fromConsoleInput($input);
        $target_php_settings = TargetPhpSettings::fromConsoleInput($input);

        $output->writeln(
            sprintf(
                '0x%s',
                dechex($this->php_globals_finder->findExecutorGlobals($target_process_settings, $target_php_settings))
            )
        );

        return 0;
    }
}
