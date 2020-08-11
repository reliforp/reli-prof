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
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use PhpProfiler\Inspector\TraceLoopProvider;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\PhpProcessReader\PhpGlobalsFinder;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\PhpProcessReader\PhpMemoryReader\ExecutorGlobalsReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GetCurrentFunctionNameCommand extends Command
{
    private PhpGlobalsFinder $php_globals_finder;
    private ExecutorGlobalsReader $executor_globals_reader;
    private TraceLoopProvider $loop_provider;
    private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input;
    private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input;
    private TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input;

    public function __construct(
        PhpGlobalsFinder $php_globals_finder,
        ExecutorGlobalsReader $executor_globals_reader,
        TraceLoopProvider $loop_provider,
        TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input
    ) {
        parent::__construct();
        $this->php_globals_finder = $php_globals_finder;
        $this->executor_globals_reader = $executor_globals_reader;
        $this->loop_provider = $loop_provider;
        $this->target_php_settings_from_console_input = $target_php_settings_from_console_input;
        $this->target_process_settings_from_console_input = $target_process_settings_from_console_input;
        $this->trace_loop_settings_from_console_input = $trace_loop_settings_from_console_input;
    }

    public function configure(): void
    {
        $this->setName('inspector:current_function')
            ->setDescription('periodically get running function name from an outer process or thread')
        ;
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->trace_loop_settings_from_console_input->setOptions($this);
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
        $target_php_settings = $this->target_php_settings_from_console_input->fromConsoleInput($input);
        $target_process_settings = $this->target_process_settings_from_console_input->fromConsoleInput($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->fromConsoleInput($input);

        $eg_address = $this->php_globals_finder->findExecutorGlobals($target_process_settings, $target_php_settings);

        $this->loop_provider->getMainLoop(
            function () use ($target_process_settings, $target_php_settings, $eg_address, $output): bool {
                $output->writeln(
                    $this->executor_globals_reader->readCurrentFunctionName(
                        $target_process_settings->pid,
                        $target_php_settings->php_version,
                        $eg_address
                    )
                );
                return true;
            },
            $loop_settings
        )->invoke();

        return 0;
    }
}
