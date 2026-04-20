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

namespace Reli\Lib\Elf\Process;

use FFI;
use Reli\Lib\FFI\FFIHelper;
use Mockery;
use Reli\BaseTestCase;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

class BinaryFingerprintCreatorTest extends BaseTestCase
{
    private function createMockMap(int $base_address = 0x400000): ProcessModuleMemoryMapInterface
    {
        $map = Mockery::mock(ProcessModuleMemoryMapInterface::class);
        $map->allows()->getBaseAddress()->andReturns($base_address);
        $map->allows()->getDeviceId()->andReturns('00:25');
        $map->allows()->getInodeNumber()->andReturns(99999);
        $map->allows()->getModuleName()->andReturns('/usr/local/bin/php');
        return $map;
    }

    private function createCdata(string $bytes): FFI\CData
    {
        $len = strlen($bytes);
        $cdata = FFIHelper::new("unsigned char[{$len}]");
        for ($i = 0; $i < $len; $i++) {
            $cdata[$i] = ord($bytes[$i]);
        }
        return $cdata;
    }

    public function testCreatesElfHeaderFingerprint(): void
    {
        $elf_header = "\x7fELF" . str_repeat("\x00", 60);

        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $memory_reader->expects()
            ->read(1234, 0x400000, 64)
            ->andReturns($this->createCdata($elf_header));

        $map = $this->createMockMap(0x400000);
        $creator = new BinaryFingerprintCreator($memory_reader);
        $fp = $creator->createFromProcessModuleMemoryMap(1234, $map);

        $this->assertStringContainsString(bin2hex($elf_header), (string)$fp);
    }

    public function testFallsBackToMetadataOnReadFailure(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $memory_reader->expects()
            ->read(1234, 0x400000, 64)
            ->andThrows(new MemoryReaderException('read failed', 14));

        $map = $this->createMockMap(0x400000);
        $creator = new BinaryFingerprintCreator($memory_reader);
        $fp = $creator->createFromProcessModuleMemoryMap(1234, $map);

        $this->assertNotEmpty($fp->getCacheKey());
        $this->assertSame(64, strlen($fp->getCacheKey()));
        $this->assertStringNotContainsString('7f454c46', (string)$fp);
    }

    public function testDifferentElfHeadersProduceDifferentFingerprints(): void
    {
        // Simulates NTS vs ZTS: same metadata but different ELF headers (e_phnum differs)
        $header_nts = "\x7fELF\x02\x01\x01\x03" . str_repeat("\x00", 52) . "\x0e\x00\x1e\x00";
        $header_zts = "\x7fELF\x02\x01\x01\x03" . str_repeat("\x00", 52) . "\x0f\x00\x1f\x00";

        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $memory_reader->allows()
            ->read(100, 0x400000, 64)
            ->andReturns($this->createCdata($header_nts));
        $memory_reader->allows()
            ->read(200, 0x400000, 64)
            ->andReturns($this->createCdata($header_zts));

        // Same base address and metadata — only ELF header content differs
        $map_nts = $this->createMockMap(0x400000);
        $map_zts = $this->createMockMap(0x400000);

        $creator = new BinaryFingerprintCreator($memory_reader);
        $fp_nts = $creator->createFromProcessModuleMemoryMap(100, $map_nts);
        $fp_zts = $creator->createFromProcessModuleMemoryMap(200, $map_zts);

        $this->assertNotSame($fp_nts->getCacheKey(), $fp_zts->getCacheKey());
    }

    public function testSameElfHeaderProducesSameFingerprint(): void
    {
        $header = "\x7fELF" . str_repeat("\xAA", 60);

        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $memory_reader->expects()
            ->read(100, 0x400000, 64)
            ->andReturns($this->createCdata($header))
            ->twice();

        $map = $this->createMockMap(0x400000);
        $creator = new BinaryFingerprintCreator($memory_reader);
        $fp1 = $creator->createFromProcessModuleMemoryMap(100, $map);
        $fp2 = $creator->createFromProcessModuleMemoryMap(100, $map);

        $this->assertSame($fp1->getCacheKey(), $fp2->getCacheKey());
    }
}
