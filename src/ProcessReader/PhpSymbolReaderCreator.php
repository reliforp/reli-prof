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

use PhpProfiler\Lib\Elf\ElfParserException;
use PhpProfiler\Lib\Elf\SymbolResolverCreator;
use PhpProfiler\Lib\Elf\Tls\LibThreadDbTlsFinder;
use PhpProfiler\Lib\Process\MemoryReaderException;
use PhpProfiler\Lib\Process\MemoryReaderInterface;
use PhpProfiler\Lib\Process\RegisterReader;
use PhpProfiler\Lib\Process\RegisterReaderException;

/**
 * Class PhpSymbolReaderCreator
 * @package PhpProfiler\ProcessReader
 */
final class PhpSymbolReaderCreator
{
    private MemoryReaderInterface $memory_reader;

    /**
     * PhpSymbolReaderCreator constructor.
     * @param MemoryReaderInterface $memory_reader
     */
    public function __construct(MemoryReaderInterface $memory_reader)
    {
        $this->memory_reader = $memory_reader;
    }

    /**
     * @param int $pid
     * @return ProcessModuleSymbolReader
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws ElfParserException
     * @throws RegisterReaderException
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
            $tls_finder = new LibThreadDbTlsFinder(
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
