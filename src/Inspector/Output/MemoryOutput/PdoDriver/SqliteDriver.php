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

    #[\Override]
    public function createConnection(): \PDO
    {
        $db = new \PDO('sqlite:' . $this->path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    #[\Override]
    public function insertIgnoreSql(string $table, string $columns, string $placeholders): string
    {
        return "INSERT OR IGNORE INTO {$table} ({$columns}) VALUES ({$placeholders})";
    }

    #[\Override]
    public function concatExpr(string $a, string $b): string
    {
        return "{$a} || {$b}";
    }

    #[\Override]
    public function autoIncrementPrimaryKey(): string
    {
        return 'INTEGER PRIMARY KEY';
    }

    #[\Override]
    public function tuneForBulkInsert(\PDO $db): void
    {
        $db->exec('PRAGMA journal_mode=OFF');
        $db->exec('PRAGMA synchronous=OFF');
        $db->exec('PRAGMA cache_size=-65536');
        $db->exec('PRAGMA temp_store=MEMORY');
        $db->exec('PRAGMA locking_mode=EXCLUSIVE');
        $db->exec('PRAGMA mmap_size=268435456');
    }

    #[\Override]
    public function afterBulkInsert(\PDO $db): void
    {
    }

    #[\Override]
    public function createViewSql(string $view_name): string
    {
        return "CREATE VIEW IF NOT EXISTS {$view_name} AS";
    }

    #[\Override]
    public function quoteIdentifier(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    #[\Override]
    public function primaryKeyTextType(): string
    {
        return 'TEXT';
    }

    #[\Override]
    public function castAsInteger(string $expr): string
    {
        return "CAST({$expr} AS INTEGER)";
    }

    #[\Override]
    public function tuneForParallelInsert(\PDO $db): void
    {
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');
        $db->exec('PRAGMA cache_size=-65536');
        $db->exec('PRAGMA temp_store=MEMORY');
        $db->exec('PRAGMA mmap_size=268435456');
        $db->exec('PRAGMA busy_timeout=60000');
    }
}
