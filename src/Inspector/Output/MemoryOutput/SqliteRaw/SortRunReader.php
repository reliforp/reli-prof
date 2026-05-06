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
 * Streaming reader for a SortRunWriter v3 file.
 *
 * The file lays each column out in its own contiguous section so we
 * can bulk-`unpack` keys / rowids / cell offsets in three C-side
 * calls instead of N small per-record substr+unpack. Cell bytes are
 * concatenated in a single trailing section; per-record cell length
 * is `cell_offsets[i+1] - cell_offsets[i]` (or
 * `cell_data_size - cell_offsets[last]` for the last record), so we
 * never have to store an explicit length array.
 *
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress PossiblyInvalidArrayAccess
 * @psalm-suppress MixedReturnTypeCoercion
 */
final class SortRunReader
{
    private int $count;
    private int $cursor = 0;
    /** @var array<int, int> 1-indexed (PHP unpack convention) */
    private array $keys;
    /** @var array<int, int> 1-indexed */
    private array $rowids;
    /** @var array<int, int> 1-indexed */
    private array $cell_offsets;
    private string $cell_data;
    private int $cell_data_size;

    public function __construct(string $path)
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) < 8) {
            throw new \RuntimeException("failed to open sort run file for read: {$path}");
        }
        $count = (int)unpack('N', substr($bytes, 0, 4))[1];
        $cell_data_size = (int)unpack('N', substr($bytes, 4, 4))[1];
        $this->count = $count;
        $this->cell_data_size = $cell_data_size;

        $expected = 8 + $count * 8 + $count * 8 + $count * 4 + $cell_data_size;
        if (strlen($bytes) !== $expected) {
            throw new \RuntimeException(
                "sort run file size mismatch: expected {$expected}, got " . strlen($bytes)
            );
        }

        if ($count > 0) {
            $keys_off = 8;
            $rowids_off = $keys_off + $count * 8;
            $offsets_off = $rowids_off + $count * 8;
            $cells_off = $offsets_off + $count * 4;
            /** @var array<int, int> $keys */
            $keys = unpack("J{$count}", $bytes, $keys_off);
            /** @var array<int, int> $rowids */
            $rowids = unpack("J{$count}", $bytes, $rowids_off);
            /** @var array<int, int> $cell_offsets */
            $cell_offsets = unpack("N{$count}", $bytes, $offsets_off);
            $this->keys = $keys;
            $this->rowids = $rowids;
            $this->cell_offsets = $cell_offsets;
            $this->cell_data = substr($bytes, $cells_off);
        } else {
            $this->keys = [];
            $this->rowids = [];
            $this->cell_offsets = [];
            $this->cell_data = '';
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
     * Peek the current (key, rowid, cell). Does not advance the cursor.
     *
     * The cell is the SQLite leaf cell bytes (varint(payload_size) +
     * payload), pre-encoded by the worker against a known run_id —
     * the merger appends it verbatim into the resulting leaf.
     *
     * @return array{int, int, string}
     */
    public function peek(): array
    {
        if (!$this->hasMore()) {
            throw new \RuntimeException('sort run reader is exhausted');
        }
        // unpack arrays are 1-indexed.
        $i1 = $this->cursor + 1;
        $offset = $this->cell_offsets[$i1];
        $next_offset = $i1 < $this->count
            ? $this->cell_offsets[$i1 + 1]
            : $this->cell_data_size;
        $cell = substr($this->cell_data, $offset, $next_offset - $offset);
        return [$this->keys[$i1], $this->rowids[$i1], $cell];
    }

    public function advance(): void
    {
        $this->cursor++;
    }
}
