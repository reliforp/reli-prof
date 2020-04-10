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
 * Class ProcessMemoryMapParser
 * @package PhpProfiler\ProcessReader
 */
class ProcessMemoryMapParser
{
    private LineFetcher $line_fetcher;

    /**
     * ProcessMemoryMapParser constructor.
     * @param LineFetcher $line_fetcher
     */
    public function __construct(LineFetcher $line_fetcher)
    {
        $this->line_fetcher = $line_fetcher;
    }

    /**
     * @param string $memory_map_string
     * @return ProcessMemoryMap
     */
    public function parse(string $memory_map_string): ProcessMemoryMap
    {
        $memory_areas = [];
        foreach ($this->line_fetcher->createGenerator($memory_map_string) as $line)
        {
            $line_parsed = $this->parseLine($line);
            if ($line_parsed !== null) {
                $memory_areas[] = $line_parsed;
            }
        }
        return new ProcessMemoryMap($memory_areas);
    }

    /**
     * @param string $line
     * @return ProcessMemoryArea
     */
    private function parseLine(string $line): ?ProcessMemoryArea
    {
        $matches = [];
        preg_match('/([0-9a-f]{12,16})-([0-9a-f]{12,16}) ([r\-][w\-][x\-][p\-]) ([0-9a-f]{8}) ([0-9][0-9]:[0-9][0-9]) ([0-9]+) +([^ ]+)/', $line, $matches);
        if ($matches === []) {
            return null;
        }
        $begin = $matches[1];
        $end = $matches[2];
        $attribute_string = $matches[3];
        $attribute = new ProcessMemoryAttribute(
            $attribute_string[0] === 'r',
            $attribute_string[1] === 'w',
            $attribute_string[2] === 'x',
            $attribute_string[3] === 'p',
        );
        $file_offset = $matches[4];
        $name = $matches[7];
        return new ProcessMemoryArea($begin, $end, $file_offset, $attribute, $name);
    }
}