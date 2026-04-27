<?php

declare(strict_types=1);

/**
 * CPU-throughput variant of bench-worker-live. Warms the descriptor /
 * trace caches with a few cold-path samples, then runs N timed samples
 * back-to-back and reports wall + CPU time so we can compare per-sample
 * cost across NTS/ZTS and JIT on/off.
 */

use Amp\Sync\Channel;
use DI\ContainerBuilder;
use Reli\Inspector\Daemon\Searcher\Worker\ProcessDescriptorCache;
use Reli\Inspector\Daemon\Searcher\Worker\ProcessDescriptorRetriever;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;

return function (Channel $channel): void {
    ini_set('memory_limit', '512M');
    require __DIR__ . '/../vendor/autoload.php';

    $target_pid = (int) $channel->receive();

    $container = (new ContainerBuilder())
        ->addDefinitions(__DIR__ . '/../config/di.php')
        ->build();
    \Reli\Lib\Log\Log::initializeLogger(
        $container->make(\Psr\Log\LoggerInterface::class),
        $container->make(\Reli\Lib\Log\StateCollector\StateCollector::class),
    );

    $tps = new TargetPhpSettings(
        php_regex: '.*/(php|php-fpm)\d?(\.\d)?$|.*/libphp.*\.so$',
        libpthread_regex: '.*/libpthread.*\.so|.*/libc\.so.*|.*/ld-musl-.*\.so',
    );

    $retriever = $container->make(ProcessDescriptorRetriever::class);
    $descriptor_cache = new ProcessDescriptorCache(60);
    $descriptor = $retriever->getProcessDescriptor($target_pid, $tps, $descriptor_cache);

    if ($descriptor->pid === 0) {
        $channel->send(['error' => 'descriptor invalid', 'target_pid' => $target_pid]);
        $channel->receive();
        return;
    }

    /** @var CallTraceReader $reader */
    $reader = $container->make(CallTraceReader::class);
    $trace_cache = new TraceCache();

    $warmup = (int) (getenv('RELI_BENCH_WARMUP') ?: 50);
    $samples = (int) (getenv('RELI_BENCH_SAMPLES') ?: 5000);

    // Warmup -- triggers ELF parse, symbol resolve, trace cache bootstrap.
    for ($i = 0; $i < $warmup; $i++) {
        $reader->readCallTrace(
            $descriptor->pid,
            $descriptor->php_version,
            $descriptor->eg_address,
            $descriptor->sg_address,
            depth: 256,
            trace_cache: $trace_cache,
        );
    }

    // Per-thread CPU time. getrusage(0)=RUSAGE_SELF returns the *process*
    // total under Linux (sum across all threads), so under thread mode it
    // would 8x-inflate when N=8 workers run in one process. Read this
    // thread's own utime+stime from /proc/self/task/$tid/stat (clock ticks).
    $hz = 100; // sysconf(_SC_CLK_TCK), 100 on Linux
    $readThreadCpu = static function () use ($hz): int {
        $tid = (int) trim((string) @file_get_contents('/proc/thread-self/stat'));
        // procfs format: pid (comm) state ppid pgrp ... utime(14) stime(15)
        $stat = (string) @file_get_contents('/proc/thread-self/stat');
        $rparen = strrpos($stat, ')');
        $rest = substr($stat, $rparen + 2);
        $fields = preg_split('/\s+/', $rest);
        // After "(comm)" the rest starts at field 3 (state). utime is field 14, idx 14-3=11 in $fields.
        $utime = (int) ($fields[11] ?? 0);
        $stime = (int) ($fields[12] ?? 0);
        return (int) (($utime + $stime) * (1_000_000 / $hz));
    };

    $cpu0 = $readThreadCpu();
    $wall0 = hrtime(true);
    $captured = 0;
    for ($i = 0; $i < $samples; $i++) {
        $trace = $reader->readCallTrace(
            $descriptor->pid,
            $descriptor->php_version,
            $descriptor->eg_address,
            $descriptor->sg_address,
            depth: 256,
            trace_cache: $trace_cache,
        );
        if ($trace !== null) {
            $captured++;
        }
    }
    $wall1 = hrtime(true);
    $cpu1 = $readThreadCpu();

    $cpu_us = $cpu1 - $cpu0;
    $wall_us = (int) (($wall1 - $wall0) / 1000);

    $channel->send([
        'pid' => getmypid(),
        'target_pid' => $target_pid,
        'samples' => $samples,
        'captured' => $captured,
        'wall_us' => $wall_us,
        'cpu_us' => $cpu_us,
        'us_per_sample_wall' => $wall_us / max(1, $samples),
        'us_per_sample_cpu' => $cpu_us / max(1, $samples),
        'jit' => function_exists('opcache_get_status')
            ? (opcache_get_status(false)['jit']['on'] ?? null)
            : null,
        'php_zts' => PHP_ZTS,
    ]);

    $channel->receive();
};
