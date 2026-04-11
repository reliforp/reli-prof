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

final class PostgreSqlDriver implements PdoDriverInterface
{
    public function __construct(
        private string $host,
        private int $port,
        private string $database,
        private string $user,
        private string $password,
    ) {
    }

    #[\Override]
    public function createConnection(): \PDO
    {
        $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->database}";
        $db = new \PDO($dsn, $this->user, $this->password);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    #[\Override]
    public function insertIgnoreSql(string $table, string $columns, string $placeholders): string
    {
        return "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders}) ON CONFLICT DO NOTHING";
    }

    #[\Override]
    public function batchInsertIgnoreSql(
        string $table,
        string $columns,
        string $row_placeholders,
        int $row_count,
    ): string {
        $rows = implode(',', array_fill(0, $row_count, "({$row_placeholders})"));
        return "INSERT INTO {$table} ({$columns}) VALUES {$rows} ON CONFLICT DO NOTHING";
    }

    #[\Override]
    public function concatExpr(string $a, string $b): string
    {
        return "{$a} || {$b}";
    }

    #[\Override]
    public function autoIncrementPrimaryKey(): string
    {
        return 'SERIAL PRIMARY KEY';
    }

    #[\Override]
    public function tuneForBulkInsert(\PDO $db): void
    {
        $db->exec('SET synchronous_commit=off');
    }

    #[\Override]
    public function afterBulkInsert(\PDO $db): void
    {
        $db->exec('SET synchronous_commit=on');
    }

    #[\Override]
    public function createViewSql(string $view_name): string
    {
        return "CREATE OR REPLACE VIEW {$view_name} AS";
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
