<?php

declare(strict_types=1);

/**
 * Bench worker. Mirrors src/Lib/Amphp/worker-entry.php's startup cost
 * (autoload + DI container build + a few representative service makes),
 * then idles waiting for shutdown so memory can be sampled.
 */

use Amp\Sync\Channel;
use DI\ContainerBuilder;

return function (Channel $channel): void {
    require __DIR__ . '/../vendor/autoload.php';

    $container = (new ContainerBuilder())
        ->addDefinitions(__DIR__ . '/../config/di.php')
        ->build();
    $container->make(Psr\Log\LoggerInterface::class);
    $container->make(Reli\Lib\Log\StateCollector\StateCollector::class);
    $container->make(Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class);
    $container->make(Reli\Inspector\Watch\HeapStatsReader::class);
    $container->make(Reli\Inspector\Watch\VariableReader::class);
    $container->make(Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator::class);
    $container->make(Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator::class);

    $rss = (int) trim((string) @file_get_contents('/proc/self/status'));
    if (preg_match('/^VmRSS:\s+(\d+)/m', (string) @file_get_contents('/proc/self/status'), $m)) {
        $rss = (int) $m[1];
    } else {
        $rss = 0;
    }

    $channel->send([
        'pid' => getmypid(),
        'php_heap_kb' => memory_get_usage(true) >> 10,
        'rss_kb' => $rss,
    ]);

    $channel->receive();
};
