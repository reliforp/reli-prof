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

use Reli\Inspector\MemoryDump\MemoryDumper;
use Reli\Inspector\MemoryDump\MemoryDumpReaderFactory;
use Reli\Inspector\Output\TraceOutput\TraceOutput;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Action\ActionInterface;
use Reli\Inspector\Watch\Action\DaemonTraceAction;
use Reli\Inspector\Watch\Action\ExecAction;
use Reli\Inspector\Watch\Action\LogAction;
use Reli\Inspector\Watch\Action\MemoryDumpAction;
use Reli\Inspector\Watch\Action\TraceAction;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;

final class ActionFactory
{
    public function __construct(
        private CallTraceReader $call_trace_reader,
        private MemoryDumper $memory_dumper,
        private MemoryDumpReaderFactory $memory_dump_reader_factory,
    ) {
    }

    /**
     * Build actions for single-process mode.
     *
     * @param value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @param \Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings $target_php_settings
     * @phpstan-param \Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings<
     *     'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'|'v82'|'v83'|'v84'|'v85'
     * > $target_php_settings
     * @return list<ActionInterface>
     */
    public function buildActions(
        WatchSettings $settings,
        TraceOutput $trace_output,
        string $php_version,
        int $eg_address,
        int $sg_address,
        int $cg_address,
        int $depth,
        TargetPhpSettings $target_php_settings,
        DiskUsageTracker $disk_tracker,
    ): array {
        $actions = [];

        foreach ($settings->actions as $action_name) {
            $action = $this->buildAction(
                $action_name,
                $settings,
                $trace_output,
                $php_version,
                $eg_address,
                $sg_address,
                $cg_address,
                $depth,
                $target_php_settings,
                $disk_tracker,
            );
            if ($action !== null) {
                $actions[] = $action;
            }
        }

        if (count($actions) === 0) {
            /** @psalm-suppress InvalidArgument */
            $actions[] = $this->buildMemoryDumpAction(
                $settings,
                $target_php_settings,
                $eg_address,
                $cg_address,
                $disk_tracker,
            );
        }

        return $actions;
    }

    /**
     * Build actions for daemon mode (trace comes from worker).
     *
     * @return list<ActionInterface>
     */
    public function buildDaemonActions(
        WatchSettings $settings,
        TraceOutput $trace_output,
        DiskUsageTracker $disk_tracker,
    ): array {
        $actions = [];

        foreach ($settings->actions as $action_name) {
            switch ($action_name) {
                case 'trace':
                    $actions[] = new DaemonTraceAction($trace_output);
                    break;
                case 'log':
                    $actions[] = $settings->log_file !== null
                        ? LogAction::toFile($settings->log_file)
                        : new LogAction();
                    break;
                case 'exec':
                    if ($settings->action_exec_command !== null) {
                        $actions[] = new ExecAction(
                            $settings->action_exec_command,
                        );
                    }
                    break;
                case 'memory-dump':
                    $actions[] = $this->buildDaemonMemoryDumpAction(
                        $settings,
                        $disk_tracker,
                    );
                    break;
            }
        }

        if (count($actions) === 0) {
            $actions[] = $settings->log_file !== null
                ? LogAction::toFile($settings->log_file)
                : new LogAction();
        }

        return $actions;
    }

    /**
     * @param value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @psalm-suppress InvalidArgument
     */
    private function buildAction(
        string $action_name,
        WatchSettings $settings,
        TraceOutput $trace_output,
        string $php_version,
        int $eg_address,
        int $sg_address,
        int $cg_address,
        int $depth,
        TargetPhpSettings $target_php_settings,
        DiskUsageTracker $disk_tracker,
    ): ?ActionInterface {
        return match ($action_name) {
            'trace' => new TraceAction(
                $this->call_trace_reader,
                $trace_output,
                $php_version,
                $eg_address,
                $sg_address,
                $depth,
            ),
            'log' => $settings->log_file !== null
                ? LogAction::toFile($settings->log_file)
                : new LogAction(),
            /** @psalm-suppress InvalidArgument */
            'memory-dump' => $this->buildMemoryDumpAction(
                $settings,
                $target_php_settings,
                $eg_address,
                $cg_address,
                $disk_tracker,
            ),
            'exec' => $settings->action_exec_command !== null
                ? new ExecAction($settings->action_exec_command)
                : null,
            default => null,
        };
    }

    /**
     * @psalm-suppress InvalidArgument
     * @param TargetPhpSettings<'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'|'v82'|'v83'|'v84'|'v85'> $target_php_settings
     */
    private function buildMemoryDumpAction(
        WatchSettings $settings,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address,
        DiskUsageTracker $disk_tracker,
    ): MemoryDumpAction {
        $format = $settings->memory_output_format;
        $profiler_settings = $format !== null
            ? $this->buildMemoryProfilerSettings($settings)
            : null;

        /** @psalm-suppress InvalidArgument */
        return new MemoryDumpAction(
            $this->memory_dumper,
            $target_php_settings,
            $eg_address,
            $cg_address,
            $settings->action_output_dir,
            $disk_tracker,
            $settings->include_binary,
            $format,
            $format !== null ? $this->memory_dump_reader_factory : null,
            $profiler_settings,
        );
    }

    private function buildDaemonMemoryDumpAction(
        WatchSettings $settings,
        DiskUsageTracker $disk_tracker,
    ): Action\DaemonMemoryDumpAction {
        $format = $settings->memory_output_format;
        $profiler_settings = $format !== null
            ? $this->buildMemoryProfilerSettings($settings)
            : null;

        return new Action\DaemonMemoryDumpAction(
            $this->memory_dumper,
            $settings->action_output_dir,
            $disk_tracker,
            $settings->include_binary,
            $format,
            $format !== null ? $this->memory_dump_reader_factory : null,
            $profiler_settings,
        );
    }

    private function buildMemoryProfilerSettings(WatchSettings $settings): MemoryProfilerSettings
    {
        return new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: true,
            output_format: $settings->memory_output_format ?? 'json',
            db_host: $settings->db_host,
            db_port: $settings->db_port,
            db_name: $settings->db_name,
            db_user: $settings->db_user,
            db_password: $settings->db_password,
        );
    }
}
