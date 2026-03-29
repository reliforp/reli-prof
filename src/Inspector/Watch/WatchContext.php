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

use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class WatchContext
{
    /**
     * @param array<string, VariableValue> $variable_values
     *     Keyed by "scope::name" (e.g. "global::$cache")
     */
    public function __construct(
        public readonly int $pid,
        public readonly HeapStats $heap_stats,
        public readonly ?CallTrace $call_trace,
        public readonly ?bool $has_exception,
        public readonly float $timestamp,
        public readonly ?self $previous,
        public readonly array $variable_values = [],
        /** Daemon mode: EG address from WatchTriggerMessage */
        public readonly int $daemon_eg_address = 0,
        /** Daemon mode: CG address from WatchTriggerMessage */
        public readonly int $daemon_cg_address = 0,
        /** Daemon mode: PHP version from WatchTriggerMessage */
        public readonly string $daemon_php_version = '',
    ) {
    }
}
