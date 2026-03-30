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
use Reli\Lib\Process\ProcessStopper\ProcessStopper;

/**
 * Memory dump action for daemon mode.
 *
 * Uses EG/CG addresses from WatchTriggerMessage (via WatchContext)
 * which originate from WatchTargetDescriptor resolved by the searcher.
 */
final class DaemonMemoryDumpAction implements ActionInterface
{
    public function __construct(
        private MemoryDumper $memory_dumper,
        private ProcessStopper $process_stopper,
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

        $eg = $context->daemon_eg_address;
        $cg = $context->daemon_cg_address;
        $ver = $context->daemon_php_version;
        if ($eg === 0 || $cg === 0 || $ver === '') {
            Log::debug(
                'memory-dump skipped: missing addresses'
                    . ' in daemon context',
            );
            return;
        }

        $output_path = sprintf(
            '%s/watch-%d-%s.dump',
            rtrim($this->output_dir, '/'),
            $process->pid,
            date('Ymd-His', (int)$event->timestamp),
        );

        $stopped = $this->process_stopper->stop($process->pid);
        try {
            /** @psalm-suppress ArgumentTypeCoercion,InvalidArgument */
            $target_settings = new TargetPhpSettings(
                php_version: $ver,
            );
            /** @psalm-suppress InvalidArgument */
            $result = $this->memory_dumper->dump(
                $process,
                $target_settings,
                $eg,
                $cg,
                $output_path,
                $this->include_binary,
            );
            $this->disk_tracker->recordFile($output_path);
            Log::info('memory-dump saved', [
                'path' => $result->output_path,
                'regions' => $result->region_count,
            ]);
        } catch (\Throwable $e) {
            Log::debug('memory-dump failed', [
                'pid' => $process->pid,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($stopped) {
                $this->process_stopper->resume($process->pid);
            }
        }
    }
}
