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

class MySqlDriverTest extends BaseTestCase
{
    public function testInsertIgnoreSql(): void
    {
        $driver = new MySqlDriver('localhost', 3306, 'test', 'root', '');
        $this->assertSame(
            'INSERT IGNORE INTO my_table (col1, col2) VALUES (?, ?)',
            $driver->insertIgnoreSql('my_table', 'col1, col2', '?, ?'),
        );
    }

    public function testConcatExpr(): void
    {
        $driver = new MySqlDriver('localhost', 3306, 'test', 'root', '');
        $this->assertSame('CONCAT(a, b)', $driver->concatExpr('a', 'b'));
    }

    public function testAutoIncrementPrimaryKey(): void
    {
        $driver = new MySqlDriver('localhost', 3306, 'test', 'root', '');
        $this->assertSame('INTEGER PRIMARY KEY AUTO_INCREMENT', $driver->autoIncrementPrimaryKey());
    }

    public function testCreateViewSql(): void
    {
        $driver = new MySqlDriver('localhost', 3306, 'test', 'root', '');
        $this->assertSame(
            'CREATE OR REPLACE VIEW my_view AS',
            $driver->createViewSql('my_view'),
        );
    }

    public function testQuoteIdentifier(): void
    {
        $driver = new MySqlDriver('localhost', 3306, 'test', 'root', '');
        $this->assertSame('`key`', $driver->quoteIdentifier('key'));
        $this->assertSame('`value`', $driver->quoteIdentifier('value'));
    }

    public function testPrimaryKeyTextType(): void
    {
        $driver = new MySqlDriver('localhost', 3306, 'test', 'root', '');
        $this->assertSame('VARCHAR(255)', $driver->primaryKeyTextType());
    }

    public function testCastAsInteger(): void
    {
        $driver = new MySqlDriver('localhost', 3306, 'test', 'root', '');
        $this->assertSame('CAST(x AS SIGNED)', $driver->castAsInteger('x'));
    }
}
