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
 * Streaming reader for a SortRunWriter file. Whole file is loaded
 * into memory once at construction; for our use case sort runs are
 * bounded by shard size and live for the duration of the merge.
 *
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress PossiblyInvalidArrayAccess
 */
final class SortRunReader
{
    private string $bytes;
    private int $count;
    private int $cursor = 0;

    public function __construct(string $path)
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) < 4) {
            throw new \RuntimeException("failed to open sort run file for read: {$path}");
        }
        $this->bytes = $bytes;
        $this->count = (int)unpack('N', substr($bytes, 0, 4))[1];
        $expected_size = 4 + $this->count * 16;
        if (strlen($bytes) !== $expected_size) {
            throw new \RuntimeException(
                "sort run file truncated: expected {$expected_size}, got " . strlen($bytes)
            );
        }
    }

    public function count(): int
    {
        return $this->count;
    }

    public function hasMore(): bool
    {
        return $this->cursor < $this->count;
    }

    /**
     * Peek the current (key, rowid). Does not advance the cursor.
     *
     * @return array{int, int}
     */
    public function peek(): array
    {
        if (!$this->hasMore()) {
            throw new \RuntimeException('sort run reader is exhausted');
        }
        $offset = 4 + $this->cursor * 16;
        $r = unpack('Jkey/Jrowid', substr($this->bytes, $offset, 16));
        return [(int)$r['key'], (int)$r['rowid']];
    }

    public function advance(): void
    {
        $this->cursor++;
    }
}
