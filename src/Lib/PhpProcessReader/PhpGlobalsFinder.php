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

use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TargetProcessSettings\TargetProcessSettings;
use PhpProfiler\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use PhpProfiler\Lib\ByteStream\CDataByteReader;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderInterface;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;
use RuntimeException;

final class PhpGlobalsFinder
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
        TargetProcessSettings $target_process_settings,
        TargetPhpSettings $target_php_settings
    ): ?int {
        $tsrm_lm_cache_cdata = $this->getSymbolReader(
            $target_process_settings,
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
        TargetProcessSettings $target_process_settings,
        TargetPhpSettings $target_php_settings
    ): ProcessSymbolReaderInterface {
        return $this->php_symbol_reader_creator->create(
            $target_process_settings->pid,
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
        TargetProcessSettings $target_process_settings,
        TargetPhpSettings $target_php_settings
    ): int {
        $tsrm_ls_cache = $this->findTsrmLsCache($target_process_settings, $target_php_settings);
        if (isset($tsrm_ls_cache)) {
            switch ($target_php_settings->php_version) {
                case ZendTypeReader::V70:
                case ZendTypeReader::V71:
                case ZendTypeReader::V72:
                case ZendTypeReader::V73:
                    $executor_globals_id_cdata = $this->getSymbolReader($target_process_settings, $target_php_settings)
                        ->read('executor_globals_id');
                    if (is_null($executor_globals_id_cdata)) {
                        throw new RuntimeException('executor_globals_id not found');
                    }
                    $tsrm_ls_cache_dereferenced = $this->integer_reader->read64(
                        new CDataByteReader(
                            $this->memory_reader->read(
                                $target_process_settings->pid,
                                $tsrm_ls_cache,
                                8
                            )
                        ),
                        0
                    )->toInt();
                    $executor_globals_id = $this->integer_reader->read32(
                        new CDataByteReader($executor_globals_id_cdata),
                        0
                    );
                    return $this->integer_reader->read64(
                        new CDataByteReader(
                            $this->memory_reader->read(
                                $target_process_settings->pid,
                                $tsrm_ls_cache_dereferenced + ($executor_globals_id - 1) * 8,
                                8
                            )
                        ),
                        0
                    )->toInt();

                case ZendTypeReader::V74:
                case ZendTypeReader::V80:
                    $executor_globals_offset_cdata = $this->getSymbolReader(
                        $target_process_settings,
                        $target_php_settings
                    )->read('executor_globals_offset');
                    if (is_null($executor_globals_offset_cdata)) {
                        throw new RuntimeException('executor_globals_offset not found');
                    }
                    $executor_globals_offset = $this->integer_reader->read64(
                        new CDataByteReader($executor_globals_offset_cdata),
                        0
                    )->toInt();
                    return $tsrm_ls_cache + $executor_globals_offset;
                default:
                    throw new \LogicException('this should never happen');
            }
        }
        $executor_globals_address = $this->getSymbolReader($target_process_settings, $target_php_settings)
            ->resolveAddress('executor_globals');
        if (is_null($executor_globals_address)) {
            throw new RuntimeException('executor globals not found');
        }
        return $executor_globals_address;
    }
}
