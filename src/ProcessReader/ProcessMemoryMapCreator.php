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

use PhpProfiler\Lib\String\LineFetcher;

/**
 * Class ProcessMemoryMapCreator
 * @package PhpProfiler\ProcessReader
 */
final class ProcessMemoryMapCreator
{
    private ProcessMemoryMapReader $memory_map_reader;
    private ProcessMemoryMapParser $memory_map_parser;

    /**
     * @return static
     */
    public static function create(): self
    {
        return new self(
            new ProcessMemoryMapReader(),
            new ProcessMemoryMapParser(new LineFetcher())
        );
    }

    /**
     * ProcessMemoryMapCreator constructor.
     * @param ProcessMemoryMapReader $memory_map_reader
     * @param ProcessMemoryMapParser $memory_map_parser
     */
    public function __construct(ProcessMemoryMapReader $memory_map_reader, ProcessMemoryMapParser $memory_map_parser)
    {
        $this->memory_map_reader = $memory_map_reader;
        $this->memory_map_parser = $memory_map_parser;
    }

    /**
     * @param int $pid
     * @return ProcessMemoryMap
     */
    public function getProcessMemoryMap(int $pid): ProcessMemoryMap
    {
        $memory_map_raw = $this->memory_map_reader->read($pid);
        return $this->memory_map_parser->parse($memory_map_raw);
    }
}
