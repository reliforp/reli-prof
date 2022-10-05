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

use DI\Container;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Noodlehaus\Config;
use Reli\Inspector\Daemon\Reader\Worker\PhpReaderTraceLoop;
use Reli\Inspector\Daemon\Reader\Worker\PhpReaderTraceLoopInterface;
use Reli\Inspector\Output\TraceFormatter\Templated\TemplatePathResolver;
use Reli\Inspector\Output\TraceFormatter\Templated\TemplatePathResolverInterface;
use Reli\Lib\Amphp\ContextCreator;
use Reli\Lib\Amphp\ContextCreatorInterface;
use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\Elf\SymbolResolver\SymbolResolverCreatorInterface;
use Reli\Lib\File\FileReaderInterface;
use Reli\Lib\File\NativeFileReader;
use Reli\Lib\Libc\Sys\Ptrace\Ptrace;
use Reli\Lib\Libc\Sys\Ptrace\PtraceX64;
use Reli\Lib\Log\StateCollector\CallerStateCollector;
use Reli\Lib\Log\StateCollector\GroupedStateCollector;
use Reli\Lib\Log\StateCollector\ProcessStateCollector;
use Reli\Lib\Log\StateCollector\StateCollector;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Search\ProcessSearcher;
use Reli\Lib\Process\Search\ProcessSearcherInterface;
use Psr\Log\LoggerInterface;
use function DI\autowire;

return [
    MemoryReaderInterface::class => autowire(MemoryReader::class),
    ZendTypeReader::class => function () {
        return new ZendTypeReader(ZendTypeReader::V80);
    },
    SymbolResolverCreatorInterface::class => autowire(Elf64SymbolResolverCreator::class),
    FileReaderInterface::class => autowire(NativeFileReader::class),
    IntegerByteSequenceReader::class => autowire(LittleEndianReader::class),
    ContextCreator::class => autowire()
        ->constructorParameter('di_config_file', __DIR__ . '/di.php'),
    ContextCreatorInterface::class => autowire(ContextCreator::class)
        ->constructorParameter('di_config_file', __DIR__ . '/di.php'),
    PhpReaderTraceLoopInterface::class => autowire(PhpReaderTraceLoop::class),
    ProcessSearcherInterface::class => autowire(ProcessSearcher::class),
    Config::class => fn () => Config::load(__DIR__ . '/config.php'),
    TemplatePathResolverInterface::class => autowire(TemplatePathResolver::class),
    StateCollector::class => function (Container $container) {
        $collectors = [];
        $collectors[] = $container->make(ProcessStateCollector::class);
        $collectors[] = $container->make(CallerStateCollector::class);
        return new GroupedStateCollector(...$collectors);
    },
    LoggerInterface::class => function (Config $config) {
        $logger = new Logger('default');
        $handler = new StreamHandler(
            $config->get('log.path.default'),
            Logger::toMonologLevel($config->get('log.level'))
        );
        $handler->setFormatter(new JsonFormatter());
        $logger->pushHandler($handler);
        return $logger;
    },
    Ptrace::class => autowire(PtraceX64::class),
];
