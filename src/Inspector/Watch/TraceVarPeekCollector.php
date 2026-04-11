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

namespace Reli\Inspector\Watch;

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Reli\Lib\Process\ProcessSpecifier;
use Throwable;

/**
 * Per-sample variable-peek collector for `inspector:trace --trace-var`.
 *
 * Holds the parsed spec list and per-sample throttle state (sample_index),
 * so the trace loop only has to call `collect()` once per sample and get
 * back an annotation map (or null) to pass into `TraceOutput::output()`.
 *
 * Construction is cheap; the collector is expected to live for the duration
 * of a single trace command invocation. It is not thread-safe — each worker
 * in daemon mode constructs its own.
 */
final class TraceVarPeekCollector
{
    private int $sample_index = 0;

    /**
     * @param list<VariableSpec> $var_specs non-empty; callers should not
     *     construct a collector when there are no specs (use null instead).
     * @param int $var_every read variables only every N-th sample (>= 1)
     * @param string|null $var_on_function skip reads when this FQN is
     *     absent from the current call stack; null disables the gate.
     */
    public function __construct(
        private VariableReaderInterface $variable_reader,
        private array $var_specs,
        private int $var_every = 1,
        private ?string $var_on_function = null,
    ) {
    }

    /**
     * Collect annotations for a single trace sample.
     *
     * Returns null when:
     *   - this sample is skipped by --trace-var-every,
     *   - the --trace-var-on-function gate fails (target frame absent),
     *   - VariableReader::readVariables() throws (target gone, stale frame,
     *     transient memory read failure — we degrade gracefully rather than
     *     killing the loop), or
     *   - every spec resolved to <not found> so there is nothing to attach.
     *
     * Otherwise returns a map keyed by the spec's lookup_key (e.g.
     * "global::$counter") with pre-formatted string values ready to be
     * embedded in phpspy comment lines or interned into rbt
     * SAMPLE_ANNOTATION events.
     *
     * @param TargetPhpSettings<
     *     value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>
     * > $target_php_settings
     * @return array<string, string>|null
     */
    public function collect(
        CallTrace $call_trace,
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address,
    ): ?array {
        // Advance the cycle counter regardless of gate outcome so
        // --trace-var-every behaves predictably even when --trace-var-on-function
        // suppresses intermediate reads.
        $current_index = ++$this->sample_index;

        if ($current_index % $this->var_every !== 0) {
            return null;
        }

        if (
            $this->var_on_function !== null
            && !$this->hasFunctionOnStack($call_trace, $this->var_on_function)
        ) {
            return null;
        }

        try {
            $values = $this->variable_reader->readVariables(
                $this->var_specs,
                $process_specifier,
                $target_php_settings,
                $eg_address,
                $cg_address,
            );
        } catch (Throwable) {
            return null;
        }

        if ($values === []) {
            return null;
        }

        $result = [];
        foreach ($values as $lookup_key => $value) {
            $result[$lookup_key] = VariableValueFormatter::format($value);
        }
        return $result;
    }

    private function hasFunctionOnStack(CallTrace $trace, string $target_fqn): bool
    {
        foreach ($trace->call_frames as $frame) {
            if ($frame->getFullyQualifiedFunctionName() === $target_fqn) {
                return true;
            }
        }
        return false;
    }
}
