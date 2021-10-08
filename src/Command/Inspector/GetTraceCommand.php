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

use PhpProfiler\Inspector\Output\TraceFormatter\Templated\TemplatedTraceFormatterFactory;
use PhpProfiler\Inspector\RetryingLoopProvider;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TemplatedTraceFormatterSettings\TemplateSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use PhpProfiler\Inspector\TargetProcess\TargetProcessResolver;
use PhpProfiler\Inspector\TraceLoopProvider;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\PhpProcessReader\PhpGlobalsFinder;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\PhpProcessReader\PhpMemoryReader\ExecutorGlobalsReader;
use PhpProfiler\Lib\Process\ProcessStopper\ProcessStopper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function PhpProfiler\Lib\Defer\defer;

final class GetTraceCommand extends Command
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private ExecutorGlobalsReader $executor_globals_reader,
        private TraceLoopProvider $loop_provider,
        private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input,
        private TemplateSettingsFromConsoleInput $template_settings_from_console_input,
        private TemplatedTraceFormatterFactory $templated_trace_formatter_factory,
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
        $this->template_settings_from_console_input->setOptions($this);
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
        $template_settings = $this->template_settings_from_console_input->createSettings($input);
        $formatter = $this->templated_trace_formatter_factory->createFromSettings($template_settings);

        $process_specifier = $this->target_process_resolver->resolve($target_process_settings);

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

        $this->loop_provider->getMainLoop(
            function () use (
                $get_trace_settings,
                $process_specifier,
                $target_php_settings,
                $loop_settings,
                $eg_address,
                $output,
                $formatter
            ): bool {
                if ($loop_settings->stop_process and $this->process_stopper->stop($process_specifier->pid)) {
                    defer($_, fn () => $this->process_stopper->resume($process_specifier->pid));
                }
                $call_trace = $this->executor_globals_reader->readCallTrace(
                    $process_specifier->pid,
                    $target_php_settings->php_version,
                    $eg_address,
                    $get_trace_settings->depth
                );
                if (!is_null($call_trace)) {
                    $output->write($formatter->format($call_trace));
                }
                return true;
            },
            $loop_settings
        )->invoke();

        return 0;
    }
}
