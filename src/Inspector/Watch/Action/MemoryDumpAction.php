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

namespace Reli\Inspector\Watch\Action;

use Reli\Inspector\MemoryDump\MemoryDumper;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Log\Log;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Fast binary memory dump action.
 *
 * Delegates to MemoryDumper (same core as inspector:memory:dump)
 * to produce a binary dump file for offline analysis.
 */
final class MemoryDumpAction implements ActionInterface
{
    public function __construct(
        private MemoryDumper $memory_dumper,
        /** @var TargetPhpSettings<'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'|'v82'|'v83'|'v84'|'v85'> */
        private TargetPhpSettings $target_php_settings,
        private int $eg_address,
        private int $cg_address,
        private string $output_dir,
        private DiskUsageTracker $disk_tracker,
        private bool $include_binary = false,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'memory-dump';
    }

    #[\Override]
    public function execute(
        TriggerEvent $event,
        ProcessSpecifier $process,
        WatchContext $context,
    ): void {
        if (!$this->disk_tracker->canWrite()) {
            Log::debug('memory-dump skipped: disk limit reached');
            return;
        }

        $output_path = sprintf(
            '%s/watch-%d-%s.dump',
            rtrim($this->output_dir, '/'),
            $process->pid,
            date('Ymd-His', (int)$event->timestamp),
        );

        try {
            $result = $this->memory_dumper->dump(
                $process,
                $this->target_php_settings,
                $this->eg_address,
                $this->cg_address,
                $output_path,
                $this->include_binary,
            );

            $this->disk_tracker->recordFile($output_path);
            Log::info('memory-dump saved', [
                'path' => $result->output_path,
                'regions' => $result->region_count,
                'bytes' => $result->total_bytes,
            ]);
        } catch (\Throwable $e) {
            Log::debug('memory-dump failed', [
                'pid' => $process->pid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
