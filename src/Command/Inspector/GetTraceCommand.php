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

use Reli\Inspector\Output\TraceFormatter\MergedCallTraceFormatter;
use Reli\Inspector\Output\TraceOutput\BinaryTraceOutput;
use Reli\Inspector\Output\TraceOutput\FormattedMergedTraceOutput;
use Reli\Inspector\Output\TraceOutput\MergedTraceOutput;
use Reli\Inspector\Output\TraceOutput\TraceOutputFactory;
use Reli\Inspector\Output\OutputChannel\StreamOutputChannel;
use Reli\Inspector\RetryingLoopProvider;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use Reli\Inspector\Settings\InspectorSettingsException;
use Reli\Inspector\Settings\OutputSettings\OutputSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Inspector\TraceLoopProvider;
use Reli\Inspector\Watch\TraceVarPeekCollector;
use Reli\Inspector\Watch\VariableReaderInterface;
use Reli\Lib\Dwarf\NativeTraceCollector;
use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\ProcessSymbolReaderException;
use Reli\Lib\Elf\Tls\TlsFinderException;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceMerger;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        private BinaryAnalysisCache $binary_analysis_cache,
        private VariableReaderInterface $variable_reader,
        private ?NativeTraceCollector $native_trace_collector = null,
    ) {
        parent::__construct();
    }

    #[\Override]
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
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
    }

    /**
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws ElfParserException
     * @throws TlsFinderException
     * @throws InspectorSettingsException
     */
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('no-cache')) {
            $this->binary_analysis_cache->disable();
        }
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);
        $output_settings = $this->output_settings_from_console_input->createSettings($input);
        $trace_output = $this->trace_output_factory->fromSettingsAndConsoleOutput(
            $output,
            $output_settings,
            $loop_settings,
        );

        // Native trace requires process stopping (for ptrace GETREGS)
        $with_native = $get_trace_settings->with_native_trace;
        if ($with_native && !$loop_settings->stop_process) {
            $output->writeln(
                '<comment>--with-native-trace requires stopping the target process (ptrace GETREGS).'
                . ' Implicitly enabling --stop-process (-S).</comment>'
            );
            $loop_settings = new \Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings(
                $loop_settings->sleep_nano_seconds,
                $loop_settings->cancel_key,
                $loop_settings->max_retries,
                true,
            );
        }

        if ($get_trace_settings->bulk_stack_copy_max_size !== null) {
            $this->executor_globals_reader->enableBulkStackCopy($get_trace_settings->bulk_stack_copy_max_size);
        }

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

        // --trace-var: resolve CG up front (only if any spec needs it) and
        // construct the per-sample peek collector. When no specs are
        // configured the collector stays null and the loop takes the
        // zero-overhead path.
        $cg_address = 0;
        $var_peek_collector = null;
        if ($get_trace_settings->var_specs !== []) {
            if ($get_trace_settings->needsCompilerGlobals()) {
                $cg_address = $this->php_globals_finder->findCompilerGlobals(
                    $process_specifier,
                    $target_php_settings,
                );
            }
            $var_peek_collector = new TraceVarPeekCollector(
                $this->variable_reader,
                $get_trace_settings->var_specs,
                $get_trace_settings->var_every,
                $get_trace_settings->var_on_function,
            );
        }

        // Set up merged trace output if native tracing is enabled
        $merged_trace_output = null;
        $trace_merger = null;
        if ($with_native && $this->native_trace_collector !== null) {
            $this->native_trace_collector->refreshMemoryMap($process_specifier->pid);
            $trace_merger = new TraceMerger();
            if ($trace_output instanceof MergedTraceOutput) {
                // BinaryTraceOutput handles merged traces directly
                $merged_trace_output = $trace_output;
            } else {
                $stream = ($output_settings->output_path !== null)
                    ? (fopen($output_settings->output_path, 'w') ?: \STDOUT)
                    : \STDOUT;
                $merged_trace_output = new FormattedMergedTraceOutput(
                    new StreamOutputChannel($stream),
                    new MergedCallTraceFormatter(),
                );
            }
        }

        $trace_cache = new TraceCache();
        $native_collector = $this->native_trace_collector;
        $native_trace_anytime = $get_trace_settings->native_trace_anytime;

        $this->loop_provider->getMainLoop(
            function () use (
                $get_trace_settings,
                $process_specifier,
                $target_php_settings,
                $loop_settings,
                $eg_address,
                $sg_address,
                $cg_address,
                $trace_output,
                $trace_cache,
                $with_native,
                $native_collector,
                $merged_trace_output,
                $trace_merger,
                $native_trace_anytime,
                $var_peek_collector,
            ): bool {
                if ($loop_settings->stop_process and $this->process_stopper->stop($process_specifier->pid)) {
                    defer($_, fn () => $this->process_stopper->resume($process_specifier->pid));
                }
                $call_trace = $this->executor_globals_reader->readCallTrace(
                    $process_specifier->pid,
                    $target_php_settings->php_version,
                    $eg_address,
                    $sg_address,
                    $get_trace_settings->depth,
                    $trace_cache
                );
                if (
                    $with_native && $native_collector !== null
                    && $merged_trace_output !== null && $trace_merger !== null
                ) {
                    if ($call_trace !== null || $native_trace_anytime) {
                        $native_trace = $native_collector->collect($process_specifier->pid);
                        if ($native_trace !== null) {
                            // Merged native+php traces intentionally skip
                            // --trace-var annotations for now; the merged
                            // output path has no annotation slot yet.
                            $php_trace = $call_trace ?? new CallTrace();
                            $merged = $trace_merger->merge($native_trace, $php_trace);
                            $merged_trace_output->outputMerged($merged);
                        } elseif ($call_trace !== null) {
                            $annotations = $var_peek_collector?->collect(
                                $call_trace,
                                $process_specifier,
                                $target_php_settings,
                                $eg_address,
                                $cg_address,
                            );
                            $trace_output->output($call_trace, $annotations);
                        }
                    }
                } elseif ($call_trace !== null) {
                    $annotations = $var_peek_collector?->collect(
                        $call_trace,
                        $process_specifier,
                        $target_php_settings,
                        $eg_address,
                        $cg_address,
                    );
                    $trace_output->output($call_trace, $annotations);
                }
                return true;
            },
            $loop_settings
        )->invoke();

        if ($trace_output instanceof BinaryTraceOutput) {
            $trace_output->finish();
        }

        return 0;
    }
}
