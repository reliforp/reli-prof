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

use Reli\Lib\String\LineFetcher;

final class ProcessMemoryMapCreator implements ProcessMemoryMapCreatorInterface
{
    public static function create(): self
    {
        return new self(
            new ProcessMemoryMapReader(),
            new ProcessMemoryMapParser(new LineFetcher())
        );
    }

    public function __construct(
        private ProcessMemoryMapReader $memory_map_reader,
        private ProcessMemoryMapParser $memory_map_parser
    ) {
    }

    public function getProcessMemoryMap(int $pid): ProcessMemoryMap
    {
        $memory_map_raw = $this->memory_map_reader->read($pid);
        return $this->memory_map_parser->parse($memory_map_raw);
    }
}
