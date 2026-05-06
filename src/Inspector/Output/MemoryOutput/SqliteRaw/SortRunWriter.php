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
 * File layout (v3 — parallel-array sections so the reader can bulk
 * `unpack` each column in one C call):
 *   [u32 BE record count]
 *   [u32 BE cell_data_size]                        // bytes in the cell-data section
 *   record_count × i64 BE key                      // sort key
 *   record_count × i64 BE rowid                    // tiebreaker
 *   record_count × u32 BE cell_offset              // offset within the cell-data section
 *   cell_data_size bytes                           // concatenated SQLite leaf cell bytes
 *
 * The cell bytes are encoded by the worker against the constructor-
 * supplied `run_id` — the only run_id the resulting index will ever
 * carry — so encoding parallelises across shards and the merger does
 * heap pull + memcpy only.
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
    private string $path;
    private bool $closed = false;

    /**
     * Pre-encoded `run_id` portion of every cell's payload. Since
     * run_id is constant for the lifetime of a sort run, we compute
     * `[type_code_varint, body_bytes]` once in the constructor and
     * reuse them for each appended cell.
     */
    private int $run_id;
    private string $run_id_tc_varint;
    private string $run_id_body;

    /**
     * Parallel buffers for the three column sections. Sized in
     * proportion to the number of records — for our largest index
     * (~700 K rows total / 4 shards ≈ 175 K per shard) the combined
     * buffers are roughly 8.4 MB per writer, well within a worker's
     * PHP memory budget. Cell-data is buffered in $cell_data so we
     * can write the whole file in one shot at close().
     *
     * @var list<int>
     */
    private array $keys = [];
    /** @var list<int> */
    private array $rowids = [];
    /** @var list<int> */
    private array $cell_offsets = [];
    private string $cell_data = '';

    public function __construct(string $path, int $run_id)
    {
        $this->path = $path;

        [$tc, $body] = self::encodeIntegerValue($run_id);
        $this->run_id = $run_id;
        $this->run_id_tc_varint = self::writeVarintShort($tc);
        $this->run_id_body = $body;
    }

    public function append(int $key, int $rowid): void
    {
        $cell = $this->encodeCell($key, $rowid);
        $this->keys[] = $key;
        $this->rowids[] = $rowid;
        $this->cell_offsets[] = strlen($this->cell_data);
        $this->cell_data .= $cell;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $count = count($this->keys);
        $cell_data_size = strlen($this->cell_data);
        $header = pack('N', $count) . pack('N', $cell_data_size);
        // Bulk-pack each column in one C-side `pack(...)` call —
        // J*/N* accept a list and emit the BE bytes in a single
        // pass. The reader uses the symmetric bulk `unpack` to
        // hydrate the columns at construction.
        $keys_bytes = $count > 0 ? pack('J*', ...$this->keys) : '';
        $rowids_bytes = $count > 0 ? pack('J*', ...$this->rowids) : '';
        $offsets_bytes = $count > 0 ? pack('N*', ...$this->cell_offsets) : '';

        $payload = $header . $keys_bytes . $rowids_bytes . $offsets_bytes . $this->cell_data;
        if (file_put_contents($this->path, $payload) === false) {
            throw new \RuntimeException("failed to write sort run file: {$this->path}");
        }
        // Free the buffers so a worker that holds many writers
        // simultaneously isn't pinning their memory after close.
        $this->keys = [];
        $this->rowids = [];
        $this->cell_offsets = [];
        $this->cell_data = '';
        $this->closed = true;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->close();
        }
    }

    /**
     * Encode one (run_id, key, rowid) record as a SQLite leaf cell:
     * `varint(payload_size) + payload`. Specialised for the common
     * shape (key/rowid in int32 range) so the bulk of rows take a
     * single `pack` call; falls through to the general encoder for
     * keys whose magnitude exceeds int32.
     */
    private function encodeCell(int $key, int $rowid): string
    {
        if (
            $key >= -2147483648 && $key <= 2147483647
            && $rowid >= -2147483648 && $rowid <= 2147483647
        ) {
            // tc = 4 for both key and rowid → 4-byte signed BE bodies.
            $inner = $this->run_id_tc_varint . "\x04\x04";
            $header_size = 1 + strlen($inner);
            $payload = chr($header_size) . $inner . $this->run_id_body
                . pack('NN', $key & 0xffffffff, $rowid & 0xffffffff);
            $payload_size = strlen($payload);
            return chr($payload_size) . $payload;
        }
        // Fallback: full encoder (handles 6/8-byte int bodies for
        // outlier keys whose magnitude exceeds int32).
        $payload = RecordEncoder::encodeIntegerRow([$this->run_id, $key, $rowid]);
        return Format::writeVarint(strlen($payload)) . $payload;
    }

    /**
     * @return array{int, string} [type_code, body_bytes]
     */
    private static function encodeIntegerValue(int $v): array
    {
        if ($v === 0) {
            return [8, ''];
        }
        if ($v === 1) {
            return [9, ''];
        }
        if ($v >= -128 && $v <= 127) {
            return [1, pack('c', $v)];
        }
        if ($v >= -32768 && $v <= 32767) {
            return [2, pack('n', $v & 0xffff)];
        }
        if ($v >= -8388608 && $v <= 8388607) {
            $u = $v & 0xffffff;
            return [3, chr(($u >> 16) & 0xff) . chr(($u >> 8) & 0xff) . chr($u & 0xff)];
        }
        if ($v >= -2147483648 && $v <= 2147483647) {
            return [4, pack('N', $v & 0xffffffff)];
        }
        if ($v >= -(1 << 47) && $v <= ((1 << 47) - 1)) {
            $u = $v & 0xffffffffffff;
            return [
                5,
                chr(($u >> 40) & 0xff) . chr(($u >> 32) & 0xff) . chr(($u >> 24) & 0xff)
                . chr(($u >> 16) & 0xff) . chr(($u >> 8) & 0xff) . chr($u & 0xff),
            ];
        }
        return [6, pack('J', $v)];
    }

    /**
     * Single-byte varint emitter — type codes for our three columns
     * are always < 128, so the varint is one byte.
     */
    private static function writeVarintShort(int $v): string
    {
        if ($v < 0 || $v >= 0x80) {
            return Format::writeVarint($v);
        }
        return chr($v);
    }
}
