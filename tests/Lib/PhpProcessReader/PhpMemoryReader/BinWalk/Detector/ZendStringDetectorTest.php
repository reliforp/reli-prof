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
     * Build a zend_string-like 24 B header.
     */
    private function fp(int $refcount, int $type_info, int $len): string
    {
        return pack('VVPP', $refcount, $type_info, 0, $len);
    }

    public function testRejectsTooSmallBin(): void
    {
        $detector = new ZendStringDetector();
        $this->assertNull($detector->detect($this->fp(1, 6, 5), 16));
    }

    public function testAcceptsPlausibleHeader(): void
    {
        $detector = new ZendStringDetector();
        // 32 B bin: payload capacity = 32 - 24 - 1 = 7 bytes; len = 5 fits.
        $hit = $detector->detect($this->fp(1, 6, 5), 32);
        $this->assertNotNull($hit);
        $this->assertStringContainsString('zend_string', $hit->label);
        $this->assertStringContainsString('len=5', $hit->label);
    }

    public function testRejectsZeroRefcount(): void
    {
        $detector = new ZendStringDetector();
        $this->assertNull($detector->detect($this->fp(0, 6, 5), 32));
    }

    public function testRejectsAbsurdRefcount(): void
    {
        $detector = new ZendStringDetector();
        // 0xFFFFFFFF is way beyond the sanity ceiling — clear garbage.
        $this->assertNull($detector->detect($this->fp(0xFFFFFFFF, 6, 5), 32));
    }

    public function testRejectsAllOnesTypeInfo(): void
    {
        $detector = new ZendStringDetector();
        $this->assertNull($detector->detect($this->fp(1, 0xFFFFFFFF, 5), 32));
    }

    public function testRejectsOverflowingLength(): void
    {
        $detector = new ZendStringDetector();
        // 32 B bin, payload capacity = 7, len = 100 cannot fit.
        $this->assertNull($detector->detect($this->fp(1, 6, 100), 32));
    }
}
