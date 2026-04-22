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

namespace Reli\Inspector\Settings\GetTraceSettings;

use InvalidArgumentException;
use PhpCast\NullableCast;
use Reli\Inspector\Settings\InspectorSettingsException;
use Reli\Inspector\Watch\VariableSpec;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function filter_var;
use function is_null;

use const FILTER_VALIDATE_INT;

final class GetTraceSettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'depth',
                'd',
                InputOption::VALUE_OPTIONAL,
                'max depth'
            )
            ->addOption(
                'with-native-trace',
                null,
                InputOption::VALUE_NONE,
                'collect native (C-level) stack traces alongside PHP traces'
            )
            ->addOption(
                'native-trace-anytime',
                null,
                InputOption::VALUE_NONE,
                'collect native traces even when PHP trace is unavailable (e.g. during init, shutdown)'
            )
            ->addOption(
                'bulk-stack-copy',
                null,
                InputOption::VALUE_OPTIONAL,
                'bulk-copy VM stack per sample for consistency (default: on, max 1M). '
                . 'Accepts optional max size in bytes (e.g. 65536, 16K, 256K, 1M)'
            )
            ->addOption(
                'no-bulk-stack-copy',
                null,
                InputOption::VALUE_NONE,
                'disable bulk-stack-copy (fall back to per-field reads)'
            )
            ->addOption(
                'trace-var',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'variable to peek per sample and attach as an annotation '
                . '(same syntax as inspector:peek-var --var, e.g. '
                . "global::\$counter, local::App\\Svc::run()\$id, "
                . "static::App\\Cache::\$entries, func_static::App\\retry()\$n, "
                . 'memory::memory_get_usage, memory::memory_get_peak_usage)'
            )
            ->addOption(
                'trace-var-every',
                null,
                InputOption::VALUE_REQUIRED,
                'read --trace-var only every N-th sample (default: 1 = every sample)'
            )
            ->addOption(
                'trace-var-on-function',
                null,
                InputOption::VALUE_REQUIRED,
                'skip --trace-var reads when this fully-qualified function is not on the stack '
                . '(cheap gate that avoids process_vm_readv when the target frame is inactive)'
            )
        ;
    }

    private function parseBulkStackCopy(InputInterface $input): ?int
    {
        if ((bool)$input->getOption('no-bulk-stack-copy')) {
            return null;
        }

        /** @var string|bool|int|float|list<string>|null $raw */
        $raw = $input->getOption('bulk-stack-copy');
        if ($raw === null) {
            // Flag not passed at all, or passed without a value: use default.
            // bulk-stack-copy is on by default — callers opt out via --no-bulk-stack-copy.
            return GetTraceSettings::BULK_STACK_COPY_DEFAULT_MAX_SIZE;
        }
        $value = NullableCast::toString($raw);
        if ($value === null) {
            return GetTraceSettings::BULK_STACK_COPY_DEFAULT_MAX_SIZE;
        }
        // Support K/M suffixes (e.g. "64K", "1M")
        if (preg_match('/^(\d+)\s*([kKmM])?$/', $value, $m)) {
            $num = (int)$m[1];
            $suffix = strtoupper($m[2] ?? '');
            $size = match ($suffix) {
                'K' => $num * 1024,
                'M' => $num * 1024 * 1024,
                default => $num,
            };
            // Explicit 0 disables (alternative to --no-bulk-stack-copy).
            return $size > 0 ? $size : null;
        }
        throw GetTraceSettingsException::create(GetTraceSettingsException::BULK_STACK_COPY_IS_NOT_VALID_SIZE);
    }

    /**
     * @return list<VariableSpec>
     * @throws GetTraceSettingsException
     */
    private function parseVarSpecs(InputInterface $input): array
    {
        /** @var mixed $raw */
        $raw = $input->getOption('trace-var');
        if (!is_array($raw)) {
            return [];
        }
        $specs = [];
        foreach ($raw as $expression) {
            if (!is_string($expression) || $expression === '') {
                continue;
            }
            try {
                $specs[] = VariableSpec::parse($expression);
            } catch (InvalidArgumentException) {
                throw GetTraceSettingsException::create(
                    GetTraceSettingsException::TRACE_VAR_EXPRESSION_IS_INVALID,
                );
            }
        }
        return $specs;
    }

    /**
     * @throws GetTraceSettingsException
     */
    private function parseVarEvery(InputInterface $input): int
    {
        /** @var mixed $raw */
        $raw = $input->getOption('trace-var-every');
        if ($raw === null) {
            return 1;
        }
        $value = NullableCast::toString($raw);
        if ($value === null) {
            return 1;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < 1) {
            throw GetTraceSettingsException::create(
                GetTraceSettingsException::TRACE_VAR_EVERY_IS_NOT_POSITIVE_INTEGER,
            );
        }
        return $parsed;
    }

    private function parseVarOnFunction(InputInterface $input): ?string
    {
        /** @var mixed $raw */
        $raw = $input->getOption('trace-var-on-function');
        $value = NullableCast::toString($raw);
        if ($value === null || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * @throws InspectorSettingsException
     */
    public function createSettings(InputInterface $input): GetTraceSettings
    {
        $depth = NullableCast::toString($input->getOption('depth'));
        if (is_null($depth)) {
            $depth = PHP_INT_MAX;
        }
        $depth = filter_var($depth, FILTER_VALIDATE_INT);
        if ($depth === false) {
            throw GetTraceSettingsException::create(GetTraceSettingsException::DEPTH_IS_NOT_INTEGER);
        }
        $with_native_trace = (bool)$input->getOption('with-native-trace');
        $native_trace_anytime = (bool)$input->getOption('native-trace-anytime');
        if ($native_trace_anytime) {
            $with_native_trace = true;
        }

        $bulk_stack_copy_max_size = $this->parseBulkStackCopy($input);

        $var_specs = $this->parseVarSpecs($input);
        $var_every = $this->parseVarEvery($input);
        $var_on_function = $this->parseVarOnFunction($input);

        return new GetTraceSettings(
            $depth,
            $with_native_trace,
            $native_trace_anytime,
            $bulk_stack_copy_max_size,
            $var_specs,
            $var_every,
            $var_on_function,
        );
    }
}
