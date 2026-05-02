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

class ZendStringDetectorTest extends BaseTestCase
{
    /**
     * Build a zend_string-like 24 B header (no payload — strict checks
     * gracefully degrade because the NUL terminator falls past the
     * fingerprint horizon).
     */
    private function header(int $refcount, int $type_info, int $len): string
    {
        return pack('VVPP', $refcount, $type_info, 0, $len);
    }

    /**
     * Build a full-slot fingerprint: header + payload + NUL terminator
     * + zero-padding to fill the slot.
     */
    private function full(int $refcount, int $hash, string $payload, int $bin_size): string
    {
        $header = pack('VVPP', $refcount, 6, $hash, strlen($payload));
        $body = $header . $payload . "\x00";
        return $body . str_repeat("\x00", max(0, $bin_size - strlen($body)));
    }

    public function testRejectsTooSmallBin(): void
    {
        $detector = new ZendStringDetector();
        $this->assertNull($detector->detect($this->header(1, 6, 5), 16));
    }

    public function testHeaderOnlyMatchReturnsMediumWhenPayloadOutsideFingerprint(): void
    {
        $detector = new ZendStringDetector();
        // 24 B fingerprint can't see the NUL at +29; falls back to
        // MEDIUM-on-header-only.
        $hit = $detector->detect($this->header(1, 6, 5), 32);
        $this->assertNotNull($hit);
        $this->assertSame(ShapeDetection::CONFIDENCE_MEDIUM, $hit->confidence);
    }

    public function testRejectsZeroRefcount(): void
    {
        $detector = new ZendStringDetector();
        $this->assertNull($detector->detect($this->header(0, 6, 5), 32));
    }

    public function testRejectsAbsurdRefcount(): void
    {
        $detector = new ZendStringDetector();
        $this->assertNull($detector->detect($this->header(0xFFFFFFFF, 6, 5), 32));
    }

    public function testRejectsAllOnesTypeInfo(): void
    {
        $detector = new ZendStringDetector();
        $this->assertNull($detector->detect($this->header(1, 0xFFFFFFFF, 5), 32));
    }

    public function testRejectsOverflowingLength(): void
    {
        $detector = new ZendStringDetector();
        $this->assertNull($detector->detect($this->header(1, 6, 100), 32));
    }

    public function testRejectsMissingNulTerminator(): void
    {
        $detector = new ZendStringDetector();
        // 64 B bin, claim len=5, but write garbage at +29 instead of NUL.
        $header = pack('VVPP', 1, 6, 0, 5);
        $payload = 'hello';
        $broken = $header . $payload . "X" . str_repeat("\x00", 64 - 30);
        $this->assertNull($detector->detect($broken, 64));
    }

    public function testRejectsNonPrintablePayload(): void
    {
        $detector = new ZendStringDetector();
        // len=5, NUL at right place, but payload is binary garbage.
        $bin = $this->full(1, 0, "\xff\xfe\xfd\xfc\xfb", 64);
        $this->assertNull($detector->detect($bin, 64));
    }

    public function testAcceptsPrintablePayloadAtMedium(): void
    {
        $detector = new ZendStringDetector();
        // hash = 0 → no DJB2 promotion.
        $bin = $this->full(1, 0, 'hello', 64);
        $hit = $detector->detect($bin, 64);
        $this->assertNotNull($hit);
        $this->assertSame(ShapeDetection::CONFIDENCE_MEDIUM, $hit->confidence);
        $this->assertStringContainsString('len=5', $hit->label);
    }

    public function testPromotesToHighOnDjb2Match(): void
    {
        $detector = new ZendStringDetector();
        $payload = 'hello';
        // Compute DJB2 the same way the detector does.
        $hash = 5381;
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            $hash = (($hash << 5) + $hash + ord($payload[$i])) & 0x7FFFFFFFFFFFFFFF;
        }
        $bin = $this->full(1, $hash, $payload, 64);
        $hit = $detector->detect($bin, 64);
        $this->assertNotNull($hit);
        $this->assertSame(ShapeDetection::CONFIDENCE_HIGH, $hit->confidence);
    }

    public function testHashMismatchKeepsMedium(): void
    {
        $detector = new ZendStringDetector();
        // Set a non-zero hash that doesn't match DJB2('hello').
        $bin = $this->full(1, 0xdeadbeef, 'hello', 64);
        $hit = $detector->detect($bin, 64);
        $this->assertNotNull($hit);
        $this->assertSame(ShapeDetection::CONFIDENCE_MEDIUM, $hit->confidence);
    }
}
