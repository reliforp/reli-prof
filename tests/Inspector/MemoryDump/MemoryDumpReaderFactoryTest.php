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

namespace Reli\Inspector\MemoryDump;

use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

class MemoryDumpReaderFactoryTest extends TestCase
{
    private string $tmp_file;

    protected function setUp(): void
    {
        $this->tmp_file = tempnam(sys_get_temp_dir(), 'reli_test_');
        if ($this->tmp_file === false) {
            $this->fail('failed to create temp file');
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmp_file)) {
            unlink($this->tmp_file);
        }
    }

    #[Test]
    public function testCreateFromPathParsesWrittenDump(): void
    {
        $writer = new MemoryDumpWriter();

        $region_data = str_repeat("\xDE\xAD", 32);

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
        ];

        $regions = [
            ['address' => 0x7f0000001000, 'size' => 64, 'data' => $region_data],
        ];

        $writer->write(
            $this->tmp_file,
            999,
            'v84',
            0x7f0000001000,
            0x7f0000002000,
            $memory_areas,
            $regions,
        );

        $factory = new MemoryDumpReaderFactory(new ContainerBuilder());
        $reader = $factory->createFromPath($this->tmp_file, []);

        // The reader was created successfully
        $this->assertInstanceOf(MemoryDumpReader::class, $reader);
    }

    #[Test]
    public function testInvalidMagicThrows(): void
    {
        file_put_contents($this->tmp_file, "NOTRDUMP\0\0");

        $factory = new MemoryDumpReaderFactory(new ContainerBuilder());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid dump file: bad magic');
        $factory->createFromPath($this->tmp_file, []);
    }
}
