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

use PhpProfiler\Lib\Binary\IntegerByteSequence\IntegerByteSequenceReader;
use PhpProfiler\Lib\Binary\IntegerByteSequence\LittleEndianReader;
use PhpProfiler\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use PhpProfiler\Lib\Elf\SymbolResolver\SymbolResolverCreatorInterface;
use PhpProfiler\Lib\File\CatFileReader;
use PhpProfiler\Lib\File\FileReaderInterface;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReader;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;

use function DI\autowire;

return [
    MemoryReaderInterface::class => autowire(MemoryReader::class),
    ZendTypeReader::class => function () {
        return new ZendTypeReader(ZendTypeReader::V74);
    },
    SymbolResolverCreatorInterface::class => autowire(Elf64SymbolResolverCreator::class),
    FileReaderInterface::class => autowire(CatFileReader::class),
    IntegerByteSequenceReader::class => autowire(LittleEndianReader::class),
];
