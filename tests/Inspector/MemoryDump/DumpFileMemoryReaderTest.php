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
use Reli\Lib\File\PathResolver\MappedPathResolver;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryAddressNotInDumpException;

class DumpFileMemoryReaderTest extends TestCase
{
    private string $tmp_file;

    protected function setUp(): void
    {
        $this->tmp_file = tempnam(sys_get_temp_dir(), 'reli_dfmr_');
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

    /**
     * Build a minimal dump file with a single captured region and
     * return a reader configured against it.
     *
     * @param list<array{address: int, size: int, data: string}> $regions
     * @param ProcessMemoryArea[] $memory_areas
     */
    private function makeReader(array $regions, array $memory_areas = []): DumpFileMemoryReader
    {
        (new MemoryDumpWriter())->write(
            $this->tmp_file,
            1234,
            'v84',
            0x1000,
            0x2000,
            $memory_areas,
            $regions,
        );

        // Build a region index compatible with DumpFileMemoryReader's
        // internal layout: we just need {address, size, file_offset}
        // tuples where file_offset is where the region's bytes start
        // in the dump file.
        $fp = fopen($this->tmp_file, 'rb') ?: $this->fail('open failed');
        $magic = fread($fp, 8);
        $this->assertSame("RDUMP\0\0\0", $magic);
        // format version (4) + php_version (4 len + body) + pid (8) +
        // eg (8) + cg (8) + rss_bytes (8, v2) + memory_map_count (4) +
        // region_count (4)
        fread($fp, 4);
        $len = unpack('V', fread($fp, 4))[1];
        fread($fp, $len);
        fread($fp, 8 + 8 + 8 + 8 + 4 + 4);
        // Skip the memory map entries.
        foreach ($memory_areas as $area) {
            // begin + end + file_offset strings
            for ($i = 0; $i < 3; $i++) {
                $l = unpack('V', fread($fp, 4))[1];
                if ($l > 0) {
                    fread($fp, $l);
                }
            }
            // 4 attribute bytes
            fread($fp, 4);
            // device_id string
            $l = unpack('V', fread($fp, 4))[1];
            if ($l > 0) {
                fread($fp, $l);
            }
            // inode (8)
            fread($fp, 8);
            // name string
            $l = unpack('V', fread($fp, 4))[1];
            if ($l > 0) {
                fread($fp, $l);
            }
        }
        // Now walk regions to record their file offsets.
        $region_index = [];
        foreach ($regions as $region) {
            fread($fp, 8); // address
            fread($fp, 8); // size
            $region_index[] = [
                'address' => $region['address'],
                'size' => $region['size'],
                'file_offset' => (int)ftell($fp),
            ];
            fread($fp, $region['size']);
        }
        fclose($fp);

        return new DumpFileMemoryReader(
            $this->tmp_file,
            $region_index,
            new ProcessMemoryMap($memory_areas),
            new MappedPathResolver([]),
        );
    }

    #[Test]
    public function testReadWithinCapturedRegionReturnsBytes(): void
    {
        $reader = $this->makeReader([
            [
                'address' => 0x7f0000000000,
                'size' => 16,
                'data' => "\x41\x42\x43\x44\x45\x46\x47\x48"
                    . "\x49\x4a\x4b\x4c\x4d\x4e\x4f\x50",
            ],
        ]);

        $cdata = $reader->read(1234, 0x7f0000000000, 8);
        $this->assertSame('ABCDEFGH', \FFI::string($cdata, 8));
    }

    #[Test]
    public function testReadWorksEvenIfRegionIndexOrderIsNotSorted(): void
    {
        $regions = [
            [
                'address' => 0x7f0000002000,
                'size' => 8,
                'data' => 'qrstuvwx',
            ],
            [
                'address' => 0x7f0000001000,
                'size' => 8,
                'data' => 'abcdefgh',
            ],
        ];

        (new MemoryDumpWriter())->write(
            $this->tmp_file,
            1234,
            'v84',
            0x1000,
            0x2000,
            [],
            $regions,
        );

        $fp = fopen($this->tmp_file, 'rb') ?: $this->fail('open failed');
        fread($fp, 8); // magic
        fread($fp, 4); // format version
        $len = unpack('V', fread($fp, 4))[1];
        fread($fp, $len);
        // pid (8) + eg (8) + cg (8) + rss_bytes (8, v2)
        // + memory_map_count (4) + region_count (4)
        fread($fp, 8 + 8 + 8 + 8 + 4 + 4);

        $region_index = [];
        foreach ($regions as $region) {
            fread($fp, 8); // address
            fread($fp, 8); // size
            $region_index[] = [
                'address' => $region['address'],
                'size' => $region['size'],
                'file_offset' => (int)ftell($fp),
            ];
            fread($fp, $region['size']);
        }
        fclose($fp);
        $region_index = array_reverse($region_index);

        $reader = new DumpFileMemoryReader(
            $this->tmp_file,
            $region_index,
            new ProcessMemoryMap([]),
            new MappedPathResolver([]),
        );

        $cdata = $reader->read(1234, 0x7f0000001000, 8);
        $this->assertSame('abcdefgh', \FFI::string($cdata, 8));
    }

    #[Test]
    public function testReadPreservesOriginalFileOrderForOverlappingRegions(): void
    {
        $reader = $this->makeReader([
            [
                'address' => 0x7f0000001000,
                'size' => 32,
                'data' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef',
            ],
            [
                'address' => 0x7f0000001010,
                'size' => 8,
                'data' => 'qrstuvwx',
            ],
        ]);

        $cdata = $reader->read(1234, 0x7f0000001010, 8);
        $this->assertSame('QRSTUVWX', \FFI::string($cdata, 8));
    }

    #[Test]
    public function testReadWithAddressNotInDumpRaisesSpecificException(): void
    {
        $reader = $this->makeReader([
            [
                'address' => 0x7f0000000000,
                'size' => 16,
                'data' => str_repeat("\xAA", 16),
            ],
        ]);

        $this->expectException(MemoryAddressNotInDumpException::class);
        $this->expectExceptionMessage('no memory region found for address');
        // Address well outside the captured region.
        $reader->read(1234, 0x7f00DEADBEEF, 8);
    }

    #[Test]
    public function testReadStraddlingRegionBoundaryRaisesSpecificException(): void
    {
        $reader = $this->makeReader([
            [
                'address' => 0x7f0000000000,
                'size' => 16,
                'data' => str_repeat("\xBB", 16),
            ],
        ]);

        // Region is 16 bytes; asking for 32 bytes starting at the region
        // base straddles past the end and must fail specifically.
        $this->expectException(MemoryAddressNotInDumpException::class);
        $reader->read(1234, 0x7f0000000000, 32);
    }

    #[Test]
    public function testDumpExceptionIsSubclassOfReaderException(): void
    {
        // Callers that catch the general reader exception must still
        // see this specialised variant, because the analyzer code
        // paths have catch (MemoryReaderException) in several places.
        $this->assertTrue(
            is_subclass_of(
                MemoryAddressNotInDumpException::class,
                \Reli\Lib\Process\MemoryReader\MemoryReaderException::class,
            ),
        );
    }
}
