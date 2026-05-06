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
 * The shared-mmap parallel writer assembles table-leaf cells in PHP
 * and memcpys them into the main DB file. A one-byte divergence from
 * SQLite's on-disk encoding silently corrupts the b-tree, so we pin
 * the per-type encodings and cross-check the full cell bytes against
 * what SQLite itself writes for an equivalent INSERT.
 */
class TableCellEncoderTest extends BaseTestCase
{
    public function testRecordTypeCodes(): void
    {
        // [NULL, 1] → header(varint size=3)(0)(9), body empty.
        self::assertSame("\x03\x00\x09", TableCellEncoder::encodeRecord([null, 1]));

        // TEXT: type code = 13 + 2 * length. "abc" → 13 + 6 = 19 (0x13).
        // Header: size=2, type=0x13. Body: "abc".
        self::assertSame("\x02\x13abc", TableCellEncoder::encodeRecord(['abc']));

        // Empty string: type = 13. Body length 0.
        self::assertSame("\x02\x0d", TableCellEncoder::encodeRecord(['']));

        // Mixed: [42, "x", null]. 42 fits in 1 signed byte (type=1).
        // "x" → 13 + 2 = 15 (0x0f). null → 0.
        // Header size = 4 (size byte + 3 type codes). Body = 0x2a + 'x'.
        self::assertSame("\x04\x01\x0f\x00\x2ax", TableCellEncoder::encodeRecord([42, 'x', null]));
    }

    public function testCellPrefixesPayloadSizeAndRowid(): void
    {
        // Single integer column 0 → record is "\x02\x08" (2 bytes).
        // Cell: varint(2), varint(rowid), payload.
        $cell = TableCellEncoder::encodeTableLeafCell(7, [0]);
        self::assertSame("\x02\x07\x02\x08", $cell);
    }

    public function testMatchesSqliteOnDiskEncodingForMixedTypes(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'tcell_');
        self::assertNotFalse($base);
        $tmp = $base . '.db';
        try {
            $db = new \PDO("sqlite:{$tmp}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA page_size = 4096');
            $db->exec('CREATE TABLE t (a INTEGER, b TEXT, c INTEGER)');
            $stmt = $db->prepare('INSERT INTO t (rowid, a, b, c) VALUES (?, ?, ?, ?)');
            $rows = [
                [1, 0, '', 1],
                [2, 12345, 'hello', 100],
                [3, null, 'unicode: αβγ', -128],
                [4, 2147483647, str_repeat('x', 50), null],
            ];
            foreach ($rows as $row) {
                $stmt->execute($row);
            }
            unset($stmt, $db);

            $bytes = (string)file_get_contents($tmp);
            // Page 2 is the rootpage of t (page 1 is sqlite_master).
            $page = substr($bytes, 4096, 4096);
            $cells = $this->parseTableLeafCellsFull($page);
            self::assertCount(count($rows), $cells);

            foreach ($rows as $i => $row) {
                [$rowid, $a, $b, $c] = $row;
                self::assertSame(
                    bin2hex(TableCellEncoder::encodeTableLeafCell($rowid, [$a, $b, $c])),
                    bin2hex($cells[$i]),
                    "cell {$i} bytes diverge from SQLite's on-disk encoding",
                );
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Parse a table leaf b-tree page and return the full cell bytes
     * for each cell (varint payload_size + varint rowid + payload).
     *
     * @return list<string>
     */
    private function parseTableLeafCellsFull(string $page): array
    {
        $cell_count = (int)(unpack('n', substr($page, 3, 2))[1] ?? 0);
        $cell_pointer_array_offset = 8;
        $cells = [];
        for ($i = 0; $i < $cell_count; $i++) {
            $cell_offset = (int)(unpack('n', substr($page, $cell_pointer_array_offset + $i * 2, 2))[1] ?? 0);
            [$payload_size, $sz_len] = Format::readVarint($page, $cell_offset);
            [$_rowid, $rowid_len] = Format::readVarint($page, $cell_offset + $sz_len);
            $cells[] = substr($page, $cell_offset, $sz_len + $rowid_len + $payload_size);
        }
        return $cells;
    }
}
