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
 * Resolve a remote-process address to a symbol name + offset within that
 * symbol — `["execute_ex", 0x123]` style.
 *
 * Companion to {@see ModuleResolverInterface}: the module resolver
 * answers "which library?", this one answers "which function?".
 * Detectors should consult both; symbol-level info promotes a
 * {@see FunctionPointerDetector} verdict from "func_ptr (libphp.so)"
 * to "func_ptr (libphp.so:execute_ex+0x123)" — the validator's
 * "is this an opcode handler or a libuv callback?" question landing
 * with one row of compare output.
 *
 * Implementations are allowed to be slow on first call (the default
 * adapter parses each module's ELF symbol table) but should cache.
 */
interface SymbolResolverInterface
{
    /**
     * @return array{0: string, 1: int}|null `[symbol_name, offset]`
     *     where offset is the byte distance from the symbol start.
     *     Null when the address doesn't fall inside any known symbol.
     */
    public function resolve(int $address): ?array;
}
