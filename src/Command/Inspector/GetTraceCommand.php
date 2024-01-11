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

namespace Reli\Command\Inspector;

use Reli\Inspector\Output\TraceOutput\TraceOutputFactory;
use Reli\Inspector\RetryingLoopProvider;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use Reli\Inspector\Settings\InspectorSettingsException;
use Reli\Inspector\Settings\OutputSettings\OutputSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Inspector\TraceLoopProvider;
use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Elf\Process\ProcessSymbolReaderException;
use Reli\Lib\Elf\Tls\TlsFinderException;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Reli\Lib\Defer\defer;

final class GetTraceCommand extends Command
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
        private CallTraceReader $executor_globals_reader,
        private TraceLoopProvider $loop_provider,
        private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input,
        private OutputSettingsFromConsoleInput $output_settings_from_console_input,
        private TraceOutputFactory $trace_output_factory,
        private ProcessStopper $process_stopper,
        private TargetProcessResolver $target_process_resolver,
        private RetryingLoopProvider $retrying_loop_provider,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('inspector:trace')
            ->setDescription('periodically get call trace from an outer process or thread')
        ;
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->get_trace_settings_from_console_input->setOptions($this);
        $this->trace_loop_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->output_settings_from_console_input->setOptions($this);
    }

    /**
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws ElfParserException
     * @throws TlsFinderException
     * @throws InspectorSettingsException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);
        $trace_output = $this->trace_output_factory->fromSettingsAndConsoleOutput(
            $output,
            $this->output_settings_from_console_input->createSettings($input),
        );

        $process_specifier = $this->target_process_resolver->resolve($target_process_settings);

        $target_php_settings = $this->php_version_detector->decidePhpVersion(
            $process_specifier,
            $target_php_settings
        );

        // On targeting ZTS, it's possible that libpthread.so of the target process isn't yet loaded
        // at this point. In that case the TLS block can't be located, then the address of EG can't
        // be found also. So simply retrying the whole process of finding EG here.
        $eg_address = $this->retrying_loop_provider->do(
            try: fn () => $this->php_globals_finder->findExecutorGlobals(
                $process_specifier,
                $target_php_settings
            ),
            retry_on: [\Throwable::class],
            max_retry: 10,
            interval_on_retry_ns: 1000 * 1000 * 10,
        );

        $sg_address = $this->php_globals_finder->findSAPIGlobals(
            $process_specifier,
            $target_php_settings
        );

        $trace_cache = new TraceCache();
        $this->loop_provider->getMainLoop(
            function () use (
                $get_trace_settings,
                $process_specifier,
                $target_php_settings,
                $loop_settings,
                $eg_address,
                $sg_address,
                $trace_output,
                $trace_cache,
            ): bool {
                assert($target_php_settings->isDecided());
                if ($loop_settings->stop_process and $this->process_stopper->stop($process_specifier->pid)) {
                    defer($_, fn () => $this->process_stopper->resume($process_specifier->pid));
                }
                $call_trace = $this->executor_globals_reader->readCallTrace(
                    $process_specifier->pid,
                    $target_php_settings->php_version,
                    $eg_address,
                    $sg_address,
                    $get_trace_settings->depth,
                    $trace_cache,
                    $get_trace_settings->start_with_trigger,
                );
                if (!is_null($call_trace)) {
                    $trace_output->output($call_trace);
                }
                return true;
            },
            $loop_settings
        )->invoke();

        return 0;
    }
}
