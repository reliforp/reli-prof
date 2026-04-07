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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\Job;

use Reli\Lib\PhpInternals\Types\C\RawInt64;
use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\PhpInternals\Types\Php\MysqlndRes;
use Reli\Lib\PhpInternals\Types\Php\MysqlndResBuffered;
use Reli\Lib\PhpInternals\Types\Php\PdoColumnData;
use Reli\Lib\PhpInternals\Types\Php\PdoMysqlDbHandle;
use Reli\Lib\PhpInternals\Types\Php\PdoMysqlStmt;
use Reli\Lib\PhpInternals\Types\Php\PdoPgsqlDbHandle;
use Reli\Lib\PhpInternals\Types\Php\PdoPgsqlStmt;
use Reli\Lib\PhpInternals\Types\Php\PdoSqliteDbHandle;
use Reli\Lib\PhpInternals\Types\Php\PdoSqliteStmt;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MysqlndMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PdoDbhMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PdoDriverDataMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableOverheadMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Helper methods for PDO-related memory tracking.
 * These are non-recursive operations that don't push jobs.
 */
final class PdoHelper
{
    /** @var array<string, array{class-string, string}> */
    private const PDO_DRIVER_DBH_MAP = [
        'sqlite' => [PdoSqliteDbHandle::class, 'pdo_sqlite_db_handle'],
        'pgsql' => [PdoPgsqlDbHandle::class, 'pdo_pgsql_db_handle'],
        'mysql' => [PdoMysqlDbHandle::class, 'pdo_mysql_db_handle'],
    ];

    /** @var array<string, array{class-string, string}> */
    private const PDO_DRIVER_STMT_MAP = [
        'sqlite' => [PdoSqliteStmt::class, 'pdo_sqlite_stmt'],
        'pgsql' => [PdoPgsqlStmt::class, 'pdo_pgsql_stmt'],
        'mysql' => [PdoMysqlStmt::class, 'pdo_mysql_stmt'],
    ];

    public static function collectPdoDbhObject(
        ZendObject $object,
        CollectorContext $ctx,
        ObjectContext $object_context,
    ): void {
        [$std_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_object_t', 'std');
        $inner_ptr_address = $object->getPointer()->address - $std_offset;

        $inner_ptr = new Pointer(RawInt64::class, $inner_ptr_address, 8);
        $inner_address = $ctx->dereferencer->deref($inner_ptr)->value;
        if ($inner_address === 0) {
            return;
        }

        $dbh_size = $ctx->zend_type_reader->sizeOf('pdo_dbh_t');
        $dbh_location = new PdoDbhMemoryLocation($inner_address, $dbh_size);
        $ctx->memory_locations->add($dbh_location);
        $object_context->addLocation($dbh_location);

        $driver_name = self::detectPdoDriver($inner_address, $ctx);

        [$dd_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_t', 'driver_data');
        $dd_ptr = new Pointer(RawInt64::class, $inner_address + $dd_offset, 8);
        $driver_data_address = $ctx->dereferencer->deref($dd_ptr)->value;

        if ($driver_data_address !== 0) {
            self::collectPdoDriverData($driver_name, $driver_data_address, $ctx, $object_context);
        }

        self::collectPdoDbhStrings($inner_address, $ctx, $object_context);
    }

    public static function collectPdoStmt(
        ZendObject $object,
        CollectorContext $ctx,
        ObjectContext $object_context,
    ): void {
        [$std_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'std');
        $stmt_address = $object->getPointer()->address - $std_offset;

        // Query strings (v81+)
        if (!$ctx->zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V81)) {
            foreach (['query_string', 'active_query_string'] as $idx => $field) {
                try {
                    [$qs_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', $field);
                    $qs_ptr = new Pointer(RawInt64::class, $stmt_address + $qs_offset, 8);
                    $qs_address = $ctx->dereferencer->deref($qs_ptr)->value;
                    if ($qs_address !== 0) {
                        $string_pointer = new Pointer(
                            ZendString::class,
                            $qs_address,
                            $ctx->zend_type_reader->sizeOf('zend_string'),
                        );
                        $string_context = CollectorHelpers::collectZendStringPointer($string_pointer, $ctx);
                        if ($idx === 0) {
                            $object_context->add('pdo_query_string', $string_context);
                        }
                    }
                } catch (\Throwable) {
                }
            }
        }

        // Bound params/columns HashTables
        foreach (['bound_params', 'bound_param_map', 'bound_columns'] as $ht_field) {
            try {
                [$ht_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', $ht_field);
                $ht_ptr = new Pointer(RawInt64::class, $stmt_address + $ht_offset, 8);
                $ht_address = $ctx->dereferencer->deref($ht_ptr)->value;
                if ($ht_address !== 0 && !$ctx->memory_locations->has($ht_address)) {
                    $ht_pointer = new Pointer(
                        ZendArray::class,
                        $ht_address,
                        $ctx->zend_type_reader->sizeOf('zend_array'),
                    );
                    $array = $ctx->dereferencer->deref($ht_pointer);
                    $array_header_location = ZendArrayMemoryLocation::fromZendArray($array);
                    $ctx->memory_locations->add($array_header_location);
                    $object_context->addLocation($array_header_location);
                    if (!is_null($array->arData)) {
                        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($array);
                        $array_table_overhead_location =
                            ZendArrayTableOverheadMemoryLocation::fromZendArrayAndUsedLocation(
                                $array,
                                $array_table_location,
                            );
                        $ctx->memory_locations->add($array_table_location);
                        $object_context->addLocation($array_table_location);
                        $ctx->memory_locations->add($array_table_overhead_location);
                        $object_context->addLocation($array_table_overhead_location);
                    }
                }
            } catch (\Throwable) {
            }
        }

        // Driver detection via stmt->dbh
        [$dbh_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'dbh');
        $dbh_ptr = new Pointer(RawInt64::class, $stmt_address + $dbh_offset, 8);
        $dbh_address = $ctx->dereferencer->deref($dbh_ptr)->value;

        $driver_name = '';
        if ($dbh_address !== 0) {
            $driver_name = self::detectPdoDriver($dbh_address, $ctx);
        }

        // Driver data
        [$dd_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'driver_data');
        $dd_ptr = new Pointer(RawInt64::class, $stmt_address + $dd_offset, 8);
        $driver_data_address = $ctx->dereferencer->deref($dd_ptr)->value;
        if ($driver_data_address !== 0) {
            self::collectPdoStmtDriverData($driver_name, $driver_data_address, $ctx, $object_context);
        }

        // Columns
        $cc_buf = $ctx->dereferencer->deref(new Pointer(
            RawInt64::class,
            $stmt_address + $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'column_count')[0],
            8,
        ));
        $column_count = $cc_buf->value & 0xFFFFFFFF;
        if ($column_count > 0x7FFFFFFF) {
            $column_count = 0;
        }

        [$cols_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'columns');
        $cols_ptr = new Pointer(RawInt64::class, $stmt_address + $cols_offset, 8);
        $columns_address = $ctx->dereferencer->deref($cols_ptr)->value;

        if ($columns_address !== 0 && $column_count > 0 && $column_count < 10000) {
            $column_data_size = $ctx->zend_type_reader->sizeOf('pdo_column_data');
            if (!$ctx->memory_locations->has($columns_address)) {
                $columns_location = new PdoDriverDataMemoryLocation($columns_address, (int)$column_count * $column_data_size);
                $ctx->memory_locations->add($columns_location);
                $object_context->addLocation($columns_location);
            }
            for ($i = 0; $i < $column_count; $i++) {
                $col = $ctx->dereferencer->deref(new Pointer(
                    PdoColumnData::class,
                    $columns_address + $i * $column_data_size,
                    $column_data_size,
                ));
                if ($col->name !== 0) {
                    CollectorHelpers::collectZendStringPointer(
                        new Pointer(ZendString::class, $col->name, $ctx->zend_type_reader->sizeOf('zend_string')),
                        $ctx,
                    );
                }
            }
        }
    }

    private static function detectPdoDriver(int $dbh_address, CollectorContext $ctx): string
    {
        try {
            [$drv_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_t', 'driver');
            $drv_ptr = new Pointer(RawInt64::class, $dbh_address + $drv_offset, 8);
            $driver_struct_address = $ctx->dereferencer->deref($drv_ptr)->value;
            if ($driver_struct_address === 0) {
                return '';
            }
            $name_ptr_ptr = new Pointer(RawInt64::class, $driver_struct_address, 8);
            $name_address = $ctx->dereferencer->deref($name_ptr_ptr)->value;
            if ($name_address === 0) {
                return '';
            }
            return (string)$ctx->dereferencer->deref(new Pointer(RawString::class, $name_address, 16));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function collectPdoDriverData(
        string $driver_name,
        int $driver_data_address,
        CollectorContext $ctx,
        ObjectContext $object_context,
    ): void {
        $map = self::PDO_DRIVER_DBH_MAP[$driver_name] ?? null;
        if ($map === null) {
            return;
        }
        [$class, $ctype] = $map;
        try {
            $size = $ctx->zend_type_reader->sizeOf($ctype);
            $ctx->dereferencer->deref(new Pointer($class, $driver_data_address, $size));
            $location = new PdoDriverDataMemoryLocation($driver_data_address, $size);
            $ctx->memory_locations->add($location);
            $object_context->addLocation($location);
        } catch (\Throwable) {
        }
    }

    private static function collectPdoStmtDriverData(
        string $driver_name,
        int $driver_data_address,
        CollectorContext $ctx,
        ObjectContext $object_context,
    ): void {
        $map = self::PDO_DRIVER_STMT_MAP[$driver_name] ?? null;
        if ($map === null) {
            return;
        }
        [$class, $ctype] = $map;
        try {
            $size = $ctx->zend_type_reader->sizeOf($ctype);
            $ctx->dereferencer->deref(new Pointer($class, $driver_data_address, $size));
            $location = new PdoDriverDataMemoryLocation($driver_data_address, $size);
            $ctx->memory_locations->add($location);
            $object_context->addLocation($location);
        } catch (\Throwable) {
            return;
        }

        if ($driver_name === 'mysql') {
            self::collectMysqlndResultFromPdoMysqlStmt($driver_data_address, $ctx, $object_context);
        }
    }

    private static function collectMysqlndResultFromPdoMysqlStmt(
        int $pdo_mysql_stmt_address,
        CollectorContext $ctx,
        ObjectContext $object_context,
    ): void {
        try {
            $result_ptr = new Pointer(RawInt64::class, $pdo_mysql_stmt_address + 8, 8);
            $result_address = $ctx->dereferencer->deref($result_ptr)->value;
            if ($result_address === 0) {
                return;
            }
            self::collectMysqlndResult($result_address, $ctx, $object_context);
        } catch (\Throwable) {
        }
    }

    private static function collectMysqlndResult(
        int $res_address,
        CollectorContext $ctx,
        ObjectContext $object_context,
    ): void {
        if ($ctx->memory_locations->has($res_address)) {
            return;
        }
        $res_size = $ctx->zend_type_reader->sizeOf('MYSQLND_RES');
        try {
            $ctx->dereferencer->deref(new Pointer(MysqlndRes::class, $res_address, $res_size));
        } catch (\Throwable) {
            return;
        }
        $location = new MysqlndMemoryLocation($res_address, $res_size);
        $ctx->memory_locations->add($location);
        $object_context->addLocation($location);

        [$sd_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('MYSQLND_RES', 'stored_data');
        $stored_data_address = $ctx->dereferencer->deref(
            new Pointer(RawInt64::class, $res_address + $sd_offset, 8),
        )->value;

        if ($stored_data_address !== 0) {
            self::collectMysqlndResBuffered($stored_data_address, $ctx, $object_context);
        }

        [$mp_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('MYSQLND_RES', 'memory_pool');
        $mempool_address = $ctx->dereferencer->deref(
            new Pointer(RawInt64::class, $res_address + $mp_offset, 8),
        )->value;

        if ($mempool_address !== 0 && !$ctx->memory_locations->has($mempool_address)) {
            $mp_size = $ctx->zend_type_reader->sizeOf('MYSQLND_MEMORY_POOL');
            try {
                $mp_location = new MysqlndMemoryLocation($mempool_address, $mp_size);
                $ctx->memory_locations->add($mp_location);
                $object_context->addLocation($mp_location);
            } catch (\Throwable) {
            }
        }
    }

    private static function collectMysqlndResBuffered(
        int $buf_address,
        CollectorContext $ctx,
        ObjectContext $object_context,
    ): void {
        if ($ctx->memory_locations->has($buf_address)) {
            return;
        }
        $buf_size = $ctx->zend_type_reader->sizeOf('MYSQLND_RES_BUFFERED');
        try {
            $ctx->dereferencer->deref(new Pointer(MysqlndResBuffered::class, $buf_address, $buf_size));
        } catch (\Throwable) {
            return;
        }
        $location = new MysqlndMemoryLocation($buf_address, $buf_size);
        $ctx->memory_locations->add($location);
        $object_context->addLocation($location);

        [$rb_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('MYSQLND_RES_BUFFERED', 'row_buffers');
        $row_buffers_address = $ctx->dereferencer->deref(
            new Pointer(RawInt64::class, $buf_address + $rb_offset, 8),
        )->value;

        [$rc_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('MYSQLND_RES_BUFFERED', 'row_count');
        $row_count = $ctx->dereferencer->deref(
            new Pointer(RawInt64::class, $buf_address + $rc_offset, 8),
        )->value;

        if ($row_buffers_address !== 0 && $row_count > 0 && $row_count < 1000000) {
            $rb_element_size = $ctx->zend_type_reader->sizeOf('MYSQLND_ROW_BUFFER');
            $total_size = (int)($row_count * $rb_element_size);
            if (!$ctx->memory_locations->has($row_buffers_address)) {
                $rb_location = new MysqlndMemoryLocation($row_buffers_address, $total_size);
                $ctx->memory_locations->add($rb_location);
                $object_context->addLocation($rb_location);
            }
        }

        [$rmp_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember(
            'MYSQLND_RES_BUFFERED',
            'result_set_memory_pool',
        );
        $rmp_address = $ctx->dereferencer->deref(
            new Pointer(RawInt64::class, $buf_address + $rmp_offset, 8),
        )->value;

        if ($rmp_address !== 0 && !$ctx->memory_locations->has($rmp_address)) {
            $mp_size = $ctx->zend_type_reader->sizeOf('MYSQLND_MEMORY_POOL');
            $mp_location = new MysqlndMemoryLocation($rmp_address, $mp_size);
            $ctx->memory_locations->add($mp_location);
            $object_context->addLocation($mp_location);

            $arena_address = $ctx->dereferencer->deref(new Pointer(RawInt64::class, $rmp_address, 8))->value;
            self::collectZendArenaChain($arena_address, $ctx, $object_context);
        }
    }

    private static function collectZendArenaChain(
        int $arena_address,
        CollectorContext $ctx,
        ObjectContext $object_context,
    ): void {
        $visited = 0;
        while ($arena_address !== 0 && $visited < 1000) {
            if ($ctx->memory_locations->has($arena_address)) {
                break;
            }
            try {
                $end_address = $ctx->dereferencer->deref(
                    new Pointer(RawInt64::class, $arena_address + 8, 8),
                )->value;
                if ($end_address > $arena_address) {
                    $chunk_size = $end_address - $arena_address;
                    if ($chunk_size < 64 * 1024 * 1024) {
                        $arena_location = new MysqlndMemoryLocation($arena_address, (int)$chunk_size);
                        $ctx->memory_locations->add($arena_location);
                        $object_context->addLocation($arena_location);
                    }
                }
                $arena_address = $ctx->dereferencer->deref(
                    new Pointer(RawInt64::class, $arena_address + 16, 8),
                )->value;
                $visited++;
            } catch (\Throwable) {
                break;
            }
        }
    }

    private static function collectPdoDbhStrings(
        int $dbh_address,
        CollectorContext $ctx,
        ObjectContext $object_context,
    ): void {
        try {
            [$ds_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_t', 'data_source');
            $ds_ptr = new Pointer(RawInt64::class, $dbh_address + $ds_offset, 8);
            $ds_address = $ctx->dereferencer->deref($ds_ptr)->value;
            [$dsl_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember(
                'pdo_dbh_t',
                'data_source_len',
            );
            $dsl_ptr = new Pointer(RawInt64::class, $dbh_address + $dsl_offset, 8);
            $ds_len = $ctx->dereferencer->deref($dsl_ptr)->value;
            if ($ds_address !== 0 && $ds_len > 0 && $ds_len < 65536) {
                $ds_location = new PdoDbhMemoryLocation($ds_address, $ds_len + 1);
                $ctx->memory_locations->add($ds_location);
                $object_context->addLocation($ds_location);
            }
        } catch (\Throwable) {
        }

        foreach (['username', 'password'] as $field) {
            try {
                [$f_offset] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_t', $field);
                $f_ptr = new Pointer(RawInt64::class, $dbh_address + $f_offset, 8);
                $f_address = $ctx->dereferencer->deref($f_ptr)->value;
                if ($f_address !== 0 && !$ctx->memory_locations->has($f_address)) {
                    $str = (string)$ctx->dereferencer->deref(new Pointer(RawString::class, $f_address, 256));
                    $len = strlen($str);
                    if ($len > 0) {
                        $f_location = new PdoDbhMemoryLocation($f_address, $len + 1);
                        $ctx->memory_locations->add($f_location);
                        $object_context->addLocation($f_location);
                    }
                }
            } catch (\Throwable) {
            }
        }
    }
}
