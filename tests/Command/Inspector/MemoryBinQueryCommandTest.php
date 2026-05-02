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
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Writer;
use Symfony\Component\Console\Tester\CommandTester;

class MemoryBinQueryCommandTest extends BaseTestCase
{
    private string $rmem_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rmem_path = tempnam(sys_get_temp_dir(), 'reli_binq_test_') . '.rmem';
    }

    protected function tearDown(): void
    {
        @unlink($this->rmem_path);
        parent::tearDown();
    }

    public function testListBinsRendersBinsAndShapes(): void
    {
        $this->writeRmemWithShapeCounts([
            3 => [
                'Bucket(zval IS_STRING)' => [
                    'count' => 2150,
                    'reachable_count' => 150,
                    'confidence' => 'high',
                    'sample_addrs' => [0x7f0000001000, 0x7f0000001020],
                ],
            ],
            13 => [
                'struct stat @+0' => [
                    'count' => 567,
                    'reachable_count' => 0,
                    'confidence' => 'high',
                    'sample_addrs' => [0x7f0000002000],
                ],
            ],
        ]);
        $tester = new CommandTester(new MemoryBinQueryCommand());
        $rc = $tester->execute([
            'rmem-file' => $this->rmem_path,
            '--list-bins' => true,
        ]);
        $this->assertSame(0, $rc);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Bin', $display);
        $this->assertStringContainsString('Bucket(zval IS_STRING)(2150)', $display);
        $this->assertStringContainsString('struct stat @+0(567)', $display);
    }

    public function testListsSampleAddressesForGivenBin(): void
    {
        $this->writeRmemWithShapeCounts([
            3 => [
                'Bucket(zval IS_STRING)' => [
                    'count' => 2150,
                    'reachable_count' => 150,
                    'confidence' => 'high',
                    'sample_addrs' => [
                        0x7f0000001000,
                        0x7f0000001020,
                        0x7f0000001040,
                        0x7f0000001060,
                    ],
                ],
            ],
        ]);
        $tester = new CommandTester(new MemoryBinQueryCommand());
        $rc = $tester->execute([
            'rmem-file' => $this->rmem_path,
            '--bin' => '3',
            '--sample' => '2',
        ]);
        $this->assertSame(0, $rc);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('0x00007f0000001000', $display);
        $this->assertStringContainsString('0x00007f0000001020', $display);
        // Beyond --sample=2 the rest are summarised, not printed.
        $this->assertStringNotContainsString('0x00007f0000001040', $display);
        $this->assertStringContainsString('2 more sample addresses available', $display);
    }

    public function testFiltersByShape(): void
    {
        $this->writeRmemWithShapeCounts([
            3 => [
                'Bucket(zval IS_STRING)' => [
                    'count' => 2150,
                    'reachable_count' => 150,
                    'confidence' => 'high',
                    'sample_addrs' => [0x1000],
                ],
                'Bucket(uninit?)' => [
                    'count' => 50,
                    'reachable_count' => 0,
                    'confidence' => 'low',
                    'sample_addrs' => [0x2000],
                ],
            ],
        ]);
        $tester = new CommandTester(new MemoryBinQueryCommand());
        $rc = $tester->execute([
            'rmem-file' => $this->rmem_path,
            '--bin' => '3',
            '--shape' => 'Bucket(uninit?)',
        ]);
        $this->assertSame(0, $rc);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Bucket(uninit?)', $display);
        $this->assertStringContainsString('0x0000000000002000', $display);
        $this->assertStringNotContainsString('0x0000000000001000', $display);
    }

    public function testListsAddressesForUnclassifiedShape(): void
    {
        // Validator's drill-down ask: when shape detectors miss a slot,
        // it lands under `unclassified` and the user can still pull
        // sample addresses to peek at.
        $this->writeRmemWithShapeCounts([
            3 => [
                'unclassified' => [
                    'count' => 2044,
                    'reachable_count' => 0,
                    'confidence' => 'low',
                    'sample_addrs' => [0x7f00deadbeef, 0x7f00deadcafe],
                ],
            ],
        ]);
        $tester = new CommandTester(new MemoryBinQueryCommand());
        $rc = $tester->execute([
            'rmem-file' => $this->rmem_path,
            '--bin' => '3',
            '--shape' => 'unclassified',
        ]);
        $this->assertSame(0, $rc);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('unclassified', $display);
        $this->assertStringContainsString('0x00007f00deadbeef', $display);
        $this->assertStringContainsString('0x00007f00deadcafe', $display);
    }

    public function testFailsWhenBinUnknown(): void
    {
        $this->writeRmemWithShapeCounts([
            3 => [
                'X' => [
                    'count' => 1,
                    'reachable_count' => 0,
                    'confidence' => 'medium',
                    'sample_addrs' => [],
                ],
            ],
        ]);
        $tester = new CommandTester(new MemoryBinQueryCommand());
        $rc = $tester->execute([
            'rmem-file' => $this->rmem_path,
            '--bin' => '99',
        ]);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('no captures for bin 99', $tester->getDisplay());
    }

    public function testFailsWhenNoBinShapeCountsEntry(): void
    {
        $this->writeRmemWithShapeCounts([]);
        $tester = new CommandTester(new MemoryBinQueryCommand());
        $rc = $tester->execute([
            'rmem-file' => $this->rmem_path,
            '--bin' => '3',
        ]);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString(
            'no bin_shape_counts entry',
            $tester->getDisplay(),
        );
    }

    /**
     * Build a minimal rmem file containing only a string dictionary +
     * a summary section with a `bin_shape_counts` entry. Mirrors the
     * shape produced by analyze.
     *
     * @param array<int, array<string, array{
     *     count: int,
     *     reachable_count: int,
     *     confidence: string,
     *     sample_addrs: list<int>
     * }>> $shape_counts Pass [] to write a summary without the entry.
     */
    private function writeRmemWithShapeCounts(array $shape_counts): void
    {
        $dict = new StringDict();
        $writer = new Writer($this->rmem_path);
        try {
            if ($shape_counts === []) {
                // Empty summary section.
                $writer->writeSection(Format::SECTION_STRING_DICT, $dict->serialize(), $dict->count());
                $writer->writeSection(Format::SECTION_SUMMARY, pack('V', 0), 0);
                return;
            }
            $payload = [
                'bin' => array_map(
                    static function (int $bin_num, array $shapes): array {
                        $rows = [];
                        foreach ($shapes as $label => $row) {
                            $rows[] = [
                                'label' => $label,
                                'count' => $row['count'],
                                'reachable_count' => $row['reachable_count'],
                                'confidence' => $row['confidence'],
                                'sample_addrs' => $row['sample_addrs'],
                            ];
                        }
                        return [
                            'bin_num' => $bin_num,
                            'shapes' => $rows,
                            'unclassified' => 0,
                        ];
                    },
                    array_keys($shape_counts),
                    array_values($shape_counts),
                ),
            ];
            $key_id = $dict->intern('bin_shape_counts');
            $val_id = $dict->intern((string)json_encode($payload));
            $writer->writeSection(Format::SECTION_STRING_DICT, $dict->serialize(), $dict->count());
            $summary = pack('V', 1) . pack('VV', $key_id, $val_id);
            $writer->writeSection(Format::SECTION_SUMMARY, $summary, 1);
        } finally {
            $writer->finish();
        }
    }
}
