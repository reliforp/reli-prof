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

final class SqliteDriver implements PdoDriverInterface
{
    public function __construct(
        private string $path,
    ) {
    }

    public function createConnection(): \PDO
    {
        $db = new \PDO('sqlite:' . $this->path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    public function insertIgnoreSql(string $table, string $columns, string $placeholders): string
    {
        return "INSERT OR IGNORE INTO {$table} ({$columns}) VALUES ({$placeholders})";
    }

    public function concatExpr(string $a, string $b): string
    {
        return "{$a} || {$b}";
    }

    public function autoIncrementPrimaryKey(): string
    {
        return 'INTEGER PRIMARY KEY';
    }

    public function tuneForBulkInsert(\PDO $db): void
    {
        $db->exec('PRAGMA journal_mode=OFF');
        $db->exec('PRAGMA synchronous=OFF');
        $db->exec('PRAGMA cache_size=-65536');
        $db->exec('PRAGMA temp_store=MEMORY');
        $db->exec('PRAGMA locking_mode=EXCLUSIVE');
        $db->exec('PRAGMA mmap_size=268435456');
    }

    public function afterBulkInsert(\PDO $db): void
    {
    }
}
