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

namespace Reli\Inspector\Settings\WatchSettings;

use PhpCast\NullableCast;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\Trigger\MemoryGrowthRateTrigger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class WatchSettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            // Trigger options
            ->addOption(
                'memory-usage',
                null,
                InputOption::VALUE_REQUIRED,
                'trigger when heap usage exceeds this limit (e.g., 256M)',
            )
            ->addOption(
                'memory-growth-rate',
                null,
                InputOption::VALUE_REQUIRED,
                'trigger when memory growth exceeds rate (e.g., 10M/min)',
            )
            ->addOption(
                'memory-peak-watch',
                null,
                InputOption::VALUE_NONE,
                'trigger when memory peak is updated',
            )
            ->addOption(
                'watch-function',
                null,
                InputOption::VALUE_REQUIRED,
                'trigger when specified function appears in call trace',
            )
            ->addOption(
                'trace-depth-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'trigger when call stack exceeds N frames',
            )
            ->addOption(
                'watch-var',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'trigger on variable value condition'
                    . ' (format: scope::name:op:value,'
                    . ' e.g. global::cache:count_gt:10000)',
            )
            // Action options
            ->addOption(
                'action',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'actions to execute on trigger (memory-dump, trace, log, exec)',
                ['memory-dump'],
            )
            ->addOption(
                'action-exec-command',
                null,
                InputOption::VALUE_REQUIRED,
                'command to execute for exec action',
            )
            ->addOption(
                'action-output-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'output directory for dump and log files',
                '.',
            )
            ->addOption(
                'log-file',
                null,
                InputOption::VALUE_REQUIRED,
                'path to log file for log action',
            )
            ->addOption(
                'memory-output-format',
                null,
                InputOption::VALUE_REQUIRED,
                'output format for memory-dump action (json, sqlite3)',
                'json',
            )
            // Rate limiting options
            ->addOption(
                'poll-interval',
                null,
                InputOption::VALUE_REQUIRED,
                'polling interval in milliseconds (default: 1000, min: 100)',
            )
            ->addOption(
                'cooldown',
                null,
                InputOption::VALUE_REQUIRED,
                'cooldown seconds between same trigger fires (default: 60)',
            )
            ->addOption(
                'max-triggers',
                null,
                InputOption::VALUE_REQUIRED,
                'maximum total trigger count, 0 for unlimited (default: 0).'
                . ' Use --oneshot as alias for ad-hoc debugging.',
            )
            ->addOption(
                'oneshot',
                null,
                InputOption::VALUE_REQUIRED,
                'alias for --max-triggers: capture N trigger events then exit',
            )
            ->addOption(
                'max-triggers-per-hour',
                null,
                InputOption::VALUE_REQUIRED,
                'maximum triggers per hour (default: 10)',
            )
            ->addOption(
                'max-dump-size',
                null,
                InputOption::VALUE_REQUIRED,
                'maximum cumulative dump file size (default: 1G)',
            )
            ->addOption(
                'backoff-multiplier',
                null,
                InputOption::VALUE_REQUIRED,
                'exponential backoff multiplier for consecutive triggers (default: 2.0)',
            )
            ->addOption(
                'backoff-max',
                null,
                InputOption::VALUE_REQUIRED,
                'maximum backoff seconds (default: 3600)',
            )
            ->addOption(
                'status-interval',
                null,
                InputOption::VALUE_REQUIRED,
                'status summary interval in seconds for daemon mode (default: 60)',
            )
            ->addOption(
                'include-binary',
                null,
                InputOption::VALUE_NONE,
                'include read-only binary segments in memory dumps',
            )
            ->addOption(
                'quiet-watch',
                null,
                InputOption::VALUE_NONE,
                'suppress terminal status output',
            )
        ;
    }

    public function createSettings(InputInterface $input): WatchSettings
    {
        $memory_usage_str = NullableCast::toString($input->getOption('memory-usage'));
        $memory_usage_bytes = $memory_usage_str !== null ? HeapStats::parseSize($memory_usage_str) : null;

        $memory_growth_rate = NullableCast::toString($input->getOption('memory-growth-rate'));
        if ($memory_growth_rate !== null) {
            // Validate the format early
            MemoryGrowthRateTrigger::parseRate($memory_growth_rate);
        }

        $trace_depth_str = NullableCast::toString($input->getOption('trace-depth-limit'));
        $trace_depth_limit = $trace_depth_str !== null ? (int)$trace_depth_str : null;

        $poll_interval = (int)($input->getOption('poll-interval') ?? WatchSettings::POLL_INTERVAL_MS_DEFAULT);
        $poll_interval = max($poll_interval, 100);

        $max_dump_size_str = NullableCast::toString($input->getOption('max-dump-size'));
        $max_dump_size = $max_dump_size_str !== null
            ? HeapStats::parseSize($max_dump_size_str)
            : WatchSettings::MAX_DUMP_SIZE_BYTES_DEFAULT;

        /** @var list<string> $actions */
        $actions = $input->getOption('action');

        // --oneshot=N is an alias for --max-triggers=N
        $max_triggers_raw = (string)(
            $input->getOption('max-triggers')
            ?? $input->getOption('oneshot')
            ?? WatchSettings::MAX_TRIGGERS_DEFAULT
        );

        return new WatchSettings(
            poll_interval_ms: $poll_interval,
            cooldown_seconds: (int)($input->getOption('cooldown') ?? WatchSettings::COOLDOWN_SECONDS_DEFAULT),
            max_triggers: (int)$max_triggers_raw,
            max_triggers_per_hour: (int)(
                $input->getOption('max-triggers-per-hour')
                ?? WatchSettings::MAX_TRIGGERS_PER_HOUR_DEFAULT
            ),
            max_dump_size_bytes: $max_dump_size,
            backoff_multiplier: (float)(
                $input->getOption('backoff-multiplier')
                ?? WatchSettings::BACKOFF_MULTIPLIER_DEFAULT
            ),
            backoff_max_seconds: (int)(
                $input->getOption('backoff-max')
                ?? WatchSettings::BACKOFF_MAX_SECONDS_DEFAULT
            ),
            action_output_dir: (string)($input->getOption('action-output-dir') ?? '.'),
            status_interval_seconds: (int)(
                $input->getOption('status-interval')
                ?? WatchSettings::STATUS_INTERVAL_SECONDS_DEFAULT
            ),
            quiet: (bool)$input->getOption('quiet-watch'),
            memory_usage_bytes: $memory_usage_bytes,
            memory_growth_rate: $memory_growth_rate,
            memory_peak_watch: (bool)$input->getOption('memory-peak-watch'),
            watch_function: NullableCast::toString($input->getOption('watch-function')),
            trace_depth_limit: $trace_depth_limit,
            watch_var: array_values(array_filter(
                array_map(
                    /** @param mixed $v */
                    fn ($v): ?string => NullableCast::toString($v),
                    (array)$input->getOption('watch-var'),
                ),
                fn (?string $v): bool => $v !== null,
            )),
            actions: $actions,
            action_exec_command: NullableCast::toString($input->getOption('action-exec-command')),
            log_file: NullableCast::toString($input->getOption('log-file')),
            memory_output_format: NullableCast::toString($input->getOption('memory-output-format')),
            include_binary: (bool)$input->getOption('include-binary'),
        );
    }
}
