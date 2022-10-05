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

namespace Reli\Lib\PhpProcessReader;

use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReader;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\Process\ProcessSymbolReaderException;
use Reli\Lib\Elf\Tls\LibThreadDbTlsFinder;
use Reli\Lib\Elf\Tls\TlsFinderException;
use Reli\Lib\Elf\Tls\X64LinuxThreadPointerRetriever;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class PhpSymbolReaderCreator
{
    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ProcessModuleSymbolReaderCreator $process_module_symbol_reader_creator,
        private ProcessMemoryMapCreator $process_memory_map_creator,
        private IntegerByteSequenceReader $integer_reader,
    ) {
    }

    /**
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
        if (!is_null($libpthread_symbol_reader)) {
            try {
                $tls_finder = new LibThreadDbTlsFinder(
                    $libpthread_symbol_reader,
                    X64LinuxThreadPointerRetriever::createDefault(),
                    $this->memory_reader,
                    $this->integer_reader
                );
                $tls_block_address = $tls_finder->findTlsBlock($pid, 1);
            } catch (TlsFinderException $e) {
                $tls_block_address = null;
            }
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
