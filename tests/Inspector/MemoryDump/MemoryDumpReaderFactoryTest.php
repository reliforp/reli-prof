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

    #[Test]
    public function testV3ModuleGlobalsRoundtrip(): void
    {
        $writer = new MemoryDumpWriter();
        $writer->write(
            $this->tmp_file,
            1234,
            'v84',
            0x7f0000001000,
            0x7f0000002000,
            [],
            [],
            null,
            ['basic_globals' => 0x7f0000003000, 'future_module_x' => 0x7f0000004000],
        );

        // Re-parse via low-level helper that mirrors the production reader.
        $fp = fopen($this->tmp_file, 'rb');
        $this->assertNotFalse($fp);
        try {
            $this->assertSame("RDUMP\0\0\0", fread($fp, 8));
            $this->assertSame(3, unpack('Vval', fread($fp, 4))['val']);
            // skip php_version, pid, eg, cg, rss
            $len = unpack('Vval', fread($fp, 4))['val'];
            fread($fp, $len);
            fread($fp, 8 + 8 + 8 + 8);

            // Module globals (v3): 2 entries, basic_globals first
            $entry_count = unpack('Vval', fread($fp, 4))['val'];
            $this->assertSame(2, $entry_count);

            $key_len = unpack('Vval', fread($fp, 4))['val'];
            $this->assertSame('basic_globals', fread($fp, $key_len));
            $this->assertSame(
                0x7f0000003000,
                unpack('Pval', fread($fp, 8))['val'],
            );

            $key_len = unpack('Vval', fread($fp, 4))['val'];
            $this->assertSame('future_module_x', fread($fp, $key_len));
            $this->assertSame(
                0x7f0000004000,
                unpack('Pval', fread($fp, 8))['val'],
            );
        } finally {
            fclose($fp);
        }

        // High-level factory path still constructs a reader.
        $factory = new MemoryDumpReaderFactory(new ContainerBuilder());
        $reader = $factory->createFromPath($this->tmp_file, []);
        $this->assertInstanceOf(MemoryDumpReader::class, $reader);
    }

    /**
     * Older dumps (v1/v2) lack the module-globals map. They must still
     * parse — the tier-2 fallback (or null) handles the missing key.
     */
    #[Test]
    public function testV2DumpStillParses(): void
    {
        // Hand-assemble a minimal v2 header. We can't go through the
        // writer because it always emits v3 now; this test pins down
        // the parser's backward-compat path.
        $buf = "RDUMP\0\0\0";
        $buf .= pack('V', 2);             // format version
        $buf .= pack('V', 3) . 'v84';     // php_version
        $buf .= pack('P', 1234);          // pid
        $buf .= pack('P', 0x1000);        // eg
        $buf .= pack('P', 0x2000);        // cg
        $buf .= pack('q', -1);            // rss_bytes (unavailable)
        $buf .= pack('V', 0);             // memory map count
        $buf .= pack('V', 0);             // region count
        file_put_contents($this->tmp_file, $buf);

        $factory = new MemoryDumpReaderFactory(new ContainerBuilder());
        $reader = $factory->createFromPath($this->tmp_file, []);
        $this->assertInstanceOf(MemoryDumpReader::class, $reader);
    }
}
