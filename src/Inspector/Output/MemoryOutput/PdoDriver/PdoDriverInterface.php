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

interface PdoDriverInterface
{
    public function createConnection(): \PDO;

    /** SQL for inserting a node, ignoring duplicates */
    public function insertIgnoreSql(string $table, string $columns, string $placeholders): string;

    /**
     * SQL for a multi-row INSERT IGNORE. `$row_placeholders` is the
     * placeholder string for a single row (e.g. `"?,?,?"`), and the
     * driver repeats it `$row_count` times with the correct
     * conflict-ignore syntax for its dialect.
     */
    public function batchInsertIgnoreSql(
        string $table,
        string $columns,
        string $row_placeholders,
        int $row_count,
    ): string;

    /** SQL expression to concatenate two strings */
    public function concatExpr(string $a, string $b): string;

    /** Auto-increment column definition for surrogate PKs */
    public function autoIncrementPrimaryKey(): string;

    /** Statements to execute after creating tables and before bulk insert */
    public function tuneForBulkInsert(\PDO $db): void;

    /** Statements to execute after bulk insert completes */
    public function afterBulkInsert(\PDO $db): void;

    /** SQL prefix for creating a view (handles dialect differences) */
    public function createViewSql(string $view_name): string;

    /** Quote an identifier (table/column name) for this database */
    public function quoteIdentifier(string $identifier): string;

    /** Text column type suitable for use as a PRIMARY KEY */
    public function primaryKeyTextType(): string;

    /** SQL expression to cast a value to integer */
    public function castAsInteger(string $expr): string;
}
