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
 * Side-channel data the bin walker shares with detectors that need
 * more than the slot's bytes.
 *
 * Built once per analyze run by {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\ZendMmBinWalker}
 * from the target's process memory map (live target or embedded in the
 * rdump). Detectors that don't need it (Bucket, ZendString, Stat) ignore
 * the parameter; {@see FunctionPointerDetector} requires it.
 *
 * Fields are read-only and may all be null — detectors must handle that.
 */
final class DetectorContext
{
    public function __construct(
        public readonly ?ModuleResolverInterface $module_resolver = null,
        public readonly ?SymbolResolverInterface $symbol_resolver = null,
    ) {
    }
}
