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

namespace PhpProfiler\Lib\Process\MemoryMap;

use PhpProfiler\Lib\String\LineFetcher;

final class ProcessMemoryMapParser
{
    public function __construct(
        private LineFetcher $line_fetcher
    ) {
    }

    public function parse(string $memory_map_string): ProcessMemoryMap
    {
        $memory_areas = [];
        foreach ($this->line_fetcher->createIterable($memory_map_string) as $line) {
            $line_parsed = $this->parseLine($line);
            if ($line_parsed !== null) {
                $memory_areas[] = $line_parsed;
            }
        }
        return new ProcessMemoryMap($memory_areas);
    }

    private function parseLine(string $line): ?ProcessMemoryArea
    {
        $matches = [];
        preg_match(
            // phpcs:ignore Generic.Files.LineLength.TooLong
            '/([0-9a-f]+)-([0-9a-f]+) ([r\-][w\-][x\-][sp\-]) ([0-9a-f]+) ([0-9][0-9][0-9]?:[0-9][0-9][0-9]?) ([0-9]+) +([^ ].+)/',
            $line,
            $matches
        );
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
