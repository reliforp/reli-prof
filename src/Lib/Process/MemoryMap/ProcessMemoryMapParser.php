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

namespace Reli\Lib\Process\MemoryMap;

use PhpCast\Cast;
use Reli\Lib\String\LineFetcher;

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
        $attribute_string = $matches[3];
        return new ProcessMemoryArea(
            begin: $matches[1],
            end: $matches[2],
            file_offset: $matches[4],
            attribute: new ProcessMemoryAttribute(
                read: $attribute_string[0] === 'r',
                write: $attribute_string[1] === 'w',
                execute: $attribute_string[2] === 'x',
                protected: $attribute_string[3] === 'p',
            ),
            device_id: $matches[5],
            inode_num: Cast::toInt($matches[6]),
            name: $matches[7],
        );
    }
}
