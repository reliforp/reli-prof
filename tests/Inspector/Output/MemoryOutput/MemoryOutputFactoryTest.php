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

namespace Reli\Inspector\Output\MemoryOutput;

use Reli\BaseTestCase;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;

class MemoryOutputFactoryTest extends BaseTestCase
{
    public function testCreateJsonOutput(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'json',
        );
        $output = $factory->create($settings);
        $this->assertInstanceOf(JsonMemoryOutput::class, $output);
    }

    public function testCreateSqliteOutput(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_test_') . '.db';
        try {
            $factory = new MemoryOutputFactory();
            $settings = new MemoryProfilerSettings(
                stop_process: false,
                pretty_print: false,
                output_format: 'sqlite3',
                output_path: $path,
            );
            $output = $factory->create($settings);
            $this->assertInstanceOf(PdoMemoryOutput::class, $output);
        } finally {
            @unlink($path);
        }
    }

    public function testCreateSqliteOutputRequiresOutputPath(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'sqlite3',
            output_path: null,
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--output is required');
        $factory->create($settings);
    }

    public function testCreateMysqlOutput(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'mysql',
            db_name: 'test_db',
            db_user: 'root',
            db_password: '',
        );
        $output = $factory->create($settings);
        $this->assertInstanceOf(PdoMemoryOutput::class, $output);
    }

    public function testCreateMysqlOutputRequiresDbName(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'mysql',
            db_user: 'root',
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--db-name is required');
        $factory->create($settings);
    }

    public function testCreateMysqlOutputRequiresDbUser(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'mysql',
            db_name: 'test_db',
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--db-user is required');
        $factory->create($settings);
    }

    public function testCreatePostgresqlOutput(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'postgresql',
            db_name: 'test_db',
            db_user: 'postgres',
            db_password: '',
        );
        $output = $factory->create($settings);
        $this->assertInstanceOf(PdoMemoryOutput::class, $output);
    }

    public function testCreatePostgresqlOutputRequiresDbName(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'postgresql',
            db_user: 'postgres',
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--db-name is required');
        $factory->create($settings);
    }

    public function testCreatePostgresqlOutputRequiresDbUser(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'postgresql',
            db_name: 'test_db',
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--db-user is required');
        $factory->create($settings);
    }

    public function testCreateReportOutput(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'report',
        );
        $output = $factory->create($settings);
        $this->assertInstanceOf(ReportMemoryOutput::class, $output);
    }

    public function testCreateReportJsonOutput(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: true,
            output_format: 'report-json',
        );
        $output = $factory->create($settings);
        $this->assertInstanceOf(ReportMemoryOutput::class, $output);
    }

    public function testCreateUnsupportedFormatThrows(): void
    {
        $factory = new MemoryOutputFactory();
        $settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'csv',
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported output format: csv');
        $factory->create($settings);
    }
}
