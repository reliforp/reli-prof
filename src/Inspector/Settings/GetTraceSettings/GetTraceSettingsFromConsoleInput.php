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

use PhpCast\NullableCast;
use Reli\Inspector\Settings\InspectorSettingsException;
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
                'bulk-copy VM stack per sample for consistency (default max: 64K). '
                . 'Accepts optional max size in bytes (e.g. 65536, 16K, 256K)'
            )
        ;
    }

    private function parseBulkStackCopy(InputInterface $input): ?int
    {
        /** @var string|bool|int|float|list<string>|null $raw */
        $raw = $input->getOption('bulk-stack-copy');
        if ($raw === null) {
            if (!$input->hasParameterOption(['--bulk-stack-copy'])) {
                return null;
            }
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
            return match ($suffix) {
                'K' => $num * 1024,
                'M' => $num * 1024 * 1024,
                default => $num,
            };
        }
        throw GetTraceSettingsException::create(GetTraceSettingsException::BULK_STACK_COPY_IS_NOT_VALID_SIZE);
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

        return new GetTraceSettings($depth, $with_native_trace, $native_trace_anytime, $bulk_stack_copy_max_size);
    }
}
