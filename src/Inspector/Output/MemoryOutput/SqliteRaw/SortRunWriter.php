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
 * File layout (v2 — cells pre-encoded by the worker):
 *   [u32 BE record count]
 *   record_count × [
 *     i64 BE key,          // sort key
 *     i64 BE rowid,        // tiebreaker
 *     u8 cell_len,         // bytes in the encoded cell that follows
 *     cell_len bytes cell  // SQLite leaf cell: varint(payload_size) + record_payload
 *   ]
 *
 * The cell is encoded by the worker against the freshly-allocated
 * `run_id` passed to the constructor — that is the only run_id the
 * resulting index will ever carry, so encoding it once per worker (in
 * parallel) is strictly cheaper than the previous scheme where main
 * called `RecordEncoder::encodeIntegerRow([$run_id, $key, $rowid])`
 * per row in the k-way merge. The merger now does heap pull + memcpy
 * only and the per-row record-encoding cost moves off the critical
 * path entirely.
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
     * Output buffer: cells accumulate here and get flushed in 64 KiB
     * chunks. fwrite once per row over hundreds of thousands of rows
     * pays a fixed per-call overhead even with PHP's 8 KiB stream
     * buffer in front; one flush per ~64 KiB amortises that to noise.
     */
    private const BUFFER_FLUSH_BYTES = 65536;
    private string $buffer = '';

    public function __construct(string $path, int $run_id)
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("failed to open sort run file for write: {$path}");
        }
        $this->fh = $fh;
        // Reserve 4 bytes for the record count; rewritten at close.
        fwrite($this->fh, "\x00\x00\x00\x00");

        [$tc, $body] = self::encodeIntegerValue($run_id);
        $this->run_id = $run_id;
        $this->run_id_tc_varint = self::writeVarintShort($tc);
        $this->run_id_body = $body;
    }

    public function append(int $key, int $rowid): void
    {
        $cell = $this->encodeCell($key, $rowid);
        $cell_len = strlen($cell);
        if ($cell_len > 255) {
            // Three int64 columns plus the small constant-bytes overhead
            // never reaches 256; if it does we've encoded something we
            // didn't expect and the file format would silently truncate.
            throw new \LengthException(
                "encoded cell length {$cell_len} exceeds u8 range — "
                . "integer index payload is not three small ints"
            );
        }
        $this->buffer .= pack('JJ', $key, $rowid) . chr($cell_len) . $cell;
        $this->count++;
        if (strlen($this->buffer) >= self::BUFFER_FLUSH_BYTES) {
            fwrite($this->fh, $this->buffer);
            $this->buffer = '';
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->buffer !== '') {
            fwrite($this->fh, $this->buffer);
            $this->buffer = '';
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

    /**
     * Encode one (run_id, key, rowid) record as a SQLite leaf cell:
     * `varint(payload_size) + payload`. Specialised for the common
     * shape (key/rowid in int32 range) so the bulk of rows take a
     * single `pack` call; falls through to the general encoder for
     * keys whose magnitude exceeds int32 (rare in our schema —
     * node_id values comfortably fit, addresses don't but addresses
     * never key these three indexes).
     */
    private function encodeCell(int $key, int $rowid): string
    {
        if (
            $key >= -2147483648 && $key <= 2147483647
            && $rowid >= -2147483648 && $rowid <= 2147483647
        ) {
            // tc = 4 for both key and rowid → 4-byte signed BE bodies.
            // header_inner = run_id_tc_varint + "\x04\x04"
            // header_size  = 1 + strlen(run_id_tc_varint) + 2
            //              = strlen(run_id_tc_varint) + 3 (always 4 for our run_ids)
            // payload      = varint(header_size) + header_inner + run_id_body + key + rowid
            $inner = $this->run_id_tc_varint . "\x04\x04";
            $header_size = 1 + strlen($inner);
            $payload = chr($header_size) . $inner . $this->run_id_body
                . pack('NN', $key & 0xffffffff, $rowid & 0xffffffff);
            $payload_size = strlen($payload);
            // payload_size is always small (< 32) for our 3-int rows
            // — single-byte varint covers everything.
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
            // Defensive: type codes 0..9 cover everything we emit;
            // anything larger is a bug in the caller.
            return Format::writeVarint($v);
        }
        return chr($v);
    }
}
