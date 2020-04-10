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

use PhpProfiler\Lib\Process\MemoryReader;
use PhpProfiler\Lib\Process\MemoryReaderException;

/**
 * Class PhpGlobalsFinder
 * @package PhpProfiler\ProcessReader
 */
class PhpGlobalsFinder
{
    private MemoryReader $memory_reader;
    private ProcessModuleSymbolReader $php_symbol_reader;
    private ?int $tsrm_ls_cache = null;
    private bool $tsrm_ls_cache_not_found = false;

    /**
     * PhpGlobalsFinder constructor.
     * @param MemoryReader $memory_reader
     * @param ProcessModuleSymbolReader $php_symbol_reader
     */
    public function __construct(MemoryReader $memory_reader, ProcessModuleSymbolReader $php_symbol_reader)
    {
        $this->memory_reader = $memory_reader;
        $this->php_symbol_reader = $php_symbol_reader;
    }

    /**
     * @return int
     * @throws MemoryReaderException
     */
    public function findTsrmLsCache(): int
    {
        if (!isset($this->tsrm_ls_cache) and !$this->tsrm_ls_cache_not_found) {
            $this->tsrm_ls_cache = $this->php_symbol_reader->readAsInt64('_tsrm_ls_cache');
            if (!isset($this->tsrm_ls_cache)) {
                $this->tsrm_ls_cache_not_found = true;
            }
        }
        return $this->tsrm_ls_cache;
    }

    /**
     * @return int
     * @throws MemoryReaderException
     */
    public function findExecutorGlobals(): int
    {
        $tsrm_ls_cache = $this->findTsrmLsCache();
        if (isset($tsrm_ls_cache)) {
            $executor_globals_offset = $this->php_symbol_reader->readAsInt64('executor_globals_offset');
            return $tsrm_ls_cache + $executor_globals_offset;
        }
        return $this->php_symbol_reader->readAsInt64('executor_globals');
    }
}