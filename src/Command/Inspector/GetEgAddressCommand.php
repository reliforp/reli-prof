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
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\PhpProcessReader\PhpGlobalsFinder;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class GetEgAddressCommand
 * @package PhpProfiler\Command\Inspector
 */
final class GetEgAddressCommand extends Command
{
    private PhpGlobalsFinder $php_globals_finder;
    private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input;
    private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input;

    public function __construct(
        PhpGlobalsFinder $php_globals_finder,
        TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input
    ) {
        parent::__construct();
        $this->php_globals_finder = $php_globals_finder;
        $this->target_php_settings_from_console_input = $target_php_settings_from_console_input;
        $this->target_process_settings_from_console_input = $target_process_settings_from_console_input;
    }

    public function configure(): void
    {
        $this->setName('inspector:eg_address')
            ->setDescription('get EG address from an outer process or thread')
        ;
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->target_process_settings_from_console_input->setOptions($this);
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
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);

        $output->writeln(
            sprintf(
                '0x%s',
                dechex($this->php_globals_finder->findExecutorGlobals($target_process_settings, $target_php_settings))
            )
        );

        return 0;
    }
}
