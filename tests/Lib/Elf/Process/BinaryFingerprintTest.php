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

use Mockery;
use Reli\BaseTestCase;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;

class BinaryFingerprintTest extends BaseTestCase
{
    private function createMockMap(string $device_id, int $inode, string $name): ProcessModuleMemoryMapInterface
    {
        $map = Mockery::mock(ProcessModuleMemoryMapInterface::class);
        $map->allows()->getDeviceId()->andReturns($device_id);
        $map->allows()->getInodeNumber()->andReturns($inode);
        $map->allows()->getModuleName()->andReturns($name);
        return $map;
    }

    public function testFromProcessModuleMemoryMapDeterministic(): void
    {
        $map = $this->createMockMap('00:25', 12345, '/usr/local/bin/php');

        $fp1 = BinaryFingerprint::fromProcessModuleMemoryMap($map);
        $fp2 = BinaryFingerprint::fromProcessModuleMemoryMap($map);

        $this->assertSame($fp1->getCacheKey(), $fp2->getCacheKey());
    }

    public function testDifferentInodesProduceDifferentFingerprints(): void
    {
        $map1 = $this->createMockMap('00:25', 12345, '/usr/local/bin/php');
        $map2 = $this->createMockMap('00:25', 67890, '/usr/local/bin/php');

        $fp1 = BinaryFingerprint::fromProcessModuleMemoryMap($map1);
        $fp2 = BinaryFingerprint::fromProcessModuleMemoryMap($map2);

        $this->assertNotSame($fp1->getCacheKey(), $fp2->getCacheKey());
    }

    public function testDifferentDeviceIdsProduceDifferentFingerprints(): void
    {
        $map1 = $this->createMockMap('00:25', 12345, '/usr/local/bin/php');
        $map2 = $this->createMockMap('00:23', 12345, '/usr/local/bin/php');

        $fp1 = BinaryFingerprint::fromProcessModuleMemoryMap($map1);
        $fp2 = BinaryFingerprint::fromProcessModuleMemoryMap($map2);

        $this->assertNotSame($fp1->getCacheKey(), $fp2->getCacheKey());
    }

    public function testFromProcessModuleMemoryMapAndElfHeaderDistinguishesNtsZts(): void
    {
        $map = $this->createMockMap('00:25', 12345, '/usr/local/bin/php');

        $elf_header_nts = str_repeat("\x00", 64);
        $elf_header_zts = str_repeat("\x01", 64);

        $fp_nts = BinaryFingerprint::fromProcessModuleMemoryMapAndElfHeader($map, $elf_header_nts);
        $fp_zts = BinaryFingerprint::fromProcessModuleMemoryMapAndElfHeader($map, $elf_header_zts);

        $this->assertNotSame($fp_nts->getCacheKey(), $fp_zts->getCacheKey());
    }

    public function testElfHeaderFingerprintDiffersFromMetadataOnly(): void
    {
        $map = $this->createMockMap('00:25', 12345, '/usr/local/bin/php');

        $fp_metadata = BinaryFingerprint::fromProcessModuleMemoryMap($map);
        $fp_with_elf = BinaryFingerprint::fromProcessModuleMemoryMapAndElfHeader(
            $map,
            str_repeat("\x7f", 64),
        );

        $this->assertNotSame($fp_metadata->getCacheKey(), $fp_with_elf->getCacheKey());
    }

    public function testSameElfHeaderProducesSameFingerprint(): void
    {
        $map = $this->createMockMap('00:25', 12345, '/usr/local/bin/php');
        $elf_header = "\x7fELF" . str_repeat("\x00", 60);

        $fp1 = BinaryFingerprint::fromProcessModuleMemoryMapAndElfHeader($map, $elf_header);
        $fp2 = BinaryFingerprint::fromProcessModuleMemoryMapAndElfHeader($map, $elf_header);

        $this->assertSame($fp1->getCacheKey(), $fp2->getCacheKey());
    }

    public function testToString(): void
    {
        $fp = new BinaryFingerprint('test_value');
        $this->assertSame('test_value', (string)$fp);
    }

    public function testGetCacheKeyIsSha256(): void
    {
        $fp = new BinaryFingerprint('test');
        $this->assertSame(64, strlen($fp->getCacheKey()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fp->getCacheKey());
    }
}
