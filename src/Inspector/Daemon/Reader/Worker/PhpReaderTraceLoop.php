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

namespace Reli\Inspector\Daemon\Reader\Worker;

use Generator;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Inspector\Watch\TraceVarPeekCollector;
use Reli\Inspector\Watch\VariableReaderInterface;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;

use function is_null;
use function Reli\Lib\Defer\defer;

final class PhpReaderTraceLoop implements PhpReaderTraceLoopInterface
{
    public function __construct(
        private CallTraceReader $executor_globals_reader,
        private ReaderLoopProvider $reader_loop_provider,
        private ProcessStopper $process_stopper,
        private VariableReaderInterface $variable_reader,
    ) {
    }

    /**
     * @return Generator<TraceMessage>
     * @throws \Reli\Lib\Elf\Parser\ElfParserException
     * @throws \Reli\Lib\Elf\Process\ProcessSymbolReaderException
     * @throws \Reli\Lib\Elf\Tls\TlsFinderException
     * @throws \Reli\Lib\Process\MemoryReader\MemoryReaderException
     */
    #[\Override]
    public function run(
        TraceLoopSettings $loop_settings,
        TargetProcessDescriptor $target_process_descriptor,
        GetTraceSettings $get_trace_settings
    ): Generator {
        $trace_cache = new TraceCache();

        // Build a peek collector for this attach, reusing exactly the
        // single-process logic. Null when no --trace-var specs are
        // configured, so the hot path stays allocation-free.
        $var_peek_collector = null;
        if ($get_trace_settings->var_specs !== []) {
            $var_peek_collector = new TraceVarPeekCollector(
                $this->variable_reader,
                $get_trace_settings->var_specs,
                $get_trace_settings->var_every,
                $get_trace_settings->var_on_function,
            );
        }

        // The CG address rides along on the descriptor when the searcher
        // was told this daemon needs it (see PhpSearcherController::sendTarget
        // and ProcessDescriptorRetriever). static::/func_static:: specs
        // will silently resolve to <not found> when cg_address is 0,
        // matching inspector:peek-var's behaviour.
        $cg_address = $target_process_descriptor->cg_address;
        $process_specifier = new ProcessSpecifier($target_process_descriptor->pid);
        /** @var TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> */
        $target_php_settings = new TargetPhpSettings($target_process_descriptor->php_version);

        $loop = $this->reader_loop_provider->getMainLoop(
            function () use (
                $get_trace_settings,
                $target_process_descriptor,
                $loop_settings,
                $trace_cache,
                $var_peek_collector,
                $cg_address,
                $process_specifier,
                $target_php_settings,
            ): Generator {
                if ($loop_settings->stop_process and $this->process_stopper->stop($target_process_descriptor->pid)) {
                    defer($_, fn () => $this->process_stopper->resume($target_process_descriptor->pid));
                }
                $call_trace = $this->executor_globals_reader->readCallTrace(
                    $target_process_descriptor->pid,
                    $target_process_descriptor->php_version,
                    $target_process_descriptor->eg_address,
                    $target_process_descriptor->sg_address,
                    $get_trace_settings->depth,
                    $trace_cache
                );
                if (is_null($call_trace)) {
                    return;
                }
                $annotations = $var_peek_collector?->collect(
                    $call_trace,
                    $process_specifier,
                    $target_php_settings,
                    $target_process_descriptor->eg_address,
                    $cg_address,
                );
                yield new TraceMessage($call_trace, null, $annotations);
            },
            $loop_settings
        );
        /** @var Generator<TraceMessage> */
        $loop_process = $loop->invoke();
        yield from $loop_process;
    }
}
