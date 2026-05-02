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
     * Build a 32 B fingerprint with the given zval-type byte at offset 8.
     *
     * Uses a plausible userspace pointer (8-aligned, well below the
     * 2^47 ceiling) at +0..+7 so the new pointer-sanity check passes
     * for pointer-bearing types. Hash is non-zero so the all-zero
     * "Bucket(uninit?)" branch isn't taken.
     */
    private function fp(int $type_byte): string
    {
        $val = pack('P', 0x00007ffe1234c000);
        $type = pack('CCCCV', $type_byte, 0, 0, 0, 0);
        $hash = pack('P', 0xcafebabe);
        $key = pack('P', 0x00007ffe1234d000);
        return $val . $type . $hash . $key;
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
        // Type byte 99 is well past the deep-internal cutoff (15).
        $this->assertNull($detector->detect($this->fp(99), 32));
    }

    public function testHandlesValidZvalTypeRange(): void
    {
        $detector = new BucketDetector();
        // Types 0..15 are all the symbols we accept (incl. the new
        // IS_INDIRECT/IS_PTR/IS_ALIAS_PTR mappings).
        for ($i = 0; $i <= 15; $i++) {
            $this->assertNotNull(
                $detector->detect($this->fp($i), 32),
                "type byte {$i} should be accepted",
            );
        }
        // 16+ rejects (deep internals).
        $this->assertNull($detector->detect($this->fp(16), 32));
    }

    public function testRejectsPointerTypeWithZeroValue(): void
    {
        $detector = new BucketDetector();
        // Pointer-bearing types (IS_STRING etc) with value=0 reject —
        // the validator's "+15 each in 13 zval_type variants" garbage
        // is exactly this shape.
        $zero_val = pack('P', 0)
            . pack('CCCCV', 6 /* IS_STRING */, 0, 0, 0, 0)
            . pack('P', 0xcafebabe)
            . pack('P', 0x00007ffe1234d000);
        $this->assertNull($detector->detect($zero_val, 32));
    }

    public function testCollapsesAllZeroBucketToUninitLabel(): void
    {
        $detector = new BucketDetector();
        $all_zero = str_repeat("\x00", 32);
        $hit = $detector->detect($all_zero, 32);
        $this->assertNotNull($hit);
        $this->assertSame('Bucket(uninit?)', $hit->label);
    }

    public function testSymbolicatesIndirectAndPtr(): void
    {
        $detector = new BucketDetector();
        $hit_indirect = $detector->detect($this->fp(13), 32);
        $this->assertNotNull($hit_indirect);
        $this->assertStringContainsString('IS_INDIRECT', $hit_indirect->label);
        $hit_ptr = $detector->detect($this->fp(14), 32);
        $this->assertNotNull($hit_ptr);
        $this->assertStringContainsString('IS_PTR', $hit_ptr->label);
        $hit_alias = $detector->detect($this->fp(15), 32);
        $this->assertNotNull($hit_alias);
        $this->assertStringContainsString('IS_ALIAS_PTR', $hit_alias->label);
    }
}
