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

use Reli\Lib\Elf\Process\ProcessModuleSymbolReader;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\Process\ProcessSymbolReaderException;
use Reli\Lib\Elf\Tls\TlsFinderException;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;

use function readlink;

final class PhpSymbolReaderCreator
{
    public function __construct(
        private ProcessModuleSymbolReaderCreator $process_module_symbol_reader_creator,
        private ProcessMemoryMapCreator $process_memory_map_creator,
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

        $libpthread_symbol_reader = $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
            $pid,
            $process_memory_map,
            $libpthread_finder_regex,
            $libpthread_binary_path
        );
        $root_link_map_address = null;
        if (!is_null($libpthread_symbol_reader)) {
            $executable_path = readlink("/proc/{$pid}/exe");
            $full_executable_path = "/proc/{$pid}/root{$executable_path}";
            $main_executable_reader = $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
                $pid,
                $process_memory_map,
                $executable_path,
                $full_executable_path,
                $libpthread_symbol_reader,
            );
            if (is_null($main_executable_reader)) {
                throw new ProcessSymbolReaderException('main executable not found');
            }
            $root_link_map_address = $main_executable_reader->getLinkMapAddress();
        }

        $php_symbol_reader = $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
            $pid,
            $process_memory_map,
            $php_finder_regex,
            $php_binar_path,
            $libpthread_symbol_reader,
            $root_link_map_address,
        );
        if (is_null($php_symbol_reader)) {
            throw new \RuntimeException('php module not found');
        }
        return $php_symbol_reader;
    }
}
