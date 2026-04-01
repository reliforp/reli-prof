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

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

/**
 * Consistent human-readable byte size formatting.
 *
 * < 1 KB  → "999 B"
 * < 1 MB  → "15.2 KB"
 * < 1 GB  → "153.76 MB"
 * >= 1 GB → "1.23 GB"
 */
final class SizeFormatter
{
    /** @psalm-suppress InvalidOperand */
    public static function format(int|float $bytes): string
    {
        $bytes = (float)$bytes;
        if ($bytes < 1024) {
            return number_format($bytes, 0) . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 2) . ' MB';
        }
        return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
    }
}
