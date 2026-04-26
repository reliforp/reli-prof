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
use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\ByteStream\CDataByteReader;
use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprint;
use Reli\Lib\Elf\Process\BinaryFingerprintCreator;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReader;
use Reli\Lib\Elf\Process\ProcessSymbolReaderException;
use Reli\Lib\Elf\Process\ProcessSymbolReaderInterface;
use Reli\Lib\Elf\Tls\TlsFinderException;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\ProcessSpecifier;
use RuntimeException;

/** @psalm-suppress ClassMustBeFinal */
class PhpGlobalsFinder
{
    /** @var array<int, ProcessSymbolReaderInterface> */
    private array $symbol_reader_cache = [];

    /** @var array<int, int|null> */
    private array $tsrm_ls_cache_cache = [];

    public function __construct(
        private PhpSymbolReaderCreator $php_symbol_reader_creator,
        private IntegerByteSequenceReader $integer_reader,
        private MemoryReaderInterface $memory_reader,
        private PhpTsrmLsCacheFinder $tsrm_ls_cache_finder,
        private TsrmGlobalsResolver $tsrm_globals_resolver,
        private BinaryAnalysisCache $binary_analysis_cache,
        private ProcessMemoryMapCreatorInterface $process_memory_map_creator,
        private BinaryFingerprintCreator $binary_fingerprint_creator,
    ) {
    }

    /**
     * @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function findTsrmLsCache(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ?int {
        if (array_key_exists($process_specifier->pid, $this->tsrm_ls_cache_cache)) {
            return $this->tsrm_ls_cache_cache[$process_specifier->pid];
        }

        $fingerprint = $this->getPhpFingerprint($process_specifier, $target_php_settings->php_regex);
        $cached = $this->binary_analysis_cache->get($fingerprint, 'tsrm_ls_cache');
        if ($cached !== null && isset($cached['has_tsrm_ls_cache'])) {
            if ($cached['has_tsrm_ls_cache'] === false) {
                // A negative cache used to be persisted whenever the very
                // first brute-force attempt returned null, even if the failure
                // was a per-thread one (e.g. an idle FrankenPHP worker whose
                // TLS slot was still zero). That made every subsequent attach
                // to the same binary fail with a misleading "executor_globals
                // not found". Re-verify the negative entry by checking PT_TLS
                // in the ELF: if the binary actually has a TLS segment it is
                // ZTS and the negative is stale, so drop it and fall through.
                $tls_block = $this->tsrm_ls_cache_finder->resolveTlsBlock(
                    $process_specifier,
                    $target_php_settings,
                );
                if ($tls_block === null) {
                    $this->tsrm_ls_cache_cache[$process_specifier->pid] = null;
                    return null;
                }
                $this->binary_analysis_cache->set($fingerprint, 'tsrm_ls_cache', []);
            } else {
                $result = $this->tryResolveTsrmLsCacheFromCachedOffset(
                    $process_specifier,
                    $target_php_settings,
                    $fingerprint,
                );
                if ($result !== null) {
                    $this->tsrm_ls_cache_cache[$process_specifier->pid] = $result;
                    return $result;
                }
            }
        }

        $symbol_reader = $this->getSymbolReader($process_specifier, $target_php_settings);
        $tsrm_ls_cache_cdata = $symbol_reader->read('_tsrm_ls_cache');
        if (isset($tsrm_ls_cache_cdata)) {
            $this->binary_analysis_cache->set($fingerprint, 'tsrm_ls_cache', [
                'has_tsrm_ls_cache' => true,
            ]);
            $tsrm_ls_cache_address = $this->integer_reader->read64(
                new CDataByteReader($tsrm_ls_cache_cdata),
                0
            )->toInt();
            if ($tsrm_ls_cache_address === 0) {
                $this->tsrm_ls_cache_cache[$process_specifier->pid] = null;
                return null;
            }
            $this->tsrm_ls_cache_cache[$process_specifier->pid] = $tsrm_ls_cache_address;
            return $tsrm_ls_cache_address;
        }
        assert($target_php_settings->isDecided());
        $brute_force = $this->tsrm_ls_cache_finder->findByBruteForcing(
            $process_specifier,
            $target_php_settings,
        );
        if ($brute_force->address !== null) {
            $this->binary_analysis_cache->set($fingerprint, 'tsrm_ls_cache', [
                'has_tsrm_ls_cache' => true,
            ]);
        } elseif (!$brute_force->has_tls_segment) {
            // No PT_TLS in the ELF — confirmed NTS build, persist the negative.
            $this->binary_analysis_cache->set($fingerprint, 'tsrm_ls_cache', [
                'has_tsrm_ls_cache' => false,
            ]);
        }
        // else: PT_TLS exists but the chosen thread's slot is still zero
        // (e.g. an idle FrankenPHP worker that hasn't served a request yet).
        // That is per-thread state, not a binary-level fact, so do not poison
        // the cache for the rest of the binary's threads.
        $this->tsrm_ls_cache_cache[$process_specifier->pid] = $brute_force->address;
        return $brute_force->address;
    }

    private function tryResolveTsrmLsCacheFromCachedOffset(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        BinaryFingerprint $fingerprint,
    ): ?int {
        $tls_cached = $this->binary_analysis_cache->get($fingerprint, 'tls_offset');
        if ($tls_cached === null || !isset($tls_cached['offset']) || !is_int($tls_cached['offset'])) {
            return null;
        }

        $symbol_reader = $this->getSymbolReader($process_specifier, $target_php_settings);
        if (!($symbol_reader instanceof ProcessModuleSymbolReader)) {
            return null;
        }
        $tls_block_address = $symbol_reader->getTlsBlockAddress();
        if ($tls_block_address === null) {
            return null;
        }

        try {
            $candidate_address = $tls_block_address + $tls_cached['offset'];
            $candidate_cdata = $this->memory_reader->read(
                $process_specifier->pid,
                $candidate_address,
                8
            );
            $tsrm_ls_cache_address = $this->integer_reader->read64(
                new CDataByteReader($candidate_cdata),
                0
            )->toInt();
            if ($tsrm_ls_cache_address === 0) {
                return null;
            }
            return $tsrm_ls_cache_address;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function getSymbolReader(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ProcessSymbolReaderInterface {
        if (!isset($this->symbol_reader_cache[$process_specifier->pid])) {
            $this->symbol_reader_cache[$process_specifier->pid] = $this->php_symbol_reader_creator->create(
                $process_specifier->pid,
                $target_php_settings->php_regex,
                $target_php_settings->libpthread_regex,
                $target_php_settings->php_path,
                $target_php_settings->libpthread_path
            );
        }
        return $this->symbol_reader_cache[$process_specifier->pid];
    }

    /**
     * @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     * @throws ElfParserException
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function findExecutorGlobals(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): int {
        return $this->findGlobals(
            $process_specifier,
            $target_php_settings,
            'executor_globals'
        );
    }

    /**
     * @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     * @throws ElfParserException
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function findCompilerGlobals(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): int {
        return $this->findGlobals(
            $process_specifier,
            $target_php_settings,
            'compiler_globals'
        );
    }

    public function findModuleRegistry(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ?int {
        $module_map = $this->getPhpModuleMemoryMap(
            $process_specifier,
            $target_php_settings->zts_globals_regex,
        );
        $fingerprint = $this->createFingerprint($process_specifier, $module_map);
        $cached = $this->binary_analysis_cache->get($fingerprint, 'nts_globals');
        if ($cached !== null && isset($cached['module_registry']) && is_int($cached['module_registry'])) {
            return $module_map->getBaseAddress() + $cached['module_registry'];
        }

        $symbol_reader = $this->tsrm_globals_resolver->getZtsGlobalsSymbolReader(
            $process_specifier,
            $target_php_settings
        );
        $address = $symbol_reader->resolveAddress('module_registry');
        if ($address === null) {
            return null;
        }

        $cache_data = $cached ?? [];
        $cache_data['module_registry'] = $address - $module_map->getBaseAddress();
        $this->binary_analysis_cache->set($fingerprint, 'nts_globals', $cache_data);

        return $address;
    }

    /** @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
    public function findGlobals(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        string $symbol_name,
    ): int {
        $module_map = $this->getPhpModuleMemoryMap($process_specifier, $target_php_settings->php_regex);
        $fingerprint = $this->createFingerprint($process_specifier, $module_map);

        // Check ZTS (TSRM) path first: if the binary is known to have TSRM LS cache,
        // or if we can detect it now, use the ZTS resolution path.
        // This must be checked BEFORE the NTS globals cache to avoid using a cached NTS
        // offset for a ZTS binary (where globals are in TLS, not at a static offset).
        $tsrm_ls_cache = $this->findTsrmLsCache($process_specifier, $target_php_settings);
        if (isset($tsrm_ls_cache)) {
            return $this->tsrm_globals_resolver->resolveGlobalsAddress(
                $process_specifier,
                $target_php_settings,
                $symbol_name,
                $tsrm_ls_cache,
            );
        }

        $cached_nts = $this->binary_analysis_cache->get($fingerprint, 'nts_globals');
        if ($cached_nts !== null && isset($cached_nts[$symbol_name]) && is_int($cached_nts[$symbol_name])) {
            return $module_map->getBaseAddress() + $cached_nts[$symbol_name];
        }

        $globals_address = $this->getSymbolReader($process_specifier, $target_php_settings)
            ->resolveAddress($symbol_name);
        if (is_null($globals_address)) {
            // Help disambiguate the FrankenPHP-style failure: PT_TLS is in the
            // ELF (= ZTS build) but TSRM resolution returned null on the chosen
            // thread, so the falling-through-to-NTS lookup never had a chance.
            $tls_block = $this->tsrm_ls_cache_finder->resolveTlsBlock(
                $process_specifier,
                $target_php_settings,
            );
            if ($tls_block !== null) {
                throw new RuntimeException(
                    sprintf(
                        '%s not found via TSRM on TID %d. The binary is ZTS '
                        . '(PT_TLS present) but this thread\'s _tsrm_ls_cache slot '
                        . 'is uninitialized -- typical of an idle FrankenPHP worker. '
                        . 'Try a different worker TID, push a request through the '
                        . 'server first, or run inspector:trace / inspector:daemon '
                        . 'briefly to populate the TLS-offset cache.',
                        $symbol_name,
                        $process_specifier->pid,
                    ),
                );
            }
            throw new RuntimeException('global symbol not found ' . $symbol_name);
        }

        $cache_data = $cached_nts ?? [];
        $cache_data[$symbol_name] = $globals_address - $module_map->getBaseAddress();
        $this->binary_analysis_cache->set($fingerprint, 'nts_globals', $cache_data);

        return $globals_address;
    }

    private function getPhpFingerprint(
        ProcessSpecifier $process_specifier,
        string $regex,
    ): BinaryFingerprint {
        $module_map = $this->getPhpModuleMemoryMap($process_specifier, $regex);
        return $this->createFingerprint($process_specifier, $module_map);
    }

    private function createFingerprint(
        ProcessSpecifier $process_specifier,
        ProcessModuleMemoryMap $module_map,
    ): BinaryFingerprint {
        return $this->binary_fingerprint_creator->createFromProcessModuleMemoryMap(
            $process_specifier->pid,
            $module_map,
        );
    }

    private function getPhpModuleMemoryMap(
        ProcessSpecifier $process_specifier,
        string $regex,
    ): ProcessModuleMemoryMap {
        $process_memory_map = $this->process_memory_map_creator->getProcessMemoryMap(
            $process_specifier->pid,
        );
        $php_areas = $process_memory_map->findByNameRegex($regex);
        return new ProcessModuleMemoryMap($php_areas);
    }

    /**
     * Resolve a plain BSS/data symbol address from the PHP binary.
     * Unlike findGlobals(), this does NOT use the TSRM resolution path
     * on ZTS builds. Use this for symbols like zend_one_char_string
     * that are process-global (not thread-local) regardless of ZTS.
     *
     * @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     */
    public function findSymbol(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        string $symbol_name,
    ): int {
        $module_map = $this->getPhpModuleMemoryMap($process_specifier, $target_php_settings->php_regex);
        $fingerprint = $this->createFingerprint($process_specifier, $module_map);

        $cached = $this->binary_analysis_cache->get($fingerprint, 'nts_globals');
        if ($cached !== null && isset($cached[$symbol_name]) && is_int($cached[$symbol_name])) {
            return $module_map->getBaseAddress() + $cached[$symbol_name];
        }

        $address = $this->getSymbolReader($process_specifier, $target_php_settings)
            ->resolveAddress($symbol_name);
        if (is_null($address)) {
            throw new RuntimeException('symbol not found: ' . $symbol_name);
        }

        $cache_data = $cached ?? [];
        $cache_data[$symbol_name] = $address - $module_map->getBaseAddress();
        $this->binary_analysis_cache->set($fingerprint, 'nts_globals', $cache_data);

        return $address;
    }

    /** @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
    public function findSAPIGlobals(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): int {
        return $this->findGlobals(
            $process_specifier,
            $target_php_settings,
            'sapi_globals'
        );
    }

    /** @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
    public function findBasicGlobals(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ?int {
        try {
            return $this->findGlobals(
                $process_specifier,
                $target_php_settings,
                'basic_globals'
            );
        } catch (\RuntimeException) {
            return null;
        }
    }
}
