<?php

declare(strict_types=1);

/**
 * Live bench worker. Receives a target PID over the channel, performs the
 * full reli reader pipeline against it (version detect + globals find +
 * sampled call traces), and reports memory once trace + symbol caches are
 * populated.
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

    $samples = (int) (getenv('RELI_BENCH_SAMPLES') ?: 200);
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
        usleep(1000);
    }

    $rss = 0;
    if (preg_match('/^VmRSS:\s+(\d+)/m', (string) @file_get_contents('/proc/self/status'), $m)) {
        $rss = (int) $m[1];
    }
    $channel->send([
        'pid' => getmypid(),
        'target_pid' => $target_pid,
        'samples' => $samples,
        'captured' => $captured,
        'php_heap_kb' => memory_get_usage(true) >> 10,
        'rss_kb' => $rss,
    ]);

    $channel->receive();
};
