<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Noodlehaus\Config;
use PhpProfiler\Inspector\Daemon\Reader\Worker\PhpReaderTraceLoop;
use PhpProfiler\Inspector\Daemon\Reader\Worker\PhpReaderTraceLoopInterface;
use PhpProfiler\Inspector\Output\TraceFormatter\Templated\TemplatePathResolver;
use PhpProfiler\Inspector\Output\TraceFormatter\Templated\TemplatePathResolverInterface;
use PhpProfiler\Lib\Amphp\ContextCreator;
use PhpProfiler\Lib\Amphp\ContextCreatorInterface;
use PhpProfiler\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use PhpProfiler\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use PhpProfiler\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use PhpProfiler\Lib\Elf\SymbolResolver\SymbolResolverCreatorInterface;
use PhpProfiler\Lib\File\CatFileReader;
use PhpProfiler\Lib\File\FileReaderInterface;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReader;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;
use PhpProfiler\Lib\Process\Search\ProcessSearcher;
use PhpProfiler\Lib\Process\Search\ProcessSearcherInterface;
use Psr\Log\LoggerInterface;
use function DI\autowire;

return [
    MemoryReaderInterface::class => autowire(MemoryReader::class),
    ZendTypeReader::class => function () {
        return new ZendTypeReader(ZendTypeReader::V80);
    },
    SymbolResolverCreatorInterface::class => autowire(Elf64SymbolResolverCreator::class),
    FileReaderInterface::class => autowire(CatFileReader::class),
    IntegerByteSequenceReader::class => autowire(LittleEndianReader::class),
    ContextCreator::class => autowire()
        ->constructorParameter('di_config_file', __DIR__ . '/di.php'),
    ContextCreatorInterface::class => autowire(ContextCreator::class)
        ->constructorParameter('di_config_file', __DIR__ . '/di.php'),
    PhpReaderTraceLoopInterface::class => autowire(PhpReaderTraceLoop::class),
    ProcessSearcherInterface::class => autowire(ProcessSearcher::class),
    Config::class => fn () => Config::load(__DIR__ . '/config.php'),
    TemplatePathResolverInterface::class => autowire(TemplatePathResolver::class),
    LoggerInterface::class => function (Config $config) {
        $logger = new Logger('default');
        $logger->pushHandler(
            new StreamHandler(
                $config->get('log.path.default'),
                Logger::toMonologLevel($config->get('log.level'))
            )
        );
        return $logger;
    }
];
