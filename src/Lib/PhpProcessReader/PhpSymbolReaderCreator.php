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
use Reli\Lib\PhpProcessReader\MainExecutable\MainExecutablePathResolver;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;

final class PhpSymbolReaderCreator
{
    public function __construct(
        private ProcessModuleSymbolReaderCreator $process_module_symbol_reader_creator,
        private ProcessMemoryMapCreatorInterface $process_memory_map_creator,
        private BinaryAnalysisCache $binary_analysis_cache,
        private MainExecutablePathResolver $main_executable_path_resolver,
    ) {
    }

    /**
     * Build a symbol reader for the target's main executable
     * (`/proc/{pid}/exe`).
     *
     * Used to handle ELF symbol preemption: when libphp is statically
     * linked into the target's main binary (e.g. FrankenPHP), the global
     * symbols that exist in both the executable and a separately mapped
     * `libphp.so` are bound to the executable's copy by the dynamic
     * linker, leaving the libphp.so copy at zero in BSS. Resolving such
     * symbols via the executable returns the address actually used at
     * runtime.
     *
     * The reader has no link-map / TLS plumbing (the caller has not
     * supplied a libpthread reader); it only supports static symbol
     * lookups. Returns null if `/proc/{pid}/exe` cannot be resolved or
     * the executable is not present in the process memory map.
     */
    public function createForExecutable(int $pid): ?ProcessModuleSymbolReader
    {
        $executable_path = $this->main_executable_path_resolver->resolve($pid);
        if ($executable_path === null) {
            return null;
        }
        $process_memory_map = $this->process_memory_map_creator->getProcessMemoryMap($pid);
        // Pass binary_path = null so `ProcessModuleSymbolReaderCreator`
        // routes the open through its injected `ProcessPathResolver`.
        // Live: ContainerAwarePathResolver → /proc/<pid>/root<path>;
        // coredump: MappedPathResolver → --dependency-root-mapped path.
        return $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
            $pid,
            $process_memory_map,
            '^' . preg_quote($executable_path) . '$',
            null,
        );
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
            // The executable path is used here only to resolve the root
            // link-map address for TLS resolution (ZTS targets,
            // libthread_db). NTS targets don't consult this — they
            // resolve `executor_globals` via static symbol lookup —
            // so a null path is recoverable downstream for NTS even
            // though it disables the TLS path. ZTS targets *do* need
            // it; the coredump path supplies the executable through
            // {@see StaticMainExecutablePathResolver} fed from NT_FILE
            // notes, which lets post-mortem ZTS analysis succeed even
            // when /proc/<pid>/exe is gone.
            $executable_path = $this->main_executable_path_resolver->resolve($pid);
            if ($executable_path !== null) {
                // Pass binary_path = null so the path goes through the
                // injected ProcessPathResolver: live container case
                // becomes /proc/<pid>/root<path>; coredump case becomes
                // the --dependency-root-mapped path. The same shape
                // works for both.
                $main_executable_reader = $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
                    $pid,
                    $process_memory_map,
                    '^' . preg_quote($executable_path) . '$',
                    null,
                    $libpthread_symbol_reader,
                );
                if (!is_null($main_executable_reader)) {
                    $root_link_map_address = $main_executable_reader->getLinkMapAddress();
                }
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

        // musl libc: ld-musl-*.so.1 is both libc and dynamic linker.
        // It has no _thread_db_* symbols, but we still return the reader
        // so the caller can resolve link_map and the TLS finder can fall
        // back to MuslTlsFinder.
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
