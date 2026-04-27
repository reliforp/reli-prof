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
use Reli\Inspector\Watch\HeapStatsReader;
use Reli\Inspector\Watch\RssReader;
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
    /**
     * @param array<string, string> $session_tags tags applied to every snapshot
     */
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
        private MemoryDumper $memory_dumper,
        private CallTraceReader $call_trace_reader,
        private HeapStatsReader $heap_stats_reader,
        private RssReader $rss_reader,
        private ProcessStopper $process_stopper,
        private DiskUsageTracker $disk_tracker,
        private string $output_dir,
        private bool $include_binary,
        private array $session_tags = [],
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

        // Collect lightweight heap stats and RSS before stopping
        $heap_stats = $this->heap_stats_reader->read(
            $process_specifier,
            $target_php_settings,
            $eg_address,
        );
        $rss_bytes = $this->rss_reader->read($pid);

        // Pre-flight: refuse the dump if we already know we cannot fit the
        // target's heap into our own memory_limit. The current dumper buffers
        // every region as a PHP string before flushing, so peak sidecar RSS
        // tracks the target's heap size + ~80 MB baseline. Without this
        // check, an oversized request would kill the sidecar with a PHP
        // Fatal partway through the read loop, taking down the daemon for
        // every other client until a supervisor restarts it. Returning a
        // structured error keeps the daemon alive and gives the operator a
        // line to grep for.
        $preflight = self::preflightMemoryCheck($heap_stats->size);
        if ($preflight !== null) {
            return $preflight;
        }

        $stopped = $this->process_stopper->stop($pid);
        // Closure that detaches ptrace and flips $stopped so the finally
        // block doesn't try to detach again. Captured by reference so the
        // dumper's `on_read_complete` hook and the safety-net finally below
        // see the same flag.
        $resume = function () use ($pid, &$stopped): void {
            if ($stopped) {
                $this->process_stopper->resume($pid);
                $stopped = false;
            }
        };
        try {
            $trace_strings = $this->readTrace(
                $pid,
                $php_version,
                $eg_address,
                $sg_address,
            );

            $label_slug = $request->label !== null
                ? '-' . (string)preg_replace('/[^a-zA-Z0-9_-]/', '_', $request->label)
                : '';
            $output_path = sprintf(
                '%s/sidecar-%d-%s%s.dump',
                rtrim($this->output_dir, '/'),
                $pid,
                date('Ymd-His'),
                $label_slug,
            );

            // Fast-resume: ask the dumper to call us back the moment the
            // remote read phase finishes. We detach ptrace there, so the
            // target can run again while we're still streaming the buffered
            // regions to disk. The finally below remains as a safety net
            // for the case where the read phase throws before the hook
            // fires.
            $result = $this->memory_dumper->dump(
                $process_specifier,
                $target_php_settings,
                $eg_address,
                $cg_address,
                $output_path,
                $this->include_binary,
                on_read_complete: $resume,
            );
        } finally {
            $resume();
        }

        $this->disk_tracker->recordFile($output_path);

        $memory_stats = [
            'memory_usage' => $heap_stats->size,
            'memory_real_usage' => $heap_stats->real_size,
            'memory_peak_usage' => $heap_stats->peak,
            'memory_limit' => $heap_stats->limit,
            'rss' => $rss_bytes,
        ];

        $this->writeMetadata(
            $request,
            $output_path,
            $trace_strings,
            $php_version,
            $memory_stats,
        );

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
            memory_stats: $memory_stats,
        );
    }

    /**
     * @param value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
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
            return array_values(array_map(
                fn (CallFrame $frame): string => sprintf(
                    '%s %s:%d',
                    $frame->getFullyQualifiedFunctionName(),
                    $frame->file_name,
                    $frame->getLineno(),
                ),
                $call_trace->call_frames,
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<string> $trace
     * @param array<string, int|null> $memory_stats
     */
    private function writeMetadata(
        SidecarRequest $request,
        string $dump_path,
        array $trace,
        string $php_version,
        array $memory_stats,
    ): void {
        $meta_path = $dump_path . '.meta.json';
        $meta = [
            'pid' => $request->pid,
            'timestamp' => date('c'),
            'trigger' => 'sidecar_request',
            'php_version' => $php_version,
            'memory_stats' => $memory_stats,
            'call_trace' => $trace,
        ];
        if ($request->label !== null) {
            $meta['label'] = $request->label;
        }
        // Merge session tags with per-request metadata (request wins on conflict)
        $merged_metadata = array_merge($this->session_tags, $request->metadata);
        if (count($merged_metadata) > 0) {
            $meta['metadata'] = $merged_metadata;
        }
        if ($request->error_file !== null) {
            $meta['memory_limit_error_file'] = $request->error_file;
        }
        if ($request->error_line !== null) {
            $meta['memory_limit_error_line'] = $request->error_line;
        }
        file_put_contents(
            $meta_path,
            (string)json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
    }

    /**
     * Returns an error response if the target's heap clearly cannot fit in
     * what's left of the sidecar's own `memory_limit`, or null when the
     * dump should proceed. The 1.15 multiplier and 16 MiB headroom cover
     * per-region overhead from the PHP-string copies that
     * {@see \Reli\Inspector\MemoryDump\MemoryDumper} accumulates before
     * writing; if the dumper is later switched to a streaming mode that
     * does not buffer everything in PHP strings, this check should be
     * relaxed accordingly (or skipped for that mode).
     */
    private static function preflightMemoryCheck(int $target_heap_bytes): ?SidecarResponse
    {
        $limit_raw = ini_get('memory_limit');
        if (!is_string($limit_raw) || $limit_raw === '') {
            return null;
        }
        $limit = ini_parse_quantity($limit_raw);
        if ($limit <= 0) {
            // -1 / 0 → unlimited; nothing to check.
            return null;
        }

        $needed = (int)((float)$target_heap_bytes * 1.15) + (16 * 1024 * 1024);
        $available = $limit - memory_get_usage(true);
        if ($needed <= $available) {
            return null;
        }

        $msg = sprintf(
            'sidecar memory_limit too small for this target: '
            . 'need ~%d MiB, %d MiB available (limit=%d MiB, used=%d MiB). '
            . 'Raise --memory-limit on the sidecar process to roughly '
            . '(max target heap + 100 MiB).',
            (int)ceil($needed / (1024 * 1024)),
            (int)floor($available / (1024 * 1024)),
            (int)floor($limit / (1024 * 1024)),
            (int)ceil(memory_get_usage(true) / (1024 * 1024)),
        );
        Log::info('sidecar pre-flight rejected dump', [
            'target_heap_bytes' => $target_heap_bytes,
            'needed_bytes' => $needed,
            'available_bytes' => $available,
            'memory_limit' => $limit,
        ]);
        return SidecarResponse::error($msg);
    }
}
