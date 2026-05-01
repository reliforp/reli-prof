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

    /**
     * Filesystem path of the SQLite database. Used by the format-direct
     * shard merger to open the file for raw page-level I/O.
     */
    public function path(): string
    {
        return $this->path;
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
    public function batchInsertIgnoreSql(
        string $table,
        string $columns,
        string $row_placeholders,
        int $row_count,
    ): string {
        $rows = implode(',', array_fill(0, $row_count, "({$row_placeholders})"));
        return "INSERT OR IGNORE INTO {$table} ({$columns}) VALUES {$rows}";
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
}
