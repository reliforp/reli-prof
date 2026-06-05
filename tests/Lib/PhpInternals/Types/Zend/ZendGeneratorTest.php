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

namespace Reli\Lib\PhpInternals\Types\Zend;

use PHPUnit\Framework\TestCase;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;

class ZendGeneratorTest extends TestCase
{
    /**
     * Build a zend_generator backed by a zeroed buffer, then poke the four
     * 8-byte node slots so getNodeRawGeneratorPointers() can be exercised
     * without a live process.
     */
    private function makeGenerator(ZendTypeReader $reader, array $node_slots): ZendGenerator
    {
        $size = $reader->sizeOf('zend_generator');
        [$node_offset] = $reader->getOffsetAndSizeOfMember('zend_generator', 'node');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");
        foreach ($node_slots as $slot => $value) {
            $packed = pack('P', $value);
            for ($b = 0; $b < 8; $b++) {
                $raw[$node_offset + $slot * 8 + $b] = \ord($packed[$b]);
            }
        }
        $casted = $reader->readAs('zend_generator', $raw);
        return new ZendGenerator(
            $casted,
            new Pointer(ZendGenerator::class, 0x1000, $size),
        );
    }

    public function testExtractsNonZeroNodePointers(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        $generator = $this->makeGenerator($reader, [
            0 => 0x7f0000001000,
            1 => 0x7f0000002000,
            2 => 0x7f0000003000,
            3 => 0x7f0000004000,
        ]);

        $this->assertSame(
            [0x7f0000001000, 0x7f0000002000, 0x7f0000003000, 0x7f0000004000],
            $generator->getNodeRawGeneratorPointers($reader),
        );
    }

    public function testFiltersZeroNodeSlots(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        // Only parent (slot 0) and the leaves.first slot (2) are set; the
        // child/leaf and last slots are NULL and must be dropped.
        $generator = $this->makeGenerator($reader, [
            0 => 0x7f0000001000,
            1 => 0,
            2 => 0x7f0000003000,
            3 => 0,
        ]);

        $this->assertSame(
            [0x7f0000001000, 0x7f0000003000],
            $generator->getNodeRawGeneratorPointers($reader),
        );
    }

    public function testReturnsEmptyWhenAllNodeSlotsZero(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        $generator = $this->makeGenerator($reader, []);

        $this->assertSame([], $generator->getNodeRawGeneratorPointers($reader));
    }
}
