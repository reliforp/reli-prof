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
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Memory dump action for daemon mode.
 *
 * Resolves CG address on demand via PhpGlobalsFinder,
 * then delegates to MemoryDumper.
 */
final class DaemonMemoryDumpAction implements ActionInterface
{
    /** @var array<int, int> PID → CG address cache */
    private array $cg_cache = [];

    public function __construct(
        private MemoryDumper $memory_dumper,
        private PhpGlobalsFinder $php_globals_finder,
        private string $output_dir,
        private DiskUsageTracker $disk_tracker,
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

        // Need eg_address and php_version from WatchTriggerMessage
        // These are stored in the merged event description or
        // accessible via the context. For daemon mode, we get them
        // from the WatchTriggerMessage fields passed through.
        // The caller sets these as extra context.
        $eg_address = $context->daemon_eg_address;
        $php_version = $context->daemon_php_version;
        if ($eg_address === 0 || $php_version === '') {
            Log::debug(
                'memory-dump skipped: no EG address in daemon context',
            );
            return;
        }

        // Resolve CG address (cached per PID)
        $pid = $process->pid;
        if (!isset($this->cg_cache[$pid])) {
            try {
                /** @psalm-suppress ArgumentTypeCoercion,InvalidArgument */
                $target_settings = new TargetPhpSettings(
                    php_version: $php_version,
                );
                /** @psalm-suppress InvalidArgument */
                $this->cg_cache[$pid] = $this->php_globals_finder
                    ->findCompilerGlobals($process, $target_settings);
            } catch (\Throwable $e) {
                Log::debug('memory-dump: CG resolution failed', [
                    'pid' => $pid,
                    'error' => $e->getMessage(),
                ]);
                return;
            }
        }
        $cg_address = $this->cg_cache[$pid];

        $output_path = sprintf(
            '%s/watch-%d-%s.dump',
            rtrim($this->output_dir, '/'),
            $pid,
            date('Ymd-His', (int)$event->timestamp),
        );

        try {
            /** @psalm-suppress ArgumentTypeCoercion,InvalidArgument */
            $target_settings = new TargetPhpSettings(
                php_version: $php_version,
            );
            /** @psalm-suppress InvalidArgument */
            $result = $this->memory_dumper->dump(
                $process,
                $target_settings,
                $eg_address,
                $cg_address,
                $output_path,
            );
            $this->disk_tracker->recordFile($output_path);
            Log::info('memory-dump saved', [
                'path' => $result->output_path,
                'regions' => $result->region_count,
            ]);
        } catch (\Throwable $e) {
            Log::debug('memory-dump failed', [
                'pid' => $pid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
