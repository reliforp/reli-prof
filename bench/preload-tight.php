<?php

declare(strict_types=1);

/**
 * Tight preload: only the classes the bench-worker actually touches in
 * stage=services. Designed to isolate "preload removes per-thread copy
 * cost" from "preload over-eagerly loads dead weight into SHM".
 */

require __DIR__ . '/../vendor/autoload.php';

$classes = [
    Psr\Log\LoggerInterface::class,
    DI\ContainerBuilder::class,
    DI\Container::class,
    Reli\Lib\Log\StateCollector\StateCollector::class,
    Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class,
    Reli\Inspector\Watch\HeapStatsReader::class,
    Reli\Inspector\Watch\VariableReader::class,
    Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator::class,
    Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator::class,
];

$loaded = 0;
$skipped = 0;
foreach ($classes as $class) {
    try {
        if (class_exists($class) || interface_exists($class)) {
            $loaded++;
        } else {
            $skipped++;
        }
    } catch (\Throwable) {
        $skipped++;
    }
}

fwrite(STDERR, "[preload-tight] loaded={$loaded} skipped={$skipped} ");
$status = opcache_get_status(false);
fwrite(STDERR, "cached_scripts=" . ($status['opcache_statistics']['num_cached_scripts'] ?? '?') . " ");
fwrite(STDERR, "shm_used_kb=" . (int) (($status['memory_usage']['used_memory'] ?? 0) / 1024) . "\n");
