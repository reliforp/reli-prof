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

namespace Reli\Inspector\Sidecar;

use Reli\Inspector\MemoryDump\MemoryDumper;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Sidecar\Protocol\SidecarRequest;
use Reli\Inspector\Sidecar\Protocol\SidecarResponse;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;

final class SidecarDumpHandler
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
        private MemoryDumper $memory_dumper,
        private CallTraceReader $call_trace_reader,
        private ProcessStopper $process_stopper,
        private DiskUsageTracker $disk_tracker,
        private string $output_dir,
        private bool $include_binary,
    ) {
    }

    public function handle(SidecarRequest $request): SidecarResponse
    {
        $pid = $request->pid;

        if (!file_exists("/proc/{$pid}/status")) {
            return SidecarResponse::error("process {$pid} not found");
        }

        if (!$this->disk_tracker->canWrite()) {
            return SidecarResponse::error('disk usage limit reached');
        }

        try {
            return $this->doDump($request);
        } catch (\Throwable $e) {
            Log::debug('sidecar dump failed', [
                'pid' => $pid,
                'error' => $e->getMessage(),
            ]);
            return SidecarResponse::error($e->getMessage());
        }
    }

    private function doDump(SidecarRequest $request): SidecarResponse
    {
        $pid = $request->pid;
        $process_specifier = new ProcessSpecifier($pid);
        $target_php_settings = new TargetPhpSettings();

        $target_php_settings = $this->php_version_detector->decidePhpVersion(
            $process_specifier,
            $target_php_settings,
        );
        $php_version = $target_php_settings->php_version;

        $eg_address = $this->php_globals_finder->findExecutorGlobals(
            $process_specifier,
            $target_php_settings,
        );
        $cg_address = $this->php_globals_finder->findCompilerGlobals(
            $process_specifier,
            $target_php_settings,
        );
        $sg_address = $this->php_globals_finder->findSAPIGlobals(
            $process_specifier,
            $target_php_settings,
        );

        $stopped = $this->process_stopper->stop($pid);
        try {
            $trace_strings = $this->readTrace(
                $pid,
                $php_version,
                $eg_address,
                $sg_address,
            );

            $output_path = sprintf(
                '%s/sidecar-%d-%s.dump',
                rtrim($this->output_dir, '/'),
                $pid,
                date('Ymd-His'),
            );

            $result = $this->memory_dumper->dump(
                $process_specifier,
                $target_php_settings,
                $eg_address,
                $cg_address,
                $output_path,
                $this->include_binary,
            );

            $this->disk_tracker->recordFile($output_path);

            $this->writeMetadata($request, $output_path, $trace_strings, $php_version);

            Log::info('sidecar dump saved', [
                'path' => $result->output_path,
                'regions' => $result->region_count,
                'bytes' => $result->total_bytes,
                'pid' => $pid,
            ]);

            $error_context = null;
            if ($request->error_file !== null && $request->error_line !== null) {
                $error_context = [
                    'file' => $request->error_file,
                    'line' => $request->error_line,
                ];
            }

            return SidecarResponse::ok(
                path: $result->output_path,
                bytes: $result->total_bytes,
                trace: $trace_strings,
                error_context: $error_context,
            );
        } finally {
            if ($stopped) {
                $this->process_stopper->resume($pid);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function readTrace(
        int $pid,
        string $php_version,
        int $eg_address,
        int $sg_address,
    ): array {
        try {
            $call_trace = $this->call_trace_reader->readCallTrace(
                $pid,
                $php_version,
                $eg_address,
                $sg_address,
                PHP_INT_MAX,
                new TraceCache(),
            );
            if ($call_trace === null) {
                return [];
            }
            return array_map(
                fn (CallFrame $frame): string => sprintf(
                    '%s %s:%d',
                    $frame->getFullyQualifiedFunctionName(),
                    $frame->file_name,
                    $frame->getLineno(),
                ),
                $call_trace->call_frames,
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<string> $trace
     */
    private function writeMetadata(
        SidecarRequest $request,
        string $dump_path,
        array $trace,
        string $php_version,
    ): void {
        $meta_path = $dump_path . '.meta.json';
        $meta = [
            'pid' => $request->pid,
            'timestamp' => date('c'),
            'trigger' => 'sidecar_request',
            'php_version' => $php_version,
            'call_trace' => $trace,
        ];
        if ($request->error_file !== null) {
            $meta['memory_limit_error_file'] = $request->error_file;
        }
        if ($request->error_line !== null) {
            $meta['memory_limit_error_line'] = $request->error_line;
        }
        file_put_contents(
            $meta_path,
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
    }
}
