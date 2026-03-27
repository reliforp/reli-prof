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

use Reli\Lib\File\NativeFileReader;
use Reli\Lib\String\LineFetcher;

final class ProcessMemoryMapCreator implements ProcessMemoryMapCreatorInterface
{
    private const CACHE_TTL_NS = 100_000_000; // 100ms

    /** @var array<int, array{map: ProcessMemoryMap, time: int}> */
    private array $cache = [];

    public static function create(): self
    {
        return new self(
            new ProcessMemoryMapReader(new NativeFileReader()),
            new ProcessMemoryMapParser(new LineFetcher())
        );
    }

    public function __construct(
        private ProcessMemoryMapReader $memory_map_reader,
        private ProcessMemoryMapParser $memory_map_parser
    ) {
    }

    #[\Override]
    public function getProcessMemoryMap(int $pid): ProcessMemoryMap
    {
        $now = hrtime(true);
        if (
            isset($this->cache[$pid])
            && ($now - $this->cache[$pid]['time']) < self::CACHE_TTL_NS
        ) {
            return $this->cache[$pid]['map'];
        }

        $memory_map_raw = $this->memory_map_reader->read($pid);
        $map = $this->memory_map_parser->parse($memory_map_raw);
        $this->cache[$pid] = ['map' => $map, 'time' => $now];
        return $map;
    }
}
