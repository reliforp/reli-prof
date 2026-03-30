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
use Reli\Inspector\MemoryDump\MemoryDumpReaderFactory;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Log\Log;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Memory dump action for daemon mode.
 *
 * Uses EG/CG addresses from WatchTriggerMessage (via WatchContext)
 * which originate from WatchTargetDescriptor resolved by the searcher.
 * Optionally runs inline analysis to output JSON or DB formats.
 */
final class DaemonMemoryDumpAction implements ActionInterface
{
    public function __construct(
        private MemoryDumper $memory_dumper,
        private string $output_dir,
        private DiskUsageTracker $disk_tracker,
        private bool $include_binary = false,
        private ?string $memory_output_format = null,
        private ?MemoryDumpReaderFactory $memory_dump_reader_factory = null,
        private ?MemoryProfilerSettings $memory_profiler_settings = null,
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

        $base_name = sprintf(
            '%s/watch-%d-%s',
            rtrim($this->output_dir, '/'),
            $process->pid,
            date('Ymd-His', (int)$event->timestamp),
        );
        $output_path = $base_name . '.dump';

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

            $this->analyzeIfRequested($output_path, $base_name);
        } catch (\Throwable $e) {
            Log::debug('memory-dump failed', [
                'pid' => $process->pid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function analyzeIfRequested(string $dump_path, string $base_name): void
    {
        if (
            $this->memory_output_format === null
            || $this->memory_dump_reader_factory === null
            || $this->memory_profiler_settings === null
        ) {
            return;
        }

        try {
            $settings = $this->buildAnalysisSettings($base_name);
            $reader = $this->memory_dump_reader_factory->createFromPath($dump_path, []);
            $reader->read($settings);

            Log::info('memory-dump analyzed', [
                'format' => $this->memory_output_format,
            ]);
        } catch (\Throwable $e) {
            Log::debug('memory-dump analysis failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildAnalysisSettings(string $base_name): MemoryProfilerSettings
    {
        $output_path = match ($this->memory_output_format) {
            'json' => $base_name . '.json',
            'sqlite3' => $base_name . '.sqlite3',
            default => null,
        };

        return new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: true,
            output_format: $this->memory_output_format ?? 'json',
            output_path: $output_path ?? $this->memory_profiler_settings->output_path,
            db_host: $this->memory_profiler_settings->db_host,
            db_port: $this->memory_profiler_settings->db_port,
            db_name: $this->memory_profiler_settings->db_name,
            db_user: $this->memory_profiler_settings->db_user,
            db_password: $this->memory_profiler_settings->db_password,
        );
    }
}
