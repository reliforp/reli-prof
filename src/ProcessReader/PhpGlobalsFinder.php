<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\ProcessReader;

use PhpProfiler\Lib\Binary\BinaryReader;
use PhpProfiler\Lib\Binary\CDataByteReader;
use PhpProfiler\Lib\Elf\Process\ProcessModuleSymbolReader;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;

/**
 * Class PhpGlobalsFinder
 * @package PhpProfiler\ProcessReader
 */
final class PhpGlobalsFinder
{
    private ProcessModuleSymbolReader $php_symbol_reader;
    private ?int $tsrm_ls_cache = null;
    private bool $tsrm_ls_cache_not_found = false;
    private BinaryReader $binary_reader;

    /**
     * PhpGlobalsFinder constructor.
     * @param ProcessModuleSymbolReader $php_symbol_reader
     */
    public function __construct(ProcessModuleSymbolReader $php_symbol_reader)
    {
        $this->php_symbol_reader = $php_symbol_reader;
        $this->binary_reader = new BinaryReader();
    }

    /**
     * @return int
     * @throws ProcessSymbolReaderException
     */
    public function findTsrmLsCache(): ?int
    {
        if (!isset($this->tsrm_ls_cache) and !$this->tsrm_ls_cache_not_found) {
            $tsrm_lm_cache_cdata = $this->php_symbol_reader->read('_tsrm_ls_cache');
            if (isset($tsrm_lm_cache_cdata)) {
                $this->tsrm_ls_cache = $this->binary_reader->read64(
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
     * @return int
     * @throws ProcessSymbolReaderException
     */
    public function findExecutorGlobals(): int
    {
        $tsrm_ls_cache = $this->findTsrmLsCache();
        if (isset($tsrm_ls_cache)) {
            $executor_globals_offset_cdata = $this->php_symbol_reader->read('executor_globals_offset');
            if (is_null($executor_globals_offset_cdata)) {
                throw new \RuntimeException('executor_globals_offset not found');
            }
            $executor_globals_offset = $this->binary_reader->read64(
                new CDataByteReader($executor_globals_offset_cdata),
                0
            )->toInt();
            return $tsrm_ls_cache + $executor_globals_offset;
        }
        $executor_globals_cdata = $this->php_symbol_reader->read('executor_globals');
        if (is_null($executor_globals_cdata)) {
            throw new \RuntimeException('executor globals not found');
        }
        return $this->binary_reader->read64(
            new CDataByteReader($executor_globals_cdata),
            0
        )->toInt();
    }
}
