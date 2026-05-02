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

/**
 * Resolve a remote-process address to the loaded-module name + executable
 * region status the address falls in.
 *
 * Kept narrow on purpose so detectors don't depend on the full
 * {@see \Reli\Lib\Process\MemoryMap\ProcessMemoryMap} API and tests can
 * stand up trivial fakes. The default implementation
 * {@see ProcessMemoryMapModuleResolver} adapts a real `ProcessMemoryMap`.
 */
interface ModuleResolverInterface
{
    /**
     * Basename of the loaded module the address falls in (e.g. `libphp.so`,
     * `libuv.so.1`). Returns null when the address isn't in any named
     * module — anonymous mappings, JIT pages, or unknown ranges.
     */
    public function moduleBasenameFor(int $address): ?string;

    /**
     * True when the address sits inside any executable region of the
     * target's memory map. The basis for the design's
     * FunctionPointerDetector — a value that points into `.text` is
     * almost certainly a function pointer, not random data.
     */
    public function isExecutable(int $address): bool;
}
