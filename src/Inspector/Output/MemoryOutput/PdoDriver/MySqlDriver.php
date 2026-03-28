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

final class MySqlDriver implements PdoDriverInterface
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
        $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset=utf8mb4";
        $db = new \PDO($dsn, $this->user, $this->password);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    #[\Override]
    public function insertIgnoreSql(string $table, string $columns, string $placeholders): string
    {
        return "INSERT IGNORE INTO {$table} ({$columns}) VALUES ({$placeholders})";
    }

    #[\Override]
    public function concatExpr(string $a, string $b): string
    {
        return "CONCAT({$a}, {$b})";
    }

    #[\Override]
    public function autoIncrementPrimaryKey(): string
    {
        return 'INTEGER PRIMARY KEY AUTO_INCREMENT';
    }

    #[\Override]
    public function tuneForBulkInsert(\PDO $db): void
    {
        $db->exec('SET unique_checks=0');
        $db->exec('SET foreign_key_checks=0');
    }

    #[\Override]
    public function afterBulkInsert(\PDO $db): void
    {
        $db->exec('SET unique_checks=1');
        $db->exec('SET foreign_key_checks=1');
    }

    #[\Override]
    public function createViewSql(string $view_name): string
    {
        return "CREATE OR REPLACE VIEW {$view_name} AS";
    }

    #[\Override]
    public function quoteIdentifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    #[\Override]
    public function primaryKeyTextType(): string
    {
        return 'VARCHAR(255)';
    }

    #[\Override]
    public function castAsInteger(string $expr): string
    {
        return "CAST({$expr} AS SIGNED)";
    }

    #[\Override]
    public function tuneForParallelInsert(\PDO $db): void
    {
        $this->tuneForBulkInsert($db);
    }
}
