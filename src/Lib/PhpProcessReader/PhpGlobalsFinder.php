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

use PhpProfiler\Lib\Binary\IntegerByteSequence\IntegerByteSequenceReader;
use PhpProfiler\Lib\Binary\CDataByteReader;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderInterface;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
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

    /**
     * PhpGlobalsFinder constructor.
     * @param PhpSymbolReaderCreator $php_symbol_reader_creator
     * @param IntegerByteSequenceReader $integer_reader
     */
    public function __construct(
        PhpSymbolReaderCreator $php_symbol_reader_creator,
        IntegerByteSequenceReader $integer_reader
    ) {
        $this->php_symbol_reader_creator = $php_symbol_reader_creator;
        $this->integer_reader = $integer_reader;
    }

    /**
     * @param int $pid
     * @return int
     * @throws ElfParserException
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function findTsrmLsCache(int $pid): ?int
    {
        if (!isset($this->tsrm_ls_cache) and !$this->tsrm_ls_cache_not_found) {
            $tsrm_lm_cache_cdata = $this->getSymbolReader($pid)->read('_tsrm_ls_cache');
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
     * @param int $pid
     * @return ProcessSymbolReaderInterface
     * @throws ProcessSymbolReaderException
     * @throws ElfParserException
     * @throws TlsFinderException
     * @throws MemoryReaderException
     */
    public function getSymbolReader(int $pid): ProcessSymbolReaderInterface
    {
        if (!isset($this->php_symbol_reader_cache[$pid])) {
            $this->php_symbol_reader_cache[$pid] = $this->php_symbol_reader_creator->create($pid);
        }
        return $this->php_symbol_reader_cache[$pid];
    }

    /**
     * @param int $pid
     * @return int
     * @throws ElfParserException
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function findExecutorGlobals(int $pid): int
    {
        $tsrm_ls_cache = $this->findTsrmLsCache($pid);
        if (isset($tsrm_ls_cache)) {
            $executor_globals_offset_cdata = $this->getSymbolReader($pid)->read('executor_globals_offset');
            if (is_null($executor_globals_offset_cdata)) {
                throw new RuntimeException('executor_globals_offset not found');
            }
            $executor_globals_offset = $this->integer_reader->read64(
                new CDataByteReader($executor_globals_offset_cdata),
                0
            )->toInt();
            return $tsrm_ls_cache + $executor_globals_offset;
        }
        $executor_globals_address = $this->getSymbolReader($pid)->resolveAddress('executor_globals');
        if (is_null($executor_globals_address)) {
            throw new RuntimeException('executor globals not found');
        }
        return $executor_globals_address;
    }
}
