<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\ProcessReader;

use PhpProfiler\Lib\Elf\SymbolResolverCreator;
use PhpProfiler\Lib\Elf\Tls\TlsFinder;
use PhpProfiler\Lib\Process\MemoryReader;
use PhpProfiler\Lib\Process\MemoryReaderException;
use PhpProfiler\Lib\Process\RegisterReader;

/**
 * Class PhpSymbolReaderCreator
 * @package PhpProfiler\ProcessReader
 */
final class PhpSymbolReaderCreator
{
    /**
     * @var MemoryReader
     */
    private MemoryReader $memory_reader;

    /**
     * PhpSymbolReaderCreator constructor.
     * @param MemoryReader $memory_reader
     */
    public function __construct(MemoryReader $memory_reader)
    {
        $this->memory_reader = $memory_reader;
    }

    /**
     * @param int $pid
     * @return ProcessModuleSymbolReader
     * @throws MemoryReaderException
     */
    public function create(int $pid): ProcessModuleSymbolReader
    {
        $memory_reader = $this->memory_reader;

        $symbol_reader_creator = new ProcessModuleSymbolReaderCreator(
            $pid,
            ProcessMemoryMapCreator::create()->getProcessMemoryMap($pid),
            new SymbolResolverCreator(),
            $memory_reader
        );

        $tls_block_address = null;
        $libpthread_symbol_reader = $symbol_reader_creator->createModuleReaderByNameRegex('/.*\/libpthread.*\.so$/');
        if (!is_null($libpthread_symbol_reader)) {
            $tls_finder = new TlsFinder(
                $libpthread_symbol_reader,
                new RegisterReader(),
                $memory_reader
            );
            $tls_block_address = $tls_finder->findTlsBlock($pid, 1);
        }

        $php_symbol_reader = $symbol_reader_creator->createModuleReaderByNameRegex('/.*\/php$/', $tls_block_address);
        if (is_null($php_symbol_reader)) {
            throw new \RuntimeException('php module not found');
        }
        return $php_symbol_reader;
    }
}
