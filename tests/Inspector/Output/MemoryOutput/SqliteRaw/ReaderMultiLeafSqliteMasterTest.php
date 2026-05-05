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
 * Regression test for the multi-leaf sqlite_master case that surfaced
 * in PR #773's round-2 SQLite-output validation
 * (`docs/internals/pr-773-sqlite-output-validation-round2.md`).
 *
 * Reli's main DB carries 24+ sqlite_master entries (8 tables, 14
 * indexes, 2 views), enough to push page 1 from leaf (type 0x0d) to
 * interior (type 0x05). The original `loadSqliteMaster` short-circuit
 * threw `sqlite_master root is not a leaf (type 0x05); not yet
 * supported` and broke any second `inspector:memory -f sqlite3 -o
 * existing.sqlite3` capture mid-merge. The walk now mirrors
 * `walkTablePages` and recurses through interior pages until each
 * leaf's cells are decoded.
 *
 * @psalm-suppress PossiblyInvalidArrayAccess
 */
class ReaderMultiLeafSqliteMasterTest extends BaseTestCase
{
    public function testFindSchemaEntryWalksMultiLeafSqliteMaster(): void
    {
        // 100 tables × ~110 bytes/cell with the schema below packs
        // ~36 cells/leaf at page_size=4096, so the resulting
        // sqlite_master spans 3+ leaves and forces page 1 to interior.
        $table_count = 100;

        $path = tempnam(sys_get_temp_dir(), 'reli_smaster_test_');
        self::assertNotFalse($path);
        try {
            $db = new \PDO("sqlite:{$path}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA page_size = 4096');
            // VACUUM so the page_size pragma takes effect on the
            // freshly-created file before we start adding entries.
            $db->exec('VACUUM');
            $db->beginTransaction();
            for ($i = 0; $i < $table_count; $i++) {
                $db->exec(
                    "CREATE TABLE t_{$i} (id INTEGER PRIMARY KEY, name TEXT, value INTEGER)"
                );
            }
            $db->commit();
            unset($db);

            // Sanity-check the fixture: page 1 must really be an
            // interior page or this test would degenerate into the
            // single-leaf path that the original code already
            // handled.
            $bytes = file_get_contents($path);
            self::assertNotFalse($bytes);
            $page1_btree_offset = Format::HEADER_SIZE;
            $page1_type = ord($bytes[$page1_btree_offset]);
            self::assertSame(
                Format::BTREE_INTERIOR_TABLE,
                $page1_type,
                sprintf(
                    'fixture: page 1 must be sqlite_master interior (0x%02x) for the test to be meaningful;'
                    . ' got 0x%02x',
                    Format::BTREE_INTERIOR_TABLE,
                    $page1_type,
                ),
            );

            $reader = Reader::open($path);
            for ($i = 0; $i < $table_count; $i++) {
                $entry = $reader->findSchemaEntry("t_{$i}");
                self::assertIsArray(
                    $entry,
                    "t_{$i} must be reachable through the multi-leaf sqlite_master walk",
                );
                self::assertSame('table', $entry['type']);
                self::assertSame("t_{$i}", $entry['name']);
                self::assertSame("t_{$i}", $entry['tbl_name']);
                self::assertGreaterThan(0, $entry['rootpage']);
            }

            // Negative case still works — the walk yields a complete
            // map and missing names return null.
            self::assertNull($reader->findSchemaEntry('does_not_exist'));
        } finally {
            @unlink($path);
        }
    }
}
