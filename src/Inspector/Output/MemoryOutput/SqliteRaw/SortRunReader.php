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
 * Streaming reader for a SortRunWriter v2 file. Whole file is loaded
 * once and decoded into three parallel arrays (keys, rowids, cells)
 * up front; for our use case sort runs are bounded by shard size and
 * live for the duration of the merge.
 *
 * Decoding is one O(N) pass that resolves variable-length cell offsets
 * — the file format itself is variable-length per record, but at peek
 * time we only do array-index lookups, no per-record substr/unpack.
 *
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress PossiblyInvalidArrayAccess
 */
final class SortRunReader
{
    private int $count;
    private int $cursor = 0;
    /** @var list<int> */
    private array $keys;
    /** @var list<int> */
    private array $rowids;
    /** @var list<string> */
    private array $cells;

    public function __construct(string $path)
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) < 4) {
            throw new \RuntimeException("failed to open sort run file for read: {$path}");
        }
        $this->count = (int)unpack('N', substr($bytes, 0, 4))[1];

        $keys = [];
        $rowids = [];
        $cells = [];
        $cursor = 4;
        $total = strlen($bytes);
        for ($i = 0; $i < $this->count; $i++) {
            if ($cursor + 17 > $total) {
                throw new \RuntimeException(
                    "sort run file truncated at record {$i} (offset {$cursor})"
                );
            }
            $r = unpack('Jkey/Jrowid/Clen', substr($bytes, $cursor, 17));
            $cursor += 17;
            $cell_len = (int)$r['len'];
            if ($cursor + $cell_len > $total) {
                throw new \RuntimeException(
                    "sort run file cell truncated at record {$i}"
                );
            }
            $keys[] = (int)$r['key'];
            $rowids[] = (int)$r['rowid'];
            $cells[] = substr($bytes, $cursor, $cell_len);
            $cursor += $cell_len;
        }
        if ($cursor !== $total) {
            throw new \RuntimeException(
                "sort run file has trailing garbage: parsed {$cursor} bytes, file is {$total}"
            );
        }
        $this->keys = $keys;
        $this->rowids = $rowids;
        $this->cells = $cells;
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
        $i = $this->cursor;
        return [$this->keys[$i], $this->rowids[$i], $this->cells[$i]];
    }

    public function advance(): void
    {
        $this->cursor++;
    }
}
