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

namespace PhpProfiler\Lib\PhpProcessReader;

use PhpProfiler\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Process\ProcessModuleSymbolReader;
use PhpProfiler\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use PhpProfiler\Lib\Elf\Tls\LibThreadDbTlsFinder;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\Elf\Tls\X64LinuxThreadPointerRetriever;
use PhpProfiler\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;

/**
 * Class PhpSymbolReaderCreator
 * @package PhpProfiler\ProcessReader
 */
final class PhpSymbolReaderCreator
{
    /**
     * PhpSymbolReaderCreator constructor.
     */
    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ProcessModuleSymbolReaderCreator $process_module_symbol_reader_creator,
        private ProcessMemoryMapCreator $process_memory_map_creator,
        private IntegerByteSequenceReader $integer_reader,
    ) {
    }

    /**
     * @param int $pid
     * @param string $php_finder_regex
     * @param string $libpthread_finder_regex
     * @param string|null $php_binar_path
     * @param string|null $libpthread_binary_path
     * @return ProcessModuleSymbolReader
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function create(
        int $pid,
        string $php_finder_regex,
        string $libpthread_finder_regex,
        ?string $php_binar_path,
        ?string $libpthread_binary_path
    ): ProcessModuleSymbolReader {
        $process_memory_map = $this->process_memory_map_creator->getProcessMemoryMap($pid);

        $tls_block_address = null;
        $libpthread_symbol_reader = $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
            $pid,
            $process_memory_map,
            $libpthread_finder_regex,
            $libpthread_binary_path
        );
        if (!is_null($libpthread_symbol_reader) and $libpthread_symbol_reader->isAllSymbolResolvable()) {
            $tls_finder = new LibThreadDbTlsFinder(
                $libpthread_symbol_reader,
                X64LinuxThreadPointerRetriever::createDefault(),
                $this->memory_reader,
                $this->integer_reader
            );
            $tls_block_address = $tls_finder->findTlsBlock($pid, 1);
        }

        $php_symbol_reader = $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
            $pid,
            $process_memory_map,
            $php_finder_regex,
            $php_binar_path,
            $tls_block_address
        );
        if (is_null($php_symbol_reader)) {
            throw new \RuntimeException('php module not found');
        }
        return $php_symbol_reader;
    }
}
