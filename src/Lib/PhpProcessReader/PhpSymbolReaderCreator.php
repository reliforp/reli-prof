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

use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprint;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReader;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\Process\ProcessSymbolReaderException;
use Reli\Lib\Elf\Tls\TlsFinderException;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;

use function readlink;

final class PhpSymbolReaderCreator
{
    public function __construct(
        private ProcessModuleSymbolReaderCreator $process_module_symbol_reader_creator,
        private ProcessMemoryMapCreatorInterface $process_memory_map_creator,
        private BinaryAnalysisCache $binary_analysis_cache,
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

        $libpthread_symbol_reader = $this->resolveThreadDbReader(
            $pid,
            $process_memory_map,
            $libpthread_finder_regex,
            $libpthread_binary_path,
        );

        $root_link_map_address = null;
        if (!is_null($libpthread_symbol_reader)) {
            $executable_path = readlink("/proc/{$pid}/exe");
            if ($executable_path === false) {
                throw new ProcessSymbolReaderException('failed to readlink /proc/' . $pid . '/exe');
            }
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
            try {
                $root_link_map_address = $main_executable_reader->getLinkMapAddress();
            } catch (\Reli\Lib\Process\MemoryReader\MemoryReaderException) {
                // Link map not accessible (e.g., coredump missing required pages)
            }
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

    private function resolveThreadDbReader(
        int $pid,
        ProcessMemoryMap $process_memory_map,
        string $libpthread_finder_regex,
        ?string $libpthread_binary_path,
    ): ?ProcessModuleSymbolReader {
        $libpthread_fingerprint = $this->getModuleFingerprint(
            $process_memory_map,
            $libpthread_finder_regex,
        );

        if ($libpthread_fingerprint !== null) {
            $cached = $this->binary_analysis_cache->get($libpthread_fingerprint, 'thread_db_source');
            if ($cached !== null && isset($cached['source']) && is_string($cached['source'])) {
                if ($cached['source'] === 'libc') {
                    return $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
                        $pid,
                        $process_memory_map,
                        '.*/libc\.so.*',
                        null,
                    );
                }
                // source === 'libpthread': use libpthread directly (below)
            }
        }

        $libpthread_symbol_reader = $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
            $pid,
            $process_memory_map,
            $libpthread_finder_regex,
            $libpthread_binary_path,
        );

        if (
            !is_null($libpthread_symbol_reader)
            && !is_null($libpthread_symbol_reader->resolveAddress('_thread_db_pthread_dtvp'))
        ) {
            if ($libpthread_fingerprint !== null) {
                $this->binary_analysis_cache->set(
                    $libpthread_fingerprint,
                    'thread_db_source',
                    ['source' => 'libpthread'],
                );
            }
            return $libpthread_symbol_reader;
        }

        // On glibc 2.34+, libpthread.so is a stub that lacks _thread_db_*
        // symbols needed for TLS resolution; fall back to libc.so
        $libc_reader = $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
            $pid,
            $process_memory_map,
            '.*/libc\.so.*',
            null,
        );

        if (!is_null($libc_reader)) {
            if ($libpthread_fingerprint !== null) {
                $this->binary_analysis_cache->set(
                    $libpthread_fingerprint,
                    'thread_db_source',
                    ['source' => 'libc'],
                );
            }
            return $libc_reader;
        }

        return $libpthread_symbol_reader;
    }

    private function getModuleFingerprint(
        ProcessMemoryMap $process_memory_map,
        string $regex,
    ): ?BinaryFingerprint {
        $areas = $process_memory_map->findByNameRegex($regex);
        if ($areas === []) {
            return null;
        }
        $module_map = new ProcessModuleMemoryMap($areas);
        return BinaryFingerprint::fromProcessModuleMemoryMap($module_map);
    }
}
