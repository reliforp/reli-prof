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

namespace Reli\Inspector\Watch;

final class HeapStats
{
    public function __construct(
        public readonly int $size,
        public readonly int $real_size,
        public readonly int $peak,
        public readonly int $limit,
        public readonly int $real_peak = 0,
    ) {
    }

    public function formatSize(): string
    {
        return self::humanReadableBytes($this->size);
    }

    public static function humanReadableBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return sprintf('%.1fG', $bytes / (1024 * 1024 * 1024));
        }
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1fM', $bytes / (1024 * 1024));
        }
        if ($bytes >= 1024) {
            return sprintf('%.1fK', $bytes / 1024);
        }
        return $bytes . 'B';
    }

    public static function parseSize(string $size): int
    {
        $size = trim($size);
        $last = strtoupper(substr($size, -1));
        $value = (float)substr($size, 0, -1);
        return match ($last) {
            'G' => (int)((int)$value * 1024 * 1024 * 1024),
            'M' => (int)((int)$value * 1024 * 1024),
            'K' => (int)((int)$value * 1024),
            default => (int)$size,
        };
    }
}
