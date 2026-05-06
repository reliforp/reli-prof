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

use Reli\BaseTestCase;

/**
 * The integer index L stage builds index b-tree leaf cells whose
 * payload is a SQLite record. If the encoder is wrong by a byte, the
 * resulting index reads as garbage and silently corrupts query
 * results. These tests pin the type-code transitions per
 * `https://www.sqlite.org/fileformat.html#record_format` and add a
 * round-trip cross-check against SQLite's own encoder by populating
 * a fresh table and reading the cell payload SQLite stored.
 */
class RecordEncoderTest extends BaseTestCase
{
    public function testTypeCodeTransitions(): void
    {
        // Special encodings: 8 for 0, 9 for 1.
        // Header is varint(header_size) + varint(type_code_for_each_col).
        // For 2 columns of 1-byte type codes: header_size = 3 bytes.
        self::assertSame("\x03\x08\x09", RecordEncoder::encodeIntegerRow([0, 1]));

        // Single column tests: header_size = 2 (1 size byte + 1 type code byte).
        // 1-byte signed (-128..127)
        self::assertSame("\x02\x01\x7f", RecordEncoder::encodeIntegerRow([127]));
        self::assertSame("\x02\x01\x80", RecordEncoder::encodeIntegerRow([-128]));
        // 2-byte signed (-32768..32767)
        self::assertSame("\x02\x02\x7f\xff", RecordEncoder::encodeIntegerRow([32767]));
        self::assertSame("\x02\x02\x80\x00", RecordEncoder::encodeIntegerRow([-32768]));
        // 3-byte signed
        self::assertSame("\x02\x03\x7f\xff\xff", RecordEncoder::encodeIntegerRow([8388607]));
        // 4-byte signed
        self::assertSame("\x02\x04\x7f\xff\xff\xff", RecordEncoder::encodeIntegerRow([2147483647]));
        // NULL → type code 0, no body.
        self::assertSame("\x02\x00", RecordEncoder::encodeIntegerRow([null]));
    }

    public function testMatchesSqliteOnDiskEncoding(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'rec_');
        self::assertNotFalse($base);
        $tmp = $base . '.db';
        try {
            $db = new \PDO("sqlite:{$tmp}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA page_size = 4096');
            $db->exec('CREATE TABLE t (a INTEGER, b INTEGER, c INTEGER)');
            $db->exec('INSERT INTO t (rowid, a, b, c) VALUES (1, 0, 1, 100)');
            $db->exec('INSERT INTO t (rowid, a, b, c) VALUES (2, 12345, 1000000, 0)');
            $db->exec('INSERT INTO t (rowid, a, b, c) VALUES (3, NULL, -128, 2147483647)');
            unset($db);

            $bytes = (string)file_get_contents($tmp);
            // Page 2 is the rootpage of t (page 1 is sqlite_master).
            $page = substr($bytes, 4096, 4096);
            $cells = $this->parseTableLeafCells($page);
            self::assertCount(3, $cells);

            $expectations = [
                [0, 1, 100],
                [12345, 1000000, 0],
                [null, -128, 2147483647],
            ];
            foreach ($expectations as $i => $row) {
                self::assertSame(
                    bin2hex(RecordEncoder::encodeIntegerRow($row)),
                    bin2hex($cells[$i]),
                    "cell {$i} payload bytes diverge from SQLite's on-disk encoding",
                );
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Parse a table leaf b-tree page and return each cell's record
     * payload bytes (the part after `varint payload_size, varint rowid`).
     *
     * @return list<string>
     */
    private function parseTableLeafCells(string $page): array
    {
        $cell_count = (int)(unpack('n', substr($page, 3, 2))[1] ?? 0);
        $cell_pointer_array_offset = 8;
        $payloads = [];
        for ($i = 0; $i < $cell_count; $i++) {
            $cell_offset = (int)(unpack('n', substr($page, $cell_pointer_array_offset + $i * 2, 2))[1] ?? 0);
            [$payload_size, $sz_len] = Format::readVarint($page, $cell_offset);
            [$_rowid, $rowid_len] = Format::readVarint($page, $cell_offset + $sz_len);
            $payload_offset = $cell_offset + $sz_len + $rowid_len;
            $payloads[] = substr($page, $payload_offset, $payload_size);
        }
        return $payloads;
    }
}
