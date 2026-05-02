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
}
