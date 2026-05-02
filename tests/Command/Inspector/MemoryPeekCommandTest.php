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

use Reli\BaseTestCase;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Symfony\Component\Console\Tester\CommandTester;

class MemoryPeekCommandTest extends BaseTestCase
{
    private string $rdump_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rdump_path = tempnam(sys_get_temp_dir(), 'reli_peek_test_') . '.rdump';
    }

    protected function tearDown(): void
    {
        @unlink($this->rdump_path);
        parent::tearDown();
    }

    public function testRequiresExactlyOneSource(): void
    {
        $tester = $this->buildTester();
        $rc = $tester->execute(['--address' => '0x1000', '--length' => '16']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString(
            'exactly one of --pid or --rdump',
            $tester->getDisplay(),
        );
    }

    public function testRejectsBadAddress(): void
    {
        $this->writeMinimalRdump([]);
        $tester = $this->buildTester();
        $rc = $tester->execute([
            '--rdump' => $this->rdump_path,
            '--address' => 'garbage',
            '--length' => '16',
        ]);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('could not parse address', $tester->getDisplay());
    }

    public function testRejectsLengthOverCap(): void
    {
        $this->writeMinimalRdump([]);
        $tester = $this->buildTester();
        $rc = $tester->execute([
            '--rdump' => $this->rdump_path,
            '--address' => '0x1000',
            '--length' => (string)(64 * 1024 + 1),
        ]);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('capped', $tester->getDisplay());
    }

    public function testReadsSliceFromRdumpRegion(): void
    {
        $payload = "Hello, reli! " . str_repeat("\x41", 64);
        $this->writeMinimalRdump([
            ['address' => 0x7f0000000000, 'data' => $payload],
        ]);
        $tester = $this->buildTester();
        $rc = $tester->execute([
            '--rdump' => $this->rdump_path,
            '--address' => '0x7f0000000000',
            '--length' => (string)strlen($payload),
        ]);
        $this->assertSame(0, $rc);
        $display = $tester->getDisplay();
        $this->assertStringContainsString(sprintf('%d bytes from 0x7f0000000000', strlen($payload)), $display);
        // The "Hello, reli! " prefix lands in the ASCII gutter of the
        // first hexdump row.
        $this->assertStringContainsString('|Hello, reli! AAA', $display);
    }

    public function testReadsMidRegionSlice(): void
    {
        $payload = str_repeat("A", 16) . str_repeat("B", 16) . str_repeat("C", 16);
        $this->writeMinimalRdump([
            ['address' => 0x1000, 'data' => $payload],
        ]);
        $tester = $this->buildTester();
        // Read 16 bytes starting 16 bytes in — should land squarely on
        // the "B" run.
        $rc = $tester->execute([
            '--rdump' => $this->rdump_path,
            '--address' => '0x1010',
            '--length' => '16',
        ]);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('|BBBBBBBBBBBBBBBB|', $tester->getDisplay());
    }

    public function testFailsWhenAddressNotInAnyRegion(): void
    {
        $this->writeMinimalRdump([
            ['address' => 0x1000, 'data' => 'XYZ'],
        ]);
        $tester = $this->buildTester();
        $rc = $tester->execute([
            '--rdump' => $this->rdump_path,
            '--address' => '0x2000',
            '--length' => '16',
        ]);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString(
            'is not covered by any region',
            $tester->getDisplay(),
        );
    }

    private function buildTester(): CommandTester
    {
        $reader = $this->createMock(MemoryReaderInterface::class);
        return new CommandTester(new MemoryPeekCommand($reader));
    }

    /**
     * Write a minimal rdump file containing only the regions necessary
     * to exercise the peek command. memory_map is empty (count = 0);
     * region count + each region's (address, size, data) follow the
     * v2 header.
     *
     * @param list<array{address: int, data: string}> $regions
     */
    private function writeMinimalRdump(array $regions): void
    {
        $fp = fopen($this->rdump_path, 'wb');
        if ($fp === false) {
            $this->fail('failed to open rdump tmp');
        }
        try {
            fwrite($fp, "RDUMP\0\0\0");
            // version = 2
            fwrite($fp, pack('V', 2));
            // php_version (length-prefixed string)
            $this->writeString($fp, '8.4');
            // pid, eg, cg, rss
            fwrite($fp, pack('P', 1));
            fwrite($fp, pack('P', 0));
            fwrite($fp, pack('P', 0));
            fwrite($fp, pack('P', 0));
            // memory_map_count = 0, region_count = N
            fwrite($fp, pack('V', 0));
            fwrite($fp, pack('V', count($regions)));
            foreach ($regions as $r) {
                fwrite($fp, pack('P', $r['address']));
                fwrite($fp, pack('P', strlen($r['data'])));
                fwrite($fp, $r['data']);
            }
        } finally {
            fclose($fp);
        }
    }

    /** @param resource $fp */
    private function writeString($fp, string $s): void
    {
        fwrite($fp, pack('V', strlen($s)));
        fwrite($fp, $s);
    }
}
