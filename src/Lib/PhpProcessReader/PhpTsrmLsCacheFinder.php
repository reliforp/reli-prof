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

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\CDataByteReader;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\LinkMapLoader;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\Process\ProcessSymbolReaderException;
use Reli\Lib\Elf\Process\ProcessSymbolReaderInterface;
use Reli\Lib\Elf\Tls\LibThreadDbTlsFinder;
use Reli\Lib\Elf\Tls\X64LinuxThreadPointerRetriever;
use Reli\Lib\File\NativeFileReader;
use Reli\Lib\File\PathResolver\ContainerAwarePathResolver;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;

class PhpTsrmLsCacheFinder
{
    public function __construct(
        private Elf64Parser $elf64_parser,
        private ProcessMemoryMapCreator $process_memory_map_creator,
        private ContainerAwarePathResolver $process_path_resolver,
        private MemoryReaderInterface $memory_reader,
        private LittleEndianReader $integer_reader,
        private ProcessModuleSymbolReaderCreator $process_module_symbol_reader_creator,
        private LinkMapLoader $link_map_loader,
        private NativeFileReader $file_reader,
        private PhpSymbolReaderCreator $php_symbol_reader_creator,
        private ZendTypeReaderCreator $zend_type_reader_creator,
    ) {
    }

    /** @return array{int, int} */
    public function resolveTlsBlock(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): array {
        $process_memory_map = $this->process_memory_map_creator->getProcessMemoryMap($process_specifier->pid);

        $php_memory_areas = $process_memory_map->findByNameRegex($target_php_settings->php_regex);
        $php_module_memory_map = new ProcessModuleMemoryMap($php_memory_areas);
        $php_module_name = $php_module_memory_map->getModuleName();

        $libpthread_memory_areas = $process_memory_map->findByNameRegex($target_php_settings->libpthread_regex);
        $libpthread_module_memory_map = new ProcessModuleMemoryMap($libpthread_memory_areas);
        $libpthread_module_name = $libpthread_module_memory_map->getModuleName();

        $libpthread_path = $target_php_settings->libpthread_path ?? $this->process_path_resolver->resolve(
            $process_specifier->pid,
            $libpthread_module_name,
        );

        $libpthread_symbol_reader = $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
            $process_specifier->pid,
            $process_memory_map,
            $libpthread_module_name,
            $libpthread_path,
            null,
        );

        $executable_path = readlink("/proc/{$process_specifier->pid}/exe");
        $full_executable_path = "/proc/{$process_specifier->pid}/root{$executable_path}";
        $main_executable_reader = $this->process_module_symbol_reader_creator->createModuleReaderByNameRegex(
            $process_specifier->pid,
            $process_memory_map,
            $executable_path,
            $full_executable_path,
            $libpthread_symbol_reader,
        );
        if (is_null($main_executable_reader)) {
            throw new ProcessSymbolReaderException('main executable not found');
        }
        $root_link_map_address = $main_executable_reader->getLinkMapAddress();

        $tls_finder = new LibThreadDbTlsFinder(
            $libpthread_symbol_reader,
            X64LinuxThreadPointerRetriever::createDefault(),
            $this->memory_reader,
            $this->integer_reader
        );
        $link_map = $this->link_map_loader->searchByName(
            $php_module_name,
            $process_specifier->pid,
            $root_link_map_address,
        );
        $tls_block_address = $tls_finder->findTlsBlock($process_specifier->pid, $link_map?->this_address);

        $php_path = $target_php_settings->php_path ?? $this->process_path_resolver->resolve(
            $process_specifier->pid,
            $php_module_name,
        );

        $byte_reader = new StringByteReader($this->file_reader->readAll($php_path));
        $php_elf_header = $this->elf64_parser->parseElfHeader($byte_reader);
        $program_headers = $this->elf64_parser->parseProgramHeader($byte_reader, $php_elf_header);
        $pt_tls = $program_headers->findTls()[0];

        return [$tls_block_address, $pt_tls->p_memsz->toInt()];
    }



    public function findByBruteForcing(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ?int {
        [$tls_block_address, $tls_block_size] = $this->resolveTlsBlock(
            $process_specifier,
            $target_php_settings,
        );
        for ($current = $tls_block_address; $current < $tls_block_address + $tls_block_size; $current += 8) {
            $tsrm_ls_cache_candidate = $this->memory_reader->read(
                $process_specifier->pid,
                $current,
                8
            );
            $tsrm_ls_cache_address_candidate = $this->integer_reader->read64(
                new CDataByteReader($tsrm_ls_cache_candidate),
                0
            )->toInt();
            if ($this->validateCandidate($process_specifier, $target_php_settings, $tsrm_ls_cache_address_candidate)) {
                return $tsrm_ls_cache_address_candidate;
            }
        }
        return null;
    }



    public function validateCandidate(
        ProcessSpecifier $proces_specifier,
        TargetPhpSettings $target_php_settings,
        int $tsrm_ls_cache
    ): bool {
        if ($tsrm_ls_cache === 0) {
            return false;
        }
        $symbol_name = 'executor_globals';
        $process_specifier = new ProcessSpecifier($proces_specifier->pid);
        $zend_type_reader = $this->zend_type_reader_creator->create($target_php_settings->php_version);

        $executor_globals_address = null;

        try {
            switch ($target_php_settings->php_version) {
                case ZendTypeReader::V70:
                case ZendTypeReader::V71:
                case ZendTypeReader::V72:
                case ZendTypeReader::V73:
                    $id_symbol = $symbol_name . '_id';
                    $globals_id_cdata = $this->getZtsGlobalsSymbolReader($process_specifier, $target_php_settings)
                        ->read($id_symbol);
                    if (is_null($globals_id_cdata)) {
                        throw new RuntimeException('global symbol id not found');
                    }
                    $tsrm_ls_cache_dereferenced = $this->integer_reader->read64(
                        new CDataByteReader(
                            $this->memory_reader->read(
                                $process_specifier->pid,
                                $tsrm_ls_cache,
                                8
                            )
                        ),
                        0
                    )->toInt();
                    $globals_id = $this->integer_reader->read32(
                        new CDataByteReader($globals_id_cdata),
                        0
                    );
                    $executor_globals_address = $this->integer_reader->read64(
                        new CDataByteReader(
                            $this->memory_reader->read(
                                $process_specifier->pid,
                                $tsrm_ls_cache_dereferenced + ($globals_id - 1) * 8,
                                8
                            )
                        ),
                        0
                    )->toInt();
                    break;

                case ZendTypeReader::V74:
                case ZendTypeReader::V80:
                case ZendTypeReader::V81:
                case ZendTypeReader::V82:
                case ZendTypeReader::V83:
                    $offset = $symbol_name . '_offset';
                    $globals_offset_cdata = $this->getZtsGlobalsSymbolReader(
                        $process_specifier,
                        $target_php_settings
                    )->read($offset);
                    if (is_null($globals_offset_cdata)) {
                        throw new RuntimeException('globals offset not found');
                    }
                    $globals_offset = $this->integer_reader->read64(
                        new CDataByteReader($globals_offset_cdata),
                        0
                    )->toInt();
                    $executor_globals_address = $tsrm_ls_cache + $globals_offset;
                    break;
                default:
                    throw new \LogicException('this should never happen');
            }
            if (!is_null($executor_globals_address)) {
                $eg_pointer = new Pointer(
                    ZendExecutorGlobals::class,
                    $executor_globals_address,
                    $zend_type_reader->sizeOf(ZendExecutorGlobals::getCTypeName())
                );
                $dereferencer = new RemoteProcessDereferencer(
                    $this->memory_reader,
                    $process_specifier,
                    new ZendCastedTypeProvider($zend_type_reader),
                );
                $eg = $dereferencer->deref($eg_pointer);
                if (!$eg->uninitialized_zval->isNull()) {
                    return false;
                }
                if (!$eg->error_zval->isError()) {
                    return false;
                }
                if (is_null($eg->zend_constants)) {
                    return false;
                }
                $constants = $dereferencer->deref($eg->zend_constants);
                $php_version = $constants->findByKey($dereferencer, 'PHP_VERSION');
                if (is_null($php_version)) {
                    return false;
                }
                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }


    public function getZtsGlobalsSymbolReader(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ProcessSymbolReaderInterface {
        return $this->php_symbol_reader_creator->create(
            $process_specifier->pid,
            $target_php_settings->zts_globals_regex,
            $target_php_settings->libpthread_regex,
            $target_php_settings->php_path,
            $target_php_settings->libpthread_path
        );
    }
}
