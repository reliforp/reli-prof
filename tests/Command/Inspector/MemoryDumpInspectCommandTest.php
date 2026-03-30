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

namespace Reli\Command\Inspector;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Reli\Inspector\MemoryDump\MemoryDumpWriter;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MemoryDumpInspectCommandTest extends TestCase
{
    private string $tmp_file;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'reli_inspect_test_');
        if ($tmp === false) {
            $this->fail('failed to create temp file');
        }
        $this->tmp_file = $tmp;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmp_file)) {
            unlink($this->tmp_file);
        }
    }

    private function createDumpFile(
        int $pid = 1234,
        string $php_version = 'v84',
        int $eg_address = 0x7f00deadbeef,
        int $cg_address = 0x7f00cafebabe,
        array $memory_areas = [],
        array $regions = [],
    ): void {
        $writer = new MemoryDumpWriter();
        $writer->write(
            $this->tmp_file,
            $pid,
            $php_version,
            $eg_address,
            $cg_address,
            $memory_areas,
            $regions,
        );
    }

    #[Test]
    public function testHeaderOutput(): void
    {
        $this->createDumpFile(
            pid: 9999,
            php_version: 'v84',
            eg_address: 0x7f0000001000,
            cg_address: 0x7f0000002000,
        );

        $command = new MemoryDumpInspectCommand();
        $input = new ArrayInput(['dump-file' => $this->tmp_file]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);
        $this->assertSame(0, $result);

        $display = $output->fetch();
        $this->assertStringContainsString('=== Header ===', $display);
        $this->assertStringContainsString('Format Version:   1', $display);
        $this->assertStringContainsString('PHP Version:      v84', $display);
        $this->assertStringContainsString('PID:              9999', $display);
        $this->assertStringContainsString('EG Address:       0x7f0000001000', $display);
        $this->assertStringContainsString('CG Address:       0x7f0000002000', $display);
        $this->assertStringContainsString('Memory Map Count: 0', $display);
        $this->assertStringContainsString('Region Count:     0', $display);
    }

    #[Test]
    public function testMemoryMapOutput(): void
    {
        $memory_areas = [
            new ProcessMemoryArea(
                '7f0000000000',
                '7f0000200000',
                '0',
                new ProcessMemoryAttribute(true, true, false, true),
                '00:00',
                0,
                '[heap]',
            ),
            new ProcessMemoryArea(
                '7f0001000000',
                '7f0001100000',
                '1000',
                new ProcessMemoryAttribute(true, false, true, false),
                'fd:01',
                12345,
                '/usr/bin/php',
            ),
        ];

        $this->createDumpFile(memory_areas: $memory_areas);

        $command = new MemoryDumpInspectCommand();
        $input = new ArrayInput(['dump-file' => $this->tmp_file]);
        $output = new BufferedOutput();

        $command->run($input, $output);
        $display = $output->fetch();

        $this->assertStringContainsString('=== Memory Map ===', $display);
        $this->assertStringContainsString('Memory Map Count: 2', $display);
        $this->assertStringContainsString('7f0000000000-7f0000200000 rw-p', $display);
        $this->assertStringContainsString('[heap]', $display);
        $this->assertStringContainsString('7f0001000000-7f0001100000 r-x-', $display);
        $this->assertStringContainsString('/usr/bin/php', $display);
    }

    #[Test]
    public function testRegionsOutput(): void
    {
        $regions = [
            ['address' => 0x7f0000000000, 'size' => 64, 'data' => str_repeat("\x42", 64)],
            ['address' => 0x7f0001000000, 'size' => 2 * 1024 * 1024, 'data' => str_repeat("\xAB", 2 * 1024 * 1024)],
        ];

        $this->createDumpFile(regions: $regions);

        $command = new MemoryDumpInspectCommand();
        $input = new ArrayInput(['dump-file' => $this->tmp_file]);
        $output = new BufferedOutput();

        $command->run($input, $output);
        $display = $output->fetch();

        $this->assertStringContainsString('=== Regions ===', $display);
        $this->assertStringContainsString('Region Count:     2', $display);
        $this->assertStringContainsString('#0  0x7f0000000000 - 0x7f0000000040', $display);
        $this->assertStringContainsString('64 B', $display);
        $this->assertStringContainsString('#1  0x7f0001000000 - 0x7f0001200000', $display);
        $this->assertStringContainsString('2.0 MiB', $display);
        $this->assertStringContainsString('Total: 2 regions', $display);
    }

    #[Test]
    public function testMemoryMapPermissionFlags(): void
    {
        $memory_areas = [
            new ProcessMemoryArea(
                '1000',
                '2000',
                '0',
                new ProcessMemoryAttribute(false, false, false, false),
                '00:00',
                0,
                '',
            ),
            new ProcessMemoryArea(
                '3000',
                '4000',
                '0',
                new ProcessMemoryAttribute(true, true, true, true),
                '00:00',
                0,
                '',
            ),
        ];

        $this->createDumpFile(memory_areas: $memory_areas);

        $command = new MemoryDumpInspectCommand();
        $input = new ArrayInput(['dump-file' => $this->tmp_file]);
        $output = new BufferedOutput();

        $command->run($input, $output);
        $display = $output->fetch();

        $this->assertStringContainsString('1000-2000 ----', $display);
        $this->assertStringContainsString('3000-4000 rwxp', $display);
    }

    #[Test]
    public function testInvalidMagicThrowsException(): void
    {
        file_put_contents($this->tmp_file, 'NOTVALID');

        $command = new MemoryDumpInspectCommand();
        $input = new ArrayInput(['dump-file' => $this->tmp_file]);
        $output = new BufferedOutput();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid dump file: bad magic');
        $command->run($input, $output);
    }

    #[Test]
    public function testNonExistentFileThrowsException(): void
    {
        $command = new MemoryDumpInspectCommand();
        $input = new ArrayInput(['dump-file' => '/tmp/nonexistent_reli_dump_' . uniqid()]);
        $output = new BufferedOutput();

        $this->expectException(\RuntimeException::class);
        $command->run($input, $output);
    }

    #[Test]
    public function testEmptyDumpFile(): void
    {
        $this->createDumpFile();

        $command = new MemoryDumpInspectCommand();
        $input = new ArrayInput(['dump-file' => $this->tmp_file]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);
        $this->assertSame(0, $result);

        $display = $output->fetch();
        $this->assertStringContainsString('Total: 0 regions, 0 B', $display);
    }
}
