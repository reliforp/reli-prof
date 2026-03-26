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

namespace Reli\Inspector\Output\MemoryOutput\PdoDriver;

use Reli\BaseTestCase;

class SqliteDriverTest extends BaseTestCase
{
    public function testCreateConnection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_test_') . '.db';
        try {
            $driver = new SqliteDriver($path);
            $db = $driver->createConnection();
            $this->assertInstanceOf(\PDO::class, $db);
            $this->assertSame(
                \PDO::ERRMODE_EXCEPTION,
                $db->getAttribute(\PDO::ATTR_ERRMODE),
            );
        } finally {
            @unlink($path);
        }
    }

    public function testInsertIgnoreSql(): void
    {
        $driver = new SqliteDriver(':memory:');
        $this->assertSame(
            'INSERT OR IGNORE INTO my_table (col1, col2) VALUES (?, ?)',
            $driver->insertIgnoreSql('my_table', 'col1, col2', '?, ?'),
        );
    }

    public function testConcatExpr(): void
    {
        $driver = new SqliteDriver(':memory:');
        $this->assertSame('a || b', $driver->concatExpr('a', 'b'));
    }

    public function testAutoIncrementPrimaryKey(): void
    {
        $driver = new SqliteDriver(':memory:');
        $this->assertSame('INTEGER PRIMARY KEY', $driver->autoIncrementPrimaryKey());
    }

    public function testTuneForBulkInsert(): void
    {
        $driver = new SqliteDriver(':memory:');
        $db = $driver->createConnection();
        $driver->tuneForBulkInsert($db);
        $journal = $db->query('PRAGMA journal_mode')->fetchColumn();
        $this->assertSame('off', strtolower($journal));
    }

    public function testCreateViewSql(): void
    {
        $driver = new SqliteDriver(':memory:');
        $this->assertSame(
            'CREATE VIEW IF NOT EXISTS my_view AS',
            $driver->createViewSql('my_view'),
        );
    }

    public function testQuoteIdentifier(): void
    {
        $driver = new SqliteDriver(':memory:');
        $this->assertSame('"key"', $driver->quoteIdentifier('key'));
        $this->assertSame('"value"', $driver->quoteIdentifier('value'));
    }

    public function testPrimaryKeyTextType(): void
    {
        $driver = new SqliteDriver(':memory:');
        $this->assertSame('TEXT', $driver->primaryKeyTextType());
    }

    public function testCastAsInteger(): void
    {
        $driver = new SqliteDriver(':memory:');
        $this->assertSame('CAST(x AS INTEGER)', $driver->castAsInteger('x'));
    }
}
