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

use PhpProfiler\Command\Inspector\Settings\TargetProcessSettings;
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

/**
 * Class PhpGlobalsFinder
 * @package PhpProfiler\ProcessReader
 */
final class PhpGlobalsFinder
{
    private ?int $tsrm_ls_cache = null;
    private bool $tsrm_ls_cache_not_found = false;
    private IntegerByteSequenceReader $integer_reader;
    private PhpSymbolReaderCreator $php_symbol_reader_creator;
    /** @var ProcessSymbolReaderInterface[] */
    private array $php_symbol_reader_cache = [];
    private MemoryReaderInterface $memory_reader;

    /**
     * PhpGlobalsFinder constructor.
     * @param PhpSymbolReaderCreator $php_symbol_reader_creator
     * @param IntegerByteSequenceReader $integer_reader
     * @param MemoryReaderInterface $memory_reader
     */
    public function __construct(
        PhpSymbolReaderCreator $php_symbol_reader_creator,
        IntegerByteSequenceReader $integer_reader,
        MemoryReaderInterface $memory_reader
    ) {
        $this->php_symbol_reader_creator = $php_symbol_reader_creator;
        $this->integer_reader = $integer_reader;
        $this->memory_reader = $memory_reader;
    }

    /**
     * @param TargetProcessSettings $target_process_settings
     * @return int
     * @throws ElfParserException
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function findTsrmLsCache(TargetProcessSettings $target_process_settings): ?int
    {
        if (!isset($this->tsrm_ls_cache) and !$this->tsrm_ls_cache_not_found) {
            $tsrm_lm_cache_cdata = $this->getSymbolReader($target_process_settings)->read('_tsrm_ls_cache');
            if (isset($tsrm_lm_cache_cdata)) {
                $this->tsrm_ls_cache = $this->integer_reader->read64(
                    new CDataByteReader($tsrm_lm_cache_cdata),
                    0
                )->toInt();
            } else {
                $this->tsrm_ls_cache_not_found = true;
            }
        }
        return $this->tsrm_ls_cache;
    }

    /**
     * @param TargetProcessSettings $target_process_settings
     * @return ProcessSymbolReaderInterface
     * @throws ElfParserException
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function getSymbolReader(TargetProcessSettings $target_process_settings): ProcessSymbolReaderInterface
    {
        if (!isset($this->php_symbol_reader_cache[$target_process_settings->pid])) {
            $symbol_reader = $this->php_symbol_reader_creator->create(
                $target_process_settings->pid,
                $target_process_settings->php_regex,
                $target_process_settings->libpthread_regex,
                $target_process_settings->php_path,
                $target_process_settings->libpthread_path
            );
            $this->php_symbol_reader_cache[$target_process_settings->pid] = $symbol_reader;
        }
        return $this->php_symbol_reader_cache[$target_process_settings->pid];
    }

    /**
     * @param TargetProcessSettings $target_process_settings
     * @return int
     * @throws ElfParserException
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function findExecutorGlobals(TargetProcessSettings $target_process_settings): int
    {
        $tsrm_ls_cache = $this->findTsrmLsCache($target_process_settings);
        if (isset($tsrm_ls_cache)) {
            switch ($target_process_settings->php_version) {
                case ZendTypeReader::V70:
                case ZendTypeReader::V71:
                case ZendTypeReader::V72:
                case ZendTypeReader::V73:
                    $executor_globals_id_cdata = $this->getSymbolReader($target_process_settings)
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
                    $executor_globals_offset_cdata = $this->getSymbolReader($target_process_settings)
                        ->read('executor_globals_offset');
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
        $executor_globals_address = $this->getSymbolReader($target_process_settings)
            ->resolveAddress('executor_globals');
        if (is_null($executor_globals_address)) {
            throw new RuntimeException('executor globals not found');
        }
        return $executor_globals_address;
    }
}
