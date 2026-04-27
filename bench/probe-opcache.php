<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;

fwrite(STDOUT, "PHP_SAPI=" . PHP_SAPI . " PHP_ZTS=" . var_export(PHP_ZTS, true) . "\n");
fwrite(STDOUT, "opcache loaded=" . var_export(extension_loaded('Zend OPcache'), true) . "\n");
fwrite(STDOUT, "opcache.enable=" . ini_get('opcache.enable') . "\n");
fwrite(STDOUT, "opcache.enable_cli=" . ini_get('opcache.enable_cli') . "\n");
fwrite(STDOUT, "opcache.memory_consumption=" . ini_get('opcache.memory_consumption') . "\n");
fwrite(STDOUT, "opcache.preload=" . ini_get('opcache.preload') . "\n");

$status = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
fwrite(STDOUT, "opcache_get_status() = " . var_export($status, true) . "\n");

// Touch reli's class graph and re-check
$container = (new ContainerBuilder())
    ->addDefinitions(__DIR__ . '/../config/di.php')
    ->build();
$container->make(Psr\Log\LoggerInterface::class);
$container->make(Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class);
$container->make(Reli\Inspector\Watch\HeapStatsReader::class);

$status2 = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
fwrite(STDOUT, "after-DI opcache_get_status() = " . var_export($status2, true) . "\n");
