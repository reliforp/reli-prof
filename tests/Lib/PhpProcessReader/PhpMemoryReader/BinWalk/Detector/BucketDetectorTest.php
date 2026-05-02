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

class BucketDetectorTest extends BaseTestCase
{
    /**
     * Build a 24 B fingerprint with the given zval-type byte at offset 8.
     */
    private function fp(int $type_byte): string
    {
        // value: 8 bytes, type/type_info: 8 bytes (type at +8), hash: 8 bytes
        $val = pack('P', 0x1234567890abcdef);
        $type = pack('CCCCV', $type_byte, 0, 0, 0, 0); // type_byte at offset 0
        $hash = pack('P', 0xdeadbeefcafebabe);
        return $val . $type . $hash;
    }

    public function testRejectsNonThirtyTwoByteBins(): void
    {
        $detector = new BucketDetector();
        $this->assertNull($detector->detect($this->fp(6), 16));
        $this->assertNull($detector->detect($this->fp(6), 64));
    }

    public function testRejectsShortFingerprint(): void
    {
        $detector = new BucketDetector();
        $this->assertNull($detector->detect(str_repeat("\x00", 10), 32));
    }

    public function testAcceptsValidZvalType(): void
    {
        $detector = new BucketDetector();
        $hit = $detector->detect($this->fp(6), 32); // IS_STRING
        $this->assertNotNull($hit);
        $this->assertStringContainsString('Bucket', $hit->label);
        $this->assertStringContainsString('IS_STRING', $hit->label);
        $this->assertSame(ShapeDetection::CONFIDENCE_MEDIUM, $hit->confidence);
    }

    public function testRejectsInvalidZvalType(): void
    {
        $detector = new BucketDetector();
        // Type byte 99 is well outside the [0, 21] zval-type whitelist.
        $this->assertNull($detector->detect($this->fp(99), 32));
    }

    public function testHandlesAllValidZvalTypeRange(): void
    {
        $detector = new BucketDetector();
        for ($i = 0; $i <= 21; $i++) {
            $this->assertNotNull(
                $detector->detect($this->fp($i), 32),
                "type byte {$i} should be accepted",
            );
        }
        $this->assertNull($detector->detect($this->fp(22), 32));
    }
}
