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

class PostgreSqlDriverTest extends BaseTestCase
{
    public function testInsertIgnoreSql(): void
    {
        $driver = new PostgreSqlDriver('localhost', 5432, 'test', 'postgres', '');
        $this->assertSame(
            'INSERT INTO my_table (col1, col2) VALUES (?, ?) ON CONFLICT DO NOTHING',
            $driver->insertIgnoreSql('my_table', 'col1, col2', '?, ?'),
        );
    }

    public function testConcatExpr(): void
    {
        $driver = new PostgreSqlDriver('localhost', 5432, 'test', 'postgres', '');
        $this->assertSame('a || b', $driver->concatExpr('a', 'b'));
    }

    public function testAutoIncrementPrimaryKey(): void
    {
        $driver = new PostgreSqlDriver('localhost', 5432, 'test', 'postgres', '');
        $this->assertSame('SERIAL PRIMARY KEY', $driver->autoIncrementPrimaryKey());
    }

    public function testCreateViewSql(): void
    {
        $driver = new PostgreSqlDriver('localhost', 5432, 'test', 'postgres', '');
        $this->assertSame(
            'CREATE OR REPLACE VIEW my_view AS',
            $driver->createViewSql('my_view'),
        );
    }

    public function testQuoteIdentifier(): void
    {
        $driver = new PostgreSqlDriver('localhost', 5432, 'test', 'postgres', '');
        $this->assertSame('"key"', $driver->quoteIdentifier('key'));
        $this->assertSame('"value"', $driver->quoteIdentifier('value'));
    }

    public function testPrimaryKeyTextType(): void
    {
        $driver = new PostgreSqlDriver('localhost', 5432, 'test', 'postgres', '');
        $this->assertSame('TEXT', $driver->primaryKeyTextType());
    }

    public function testCastAsInteger(): void
    {
        $driver = new PostgreSqlDriver('localhost', 5432, 'test', 'postgres', '');
        $this->assertSame('CAST(x AS INTEGER)', $driver->castAsInteger('x'));
    }
}
