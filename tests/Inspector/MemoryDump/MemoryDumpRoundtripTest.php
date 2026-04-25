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

class MemoryDumpRoundtripTest extends TestCase
{
    private string $tmp_file;

    protected function setUp(): void
    {
        $this->tmp_file = tempnam(sys_get_temp_dir(), 'reli_roundtrip_');
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
    public function testWriteThenReadBack(): void
    {
        $writer = new MemoryDumpWriter();

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
                '7f0001001000',
                '0',
                new ProcessMemoryAttribute(true, false, true, false),
                'fd:01',
                12345,
                '/usr/bin/php',
            ),
        ];

        $region_data = str_repeat("\xAB\xCD", 2048);
        $regions = [
            ['address' => 0x7f0000000000, 'size' => 4096, 'data' => $region_data],
        ];

        $writer->write(
            $this->tmp_file,
            9999,
            'v84',
            0x7f00deadbeef,
            0x7f00cafebabe,
            $memory_areas,
            $regions,
        );

        // Read back via MemoryDumpReaderFactory
        $factory = new MemoryDumpReaderFactory(new ContainerBuilder());
        $reader = $factory->createFromPath($this->tmp_file, []);

        $this->assertInstanceOf(MemoryDumpReader::class, $reader);
    }

    #[Test]
    public function testDumpFileContainsCorrectRegionData(): void
    {
        $writer = new MemoryDumpWriter();

        $data_a = str_repeat('A', 100);
        $data_b = str_repeat('B', 200);

        $memory_areas = [
            new ProcessMemoryArea(
                '1000',
                '2000',
                '0',
                new ProcessMemoryAttribute(true, true, false, false),
                '00:00',
                0,
                '',
            ),
        ];

        $regions = [
            ['address' => 0x1000, 'size' => 100, 'data' => $data_a],
            ['address' => 0x1100, 'size' => 200, 'data' => $data_b],
        ];

        $writer->write(
            $this->tmp_file,
            42,
            'v83',
            0x5000,
            0x6000,
            $memory_areas,
            $regions,
        );

        // Verify by parsing the binary format directly
        $fp = fopen($this->tmp_file, 'rb');
        $this->assertNotFalse($fp);

        // Skip header
        $magic = fread($fp, 8);
        $this->assertSame("RELIMEM\0", $magic);

        // version
        $ver = unpack('Vval', fread($fp, 4));
        $this->assertSame(2, $ver['val']);

        // php_version
        $len = unpack('Vval', fread($fp, 4))['val'];
        $php_ver = fread($fp, $len);
        $this->assertSame('v83', $php_ver);

        // pid
        $pid = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(42, $pid);

        // eg, cg
        $eg = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(0x5000, $eg);
        $cg = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(0x6000, $cg);

        // rss_bytes (v2): caller passed default null → -1 sentinel
        $rss = unpack('qval', fread($fp, 8))['val'];
        $this->assertSame(-1, $rss);

        // counts
        $map_count = unpack('Vval', fread($fp, 4))['val'];
        $this->assertSame(1, $map_count);
        $region_count = unpack('Vval', fread($fp, 4))['val'];
        $this->assertSame(2, $region_count);

        fclose($fp);
    }

    #[Test]
    public function testLargeRegionRoundtrip(): void
    {
        $writer = new MemoryDumpWriter();

        // Simulate a 2MB ZendMM chunk
        $chunk_data = random_bytes(2 * 1024 * 1024);

        $regions = [
            ['address' => 0x7f0000000000, 'size' => 2 * 1024 * 1024, 'data' => $chunk_data],
        ];

        $writer->write(
            $this->tmp_file,
            1,
            'v84',
            0x1000,
            0x2000,
            [],
            $regions,
        );

        $file_size = filesize($this->tmp_file);
        // File should be at least 2MB (chunk data) + header
        $this->assertGreaterThan(2 * 1024 * 1024, $file_size);
        // But not much more than 2MB
        $this->assertLessThan(2 * 1024 * 1024 + 1024, $file_size);
    }

    #[Test]
    public function testMultipleMemoryAreasPreserved(): void
    {
        $writer = new MemoryDumpWriter();

        $areas = [];
        for ($i = 0; $i < 10; $i++) {
            $areas[] = new ProcessMemoryArea(
                dechex(0x7f0000000000 + $i * 0x1000),
                dechex(0x7f0000000000 + ($i + 1) * 0x1000),
                '0',
                new ProcessMemoryAttribute(true, $i % 2 === 0, false, true),
                '00:00',
                $i,
                $i % 3 === 0 ? '[heap]' : ($i % 3 === 1 ? '/usr/bin/php' : ''),
            );
        }

        $writer->write(
            $this->tmp_file,
            100,
            'v84',
            0x1000,
            0x2000,
            $areas,
            [],
        );

        $factory = new MemoryDumpReaderFactory(new ContainerBuilder());
        // Should not throw
        $reader = $factory->createFromPath($this->tmp_file, []);
        $this->assertInstanceOf(MemoryDumpReader::class, $reader);
    }
}
