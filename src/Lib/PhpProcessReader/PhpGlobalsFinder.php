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
        private MemoryReaderInterface $memory_reader
    ) {
    }

    /**
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function findTsrmLsCache(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ?int {
        $tsrm_lm_cache_cdata = $this->getSymbolReader(
            $process_specifier,
            $target_php_settings
        )->read('_tsrm_ls_cache');
        if (isset($tsrm_lm_cache_cdata)) {
            return $this->integer_reader->read64(
                new CDataByteReader($tsrm_lm_cache_cdata),
                0
            )->toInt();
        }
        return null;
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

    public function findModuleRegistry(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ?int {
        $symbol_reader = $this->getSymbolReader(
            $process_specifier,
            $target_php_settings
        );
        $module_registry = $symbol_reader->resolveAddress('module_registry');
        return $module_registry;
    }

    public function findGlobals(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        string $symbol_name,
    ): int {
        $tsrm_ls_cache = $this->findTsrmLsCache($process_specifier, $target_php_settings);
        if (isset($tsrm_ls_cache)) {
            switch ($target_php_settings->php_version) {
                case ZendTypeReader::V70:
                case ZendTypeReader::V71:
                case ZendTypeReader::V72:
                case ZendTypeReader::V73:
                    $id_symbol = $symbol_name . '_id';
                    $globals_id_cdata = $this->getSymbolReader($process_specifier, $target_php_settings)
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
                    return $this->integer_reader->read64(
                        new CDataByteReader(
                            $this->memory_reader->read(
                                $process_specifier->pid,
                                $tsrm_ls_cache_dereferenced + ($globals_id - 1) * 8,
                                8
                            )
                        ),
                        0
                    )->toInt();

                case ZendTypeReader::V74:
                case ZendTypeReader::V80:
                case ZendTypeReader::V81:
                    $offset = $symbol_name . '_offset';
                    $globals_offset_cdata = $this->getSymbolReader(
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
                    return $tsrm_ls_cache + $globals_offset;
                default:
                    throw new \LogicException('this should never happen');
            }
        }
        $globals_address = $this->getSymbolReader($process_specifier, $target_php_settings)
            ->resolveAddress($symbol_name);
        if (is_null($globals_address)) {
            throw new RuntimeException('global symbol not found ' . $symbol_name);
        }
        return $globals_address;
    }

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
