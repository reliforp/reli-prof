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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;

class MemoryDumpWriterTest extends TestCase
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
    public function testWriteAndReadRoundtrip(): void
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
                '7f0001100000',
                '0',
                new ProcessMemoryAttribute(true, false, true, false),
                'fd:01',
                12345,
                '/usr/bin/php',
            ),
        ];

        $region_data_1 = str_repeat("\x42", 64);
        $region_data_2 = str_repeat("\xAB", 128);

        $regions = [
            ['address' => 0x7f0000000000, 'size' => 64, 'data' => $region_data_1],
            ['address' => 0x7f0001000000, 'size' => 128, 'data' => $region_data_2],
        ];

        $writer->write(
            $this->tmp_file,
            1234,
            'v84',
            0x7f00deadbeef,
            0x7f00cafebabe,
            $memory_areas,
            $regions,
        );

        $this->assertFileExists($this->tmp_file);
        $file_size = filesize($this->tmp_file);
        $this->assertGreaterThan(0, $file_size);

        // Parse the file back
        $fp = fopen($this->tmp_file, 'rb');
        $this->assertNotFalse($fp);

        // Magic
        $magic = fread($fp, 8);
        $this->assertSame("RELIMEM\0", $magic);

        // Format version
        $result = unpack('Vval', fread($fp, 4));
        $this->assertSame(1, $result['val']);

        // PHP version
        $len = unpack('Vval', fread($fp, 4))['val'];
        $php_version = fread($fp, $len);
        $this->assertSame('v84', $php_version);

        // PID
        $pid = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(1234, $pid);

        // EG address
        $eg_address = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(0x7f00deadbeef, $eg_address);

        // CG address
        $cg_address = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(0x7f00cafebabe, $cg_address);

        // Memory map count
        $memory_map_count = unpack('Vval', fread($fp, 4))['val'];
        $this->assertSame(2, $memory_map_count);

        // Region count
        $region_count = unpack('Vval', fread($fp, 4))['val'];
        $this->assertSame(2, $region_count);

        // First memory area
        $begin_len = unpack('Vval', fread($fp, 4))['val'];
        $begin = fread($fp, $begin_len);
        $this->assertSame('7f0000000000', $begin);

        $end_len = unpack('Vval', fread($fp, 4))['val'];
        $end = fread($fp, $end_len);
        $this->assertSame('7f0000200000', $end);

        $offset_len = unpack('Vval', fread($fp, 4))['val'];
        $file_offset = fread($fp, $offset_len);
        $this->assertSame('0', $file_offset);

        $attrs = fread($fp, 4);
        $this->assertSame(1, ord($attrs[0])); // read
        $this->assertSame(1, ord($attrs[1])); // write
        $this->assertSame(0, ord($attrs[2])); // execute
        $this->assertSame(1, ord($attrs[3])); // protected

        // Skip device_id
        $dev_len = unpack('Vval', fread($fp, 4))['val'];
        fread($fp, $dev_len); // "00:00"

        // inode
        $inode = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(0, $inode);

        // name
        $name_len = unpack('Vval', fread($fp, 4))['val'];
        $name = fread($fp, $name_len);
        $this->assertSame('[heap]', $name);

        // Skip second memory area (just seek past it to verify no read errors)
        // begin
        $len = unpack('Vval', fread($fp, 4))['val'];
        fread($fp, $len);
        // end
        $len = unpack('Vval', fread($fp, 4))['val'];
        fread($fp, $len);
        // file_offset
        $len = unpack('Vval', fread($fp, 4))['val'];
        fread($fp, $len);
        // attrs
        fread($fp, 4);
        // device_id
        $len = unpack('Vval', fread($fp, 4))['val'];
        fread($fp, $len);
        // inode
        fread($fp, 8);
        // name
        $len = unpack('Vval', fread($fp, 4))['val'];
        fread($fp, $len);

        // First region
        $addr = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(0x7f0000000000, $addr);
        $size = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(64, $size);
        $data = fread($fp, $size);
        $this->assertSame($region_data_1, $data);

        // Second region
        $addr = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(0x7f0001000000, $addr);
        $size = unpack('Pval', fread($fp, 8))['val'];
        $this->assertSame(128, $size);
        $data = fread($fp, $size);
        $this->assertSame($region_data_2, $data);

        // EOF
        $this->assertSame('', fread($fp, 1));

        fclose($fp);
    }

    #[Test]
    public function testEmptyRegions(): void
    {
        $writer = new MemoryDumpWriter();
        $writer->write(
            $this->tmp_file,
            42,
            'v83',
            0x1000,
            0x2000,
            [],
            [],
        );

        $this->assertFileExists($this->tmp_file);

        $fp = fopen($this->tmp_file, 'rb');
        $magic = fread($fp, 8);
        $this->assertSame("RELIMEM\0", $magic);
        fclose($fp);
    }
}
