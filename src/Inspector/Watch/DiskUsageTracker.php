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

final class DiskUsageTracker
{
    private int $total_bytes = 0;

    public function __construct(
        private int $limit_bytes,
    ) {
    }

    public function recordFile(string $path): void
    {
        $size = filesize($path);
        if ($size !== false) {
            $this->total_bytes += $size;
        }
    }

    public function canWrite(): bool
    {
        return $this->limit_bytes <= 0 || $this->total_bytes < $this->limit_bytes;
    }

    public function getTotalBytes(): int
    {
        return $this->total_bytes;
    }

    public function getLimitBytes(): int
    {
        return $this->limit_bytes;
    }
}
