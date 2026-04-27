<?php

declare(strict_types=1);

/**
 * Stage-controlled bench worker. RELI_BENCH_STAGE picks how much work the
 * worker does so we can see which slice of per-thread memory comes from
 * what:
 *
 *   minimal  : autoload only, no class touches beyond composer's own.
 *   classes  : autoload + class_exists() on reli's heavy reader classes
 *              (forces opcache lookup / copy without instantiating).
 *   container: autoload + ContainerBuilder build, no make().
 *   services : full bootstrap (current bench-worker behaviour).
 */

use Amp\Sync\Channel;
use DI\ContainerBuilder;

return function (Channel $channel): void {
    require __DIR__ . '/../vendor/autoload.php';

    $stage = getenv('RELI_BENCH_STAGE') ?: 'services';

    if (in_array($stage, ['classes', 'container', 'services'], true)) {
        class_exists(Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class);
        class_exists(Reli\Inspector\Watch\HeapStatsReader::class);
        class_exists(Reli\Inspector\Watch\VariableReader::class);
        class_exists(Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator::class);
        class_exists(Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator::class);
        class_exists(Reli\Lib\Log\StateCollector\StateCollector::class);
    }

    if (in_array($stage, ['container', 'services'], true)) {
        $container = (new ContainerBuilder())
            ->addDefinitions(__DIR__ . '/../config/di.php')
            ->build();
    }

    if ($stage === 'services') {
        $container->make(Psr\Log\LoggerInterface::class);
        $container->make(Reli\Lib\Log\StateCollector\StateCollector::class);
        $container->make(Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class);
        $container->make(Reli\Inspector\Watch\HeapStatsReader::class);
        $container->make(Reli\Inspector\Watch\VariableReader::class);
        $container->make(Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator::class);
        $container->make(Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator::class);
    }

    $rss = 0;
    if (preg_match('/^VmRSS:\s+(\d+)/m', (string) @file_get_contents('/proc/self/status'), $m)) {
        $rss = (int) $m[1];
    }

    $channel->send([
        'pid' => getmypid(),
        'php_heap_kb' => memory_get_usage(true) >> 10,
        'rss_kb' => $rss,
        'stage' => $stage,
    ]);

    $channel->receive();
};
