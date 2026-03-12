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
use Reli\Lib\Elf\Process\ProcessSymbolReaderException;
use Reli\Lib\Elf\Process\ProcessSymbolReaderInterface;
use Reli\Lib\Elf\Tls\TlsFinderException;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\ProcessSpecifier;
use RuntimeException;

class PhpGlobalsFinder
{
    public function __construct(
        private PhpSymbolReaderCreator $php_symbol_reader_creator,
        private IntegerByteSequenceReader $integer_reader,
        private MemoryReaderInterface $memory_reader,
        private PhpTsrmLsCacheFinder $tsrm_ls_cache_finder,
        private TsrmGlobalsResolver $tsrm_globals_resolver,
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
        $tsrm_ls_cache_cdata = $this->getSymbolReader(
            $process_specifier,
            $target_php_settings
        )->read('_tsrm_ls_cache');
        if (isset($tsrm_ls_cache_cdata)) {
            $tsrm_ls_cache_address = $this->integer_reader->read64(
                new CDataByteReader($tsrm_ls_cache_cdata),
                0
            )->toInt();
            if ($tsrm_ls_cache_address === 0) {
                return null;
            }
            return $tsrm_ls_cache_address;
        }
        return $this->tsrm_ls_cache_finder->findByBruteForcing($process_specifier, $target_php_settings);
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
        return $this->php_symbol_reader_creator->create(
            $process_specifier->pid,
            $target_php_settings->php_regex,
            $target_php_settings->libpthread_regex,
            $target_php_settings->php_path,
            $target_php_settings->libpthread_path
        );
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
        $symbol_reader = $this->tsrm_globals_resolver->getZtsGlobalsSymbolReader(
            $process_specifier,
            $target_php_settings
        );
        return $symbol_reader->resolveAddress('module_registry');
    }

    /** @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
    public function findGlobals(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        string $symbol_name,
    ): int {
        $tsrm_ls_cache = $this->findTsrmLsCache($process_specifier, $target_php_settings);
        if (isset($tsrm_ls_cache)) {
            return $this->tsrm_globals_resolver->resolveGlobalsAddress(
                $process_specifier,
                $target_php_settings,
                $symbol_name,
                $tsrm_ls_cache,
            );
        }
        $globals_address = $this->getSymbolReader($process_specifier, $target_php_settings)
            ->resolveAddress($symbol_name);
        if (is_null($globals_address)) {
            throw new RuntimeException('global symbol not found ' . $symbol_name);
        }
        return $globals_address;
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
}
