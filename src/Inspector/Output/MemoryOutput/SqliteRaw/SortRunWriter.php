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

namespace Reli\Inspector\Output\MemoryOutput\SqliteRaw;

/**
 * Per-shard sort-run file for a single integer index.
 *
 * File layout:
 *   [u32 BE record count]
 *   record_count × [i64 BE key, i64 BE rowid]
 *
 * Records must be appended in (key, rowid) ascending order — the
 * worker is expected to extract them in sorted order via
 * `SELECT key, rowid FROM table ORDER BY key, rowid` so the merger
 * can do a streaming k-way merge.
 *
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedAssignment
 * @psalm-suppress InvalidPropertyAssignmentValue
 */
final class SortRunWriter
{
    /** @var resource */
    private $fh;
    private int $count = 0;
    private bool $closed = false;

    public function __construct(string $path)
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("failed to open sort run file for write: {$path}");
        }
        $this->fh = $fh;
        // Reserve 4 bytes for the record count; rewritten at close.
        fwrite($this->fh, "\x00\x00\x00\x00");
    }

    public function append(int $key, int $rowid): void
    {
        fwrite($this->fh, pack('JJ', $key, $rowid));
        $this->count++;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        fflush($this->fh);
        rewind($this->fh);
        fwrite($this->fh, pack('N', $this->count));
        fclose($this->fh);
        $this->closed = true;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->close();
        }
    }
}
