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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector;

use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;

/**
 * {@see ModuleResolverInterface} backed by a {@see ProcessMemoryMap} —
 * the live-target case populates this from `/proc/<pid>/maps`, the
 * rdump case from the map embedded in the dump file. Either way the
 * detector layer stays oblivious to the source.
 */
final class ProcessMemoryMapModuleResolver implements ModuleResolverInterface
{
    public function __construct(
        private ProcessMemoryMap $memory_map,
    ) {
    }

    #[\Override]
    public function moduleBasenameFor(int $address): ?string
    {
        foreach ($this->memory_map->findByAddress($address) as $area) {
            $name = $area->name;
            if ($name === '' || $name[0] === '[') {
                // Anonymous mappings ([heap], [stack], [vdso], …)
                // aren't a meaningful module label.
                continue;
            }
            return basename($name);
        }
        return null;
    }

    #[\Override]
    public function isExecutable(int $address): bool
    {
        foreach ($this->memory_map->findByAddress($address) as $area) {
            if ($area->attribute->execute) {
                return true;
            }
        }
        return false;
    }
}
