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
use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprint;
use Reli\Lib\File\FileReaderInterface;
use Reli\Lib\File\PathResolver\ContainerAwarePathResolver;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;

final class PhpTsrmLsCacheFinder
{
    public function __construct(
        private PhpSymbolReaderCreator $php_symbol_reader_creator,
        private TsrmGlobalsResolver $tsrm_globals_resolver,
        private MemoryReaderInterface $memory_reader,
        private IntegerByteSequenceReader $integer_reader,
        private Elf64Parser $elf64_parser,
        private FileReaderInterface $file_reader,
        private ProcessMemoryMapCreatorInterface $process_memory_map_creator,
        private ContainerAwarePathResolver $process_path_resolver,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private BinaryAnalysisCache $binary_analysis_cache,
    ) {
    }

    /** @return array{int, int, ProcessModuleMemoryMap}|null */
    public function resolveTlsBlock(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ?array {
        $php_symbol_reader = $this->php_symbol_reader_creator->create(
            $process_specifier->pid,
            $target_php_settings->php_regex,
            $target_php_settings->libpthread_regex,
            $target_php_settings->php_path,
            $target_php_settings->libpthread_path
        );

        $tls_block_address = $php_symbol_reader->getTlsBlockAddress();
        if (is_null($tls_block_address)) {
            return null;
        }

        $process_memory_map = $this->process_memory_map_creator->getProcessMemoryMap($process_specifier->pid);
        $php_memory_areas = $process_memory_map->findByNameRegex($target_php_settings->php_regex);
        $php_module_memory_map = new ProcessModuleMemoryMap($php_memory_areas);
        $php_module_name = $php_module_memory_map->getModuleName();

        $php_path = $target_php_settings->php_path ?? $this->process_path_resolver->resolve(
            $process_specifier->pid,
            $php_module_name,
        );

        $byte_reader = new StringByteReader($this->file_reader->readAll($php_path));
        $php_elf_header = $this->elf64_parser->parseElfHeader($byte_reader);
        $program_headers = $this->elf64_parser->parseProgramHeader($byte_reader, $php_elf_header);
        $tls_entries = $program_headers->findTls();
        if ($tls_entries === []) {
            return null;
        }

        return [$tls_block_address, $tls_entries[0]->p_memsz->toInt(), $php_module_memory_map];
    }

    /** @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
    public function findByBruteForcing(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ?int {
        $tls_block = $this->resolveTlsBlock(
            $process_specifier,
            $target_php_settings,
        );
        if (is_null($tls_block)) {
            return null;
        }
        [$tls_block_address, $tls_block_size, $php_module_memory_map] = $tls_block;
        $fingerprint = BinaryFingerprint::fromProcessModuleMemoryMap($php_module_memory_map);

        $cached = $this->binary_analysis_cache->get($fingerprint, 'tls_offset');
        if ($cached !== null && isset($cached['offset']) && is_int($cached['offset'])) {
            $candidate_address = $tls_block_address + $cached['offset'];
            assert($target_php_settings->isDecided());
            if ($this->validateCandidate($process_specifier, $target_php_settings, $candidate_address)) {
                return $candidate_address;
            }
        }

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
            assert($target_php_settings->isDecided());
            if ($this->validateCandidate($process_specifier, $target_php_settings, $tsrm_ls_cache_address_candidate)) {
                $this->binary_analysis_cache->set(
                    $fingerprint,
                    'tls_offset',
                    ['offset' => $tsrm_ls_cache_address_candidate - $tls_block_address],
                );
                return $tsrm_ls_cache_address_candidate;
            }
        }
        return null;
    }

    /** @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
    public function validateCandidate(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $tsrm_ls_cache
    ): bool {
        if ($tsrm_ls_cache === 0) {
            return false;
        }

        try {
            $executor_globals_address = $this->tsrm_globals_resolver->resolveGlobalsAddress(
                $process_specifier,
                $target_php_settings,
                'executor_globals',
                $tsrm_ls_cache,
            );

            assert($target_php_settings->isDecided());
            $zend_type_reader = $this->zend_type_reader_creator->create($target_php_settings->php_version);
            $eg_pointer = new Pointer(
                ZendExecutorGlobals::class,
                $executor_globals_address,
                $zend_type_reader->sizeOf(ZendExecutorGlobals::getCTypeName())
            );
            $dereferencer = new RemoteProcessDereferencer(
                $this->memory_reader,
                $process_specifier,
                new ZendCastedTypeProvider($zend_type_reader),
                new VersionedPointedTypeResolver($target_php_settings->php_version),
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
        } catch (\Throwable $e) {
            return false;
        }
    }
}
