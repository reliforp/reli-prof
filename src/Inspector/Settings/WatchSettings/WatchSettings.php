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

final class WatchSettings
{
    public const POLL_INTERVAL_MS_DEFAULT = 1000;
    public const COOLDOWN_SECONDS_DEFAULT = 60;
    public const MAX_TRIGGERS_DEFAULT = 0;
    public const MAX_TRIGGERS_PER_HOUR_DEFAULT = 10;
    public const MAX_DUMP_SIZE_BYTES_DEFAULT = 1024 * 1024 * 1024; // 1G
    public const BACKOFF_MULTIPLIER_DEFAULT = 2.0;
    public const BACKOFF_MAX_SECONDS_DEFAULT = 3600;
    public const STATUS_INTERVAL_SECONDS_DEFAULT = 60;

    public function __construct(
        public int $poll_interval_ms,
        public int $cooldown_seconds,
        public int $max_triggers,
        public int $max_triggers_per_hour,
        public int $max_dump_size_bytes,
        public float $backoff_multiplier,
        public int $backoff_max_seconds,
        public string $action_output_dir,
        public int $status_interval_seconds,
        public bool $quiet,
        // Trigger configs
        public ?int $memory_usage_bytes,
        public ?string $memory_growth_rate,
        public bool $memory_peak_watch,
        public ?string $watch_function,
        public ?int $trace_depth_limit,
        /** @var list<string> Variable watch expressions (scope::name:op:value) */
        public array $watch_var,
        // Action configs
        /** @var list<string> */
        public array $actions,
        public ?string $action_exec_command,
        public ?string $log_file,
        public ?string $memory_output_format,
        public bool $include_binary = false,
    ) {
    }
}
