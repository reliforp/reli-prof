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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector;

use Reli\BaseTestCase;

class OpArrayDetectorTest extends BaseTestCase
{
    /**
     * Build a 32 B sub-block whose first 8 bytes look like a `.text`
     * pointer; remaining 24 bytes are arbitrary small ints (the validator's
     * issue #88 hex showed `[.text ptr][24 B small ints]` per opcode).
     */
    private function block(int $textPtr): string
    {
        return pack('P', $textPtr) . pack('PPP', 0, 0, 0);
    }

    public function testRejectsBinTooSmallForMinHits(): void
    {
        $detector = new OpArrayDetector();
        // 64 B fits 2 blocks; need 3 → reject.
        $this->assertNull($detector->detect(str_repeat("\x00", 64), 64));
    }

    public function testEmitsMediumOnThreeBlockRun(): void
    {
        $detector = new OpArrayDetector();
        $bytes = $this->block(0x00007ffe1234c000)
            . $this->block(0x00007ffe1234c100)
            . $this->block(0x00007ffe1234c200);
        $this->assertSame(96, strlen($bytes));
        $hit = $detector->detect($bytes, 96);
        $this->assertNotNull($hit);
        $this->assertSame(ShapeDetection::CONFIDENCE_MEDIUM, $hit->confidence);
        $this->assertStringContainsString('3 ×', $hit->label);
    }

    public function testEmitsHighOnFiveBlockRun(): void
    {
        $detector = new OpArrayDetector();
        $bytes = '';
        for ($i = 0; $i < 7; $i++) {
            $bytes .= $this->block(0x00007ffe1234c000 + $i * 0x100);
        }
        $this->assertSame(224, strlen($bytes));
        $hit = $detector->detect($bytes, 224);
        $this->assertNotNull($hit);
        $this->assertSame(ShapeDetection::CONFIDENCE_HIGH, $hit->confidence);
        $this->assertStringContainsString('7 ×', $hit->label);
    }

    public function testStopsAtFirstNonPointerBlock(): void
    {
        $detector = new OpArrayDetector();
        // Two valid blocks then a small int — the run is broken at
        // block 3, so only 2 blocks count → below MIN_HITS → reject.
        $bytes = $this->block(0x00007ffe1234c000)
            . $this->block(0x00007ffe1234c100)
            . pack('P', 0x42) . pack('PPP', 0, 0, 0);
        $this->assertNull($detector->detect($bytes, 96));
    }

    public function testRejectsAllZeroBuffer(): void
    {
        $detector = new OpArrayDetector();
        $this->assertNull($detector->detect(str_repeat("\x00", 224), 224));
    }

    public function testAcceptsByteAlignedHandlerPointers(): void
    {
        // Validator's issue #88 retest: opcode handler addresses end
        // in arbitrary bytes (0x5a, 0xee, 0xb5, 0xe0, 0x01) because
        // execute_ex's computed-goto labels land at byte-granular
        // offsets. The previous 4-byte alignment check rejected these
        // and left bin[14] 1037-slot unclassified; relaxing the check
        // restores the match.
        $detector = new OpArrayDetector();
        $bytes = $this->block(0x00005570b9eab05a) // ends in 0x5a
            . $this->block(0x00005570b9ea92ee)    // ends in 0xee
            . $this->block(0x00005570b9eabdb5)    // ends in 0xb5
            . $this->block(0x00005570b9ea78e0)    // ends in 0xe0
            . $this->block(0x00005570b9ea7701);   // ends in 0x01
        $this->assertSame(160, strlen($bytes));
        $hit = $detector->detect($bytes, 160);
        $this->assertNotNull($hit);
        $this->assertStringContainsString('5 ×', $hit->label);
        // 5 hits → HIGH per the detector's threshold.
        $this->assertSame(ShapeDetection::CONFIDENCE_HIGH, $hit->confidence);
    }
}
