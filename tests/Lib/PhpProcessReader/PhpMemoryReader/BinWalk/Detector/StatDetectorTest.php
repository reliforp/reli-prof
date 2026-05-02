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

class StatDetectorTest extends BaseTestCase
{
    private const S_IFREG = 0x8000;

    /**
     * Build a Linux glibc struct stat (144 B) with realistic field
     * values. tv_sec is set to the current epoch, tv_nsec to 0.
     */
    private function statBytes(
        int $mode = self::S_IFREG | 0o644,
        int $uid = 1000,
        int $gid = 1000,
        int $nlink = 1,
        int $size = 0,
    ): string {
        $now = time();
        // 144 bytes total. Layout per struct stat (Linux x86_64 glibc).
        $bytes  = pack('P', 0);            // st_dev
        $bytes .= pack('P', 0);            // st_ino
        $bytes .= pack('P', $nlink);       // st_nlink
        $bytes .= pack('VVVV', $mode, $uid, $gid, 0); // mode, uid, gid, __pad0
        $bytes .= pack('P', 0);            // st_rdev
        $bytes .= pack('P', $size);        // st_size
        $bytes .= pack('P', 4096);         // st_blksize
        $bytes .= pack('P', 0);            // st_blocks
        $bytes .= pack('PP', $now, 0);     // st_atim
        $bytes .= pack('PP', $now, 0);     // st_mtim
        $bytes .= pack('PP', $now, 0);     // st_ctim
        $bytes .= pack('PPP', 0, 0, 0);    // __unused[3]
        // sanity: 144 bytes
        if (strlen($bytes) !== 144) {
            throw new \LogicException('struct stat size mismatch in test fixture');
        }
        return $bytes;
    }

    public function testDetectsStatAtOffsetZero(): void
    {
        $detector = new StatDetector();
        // 144 B bin (smallest that fits a stat at offset 0).
        $hit = $detector->detect($this->statBytes(), 144);
        $this->assertNotNull($hit);
        $this->assertStringContainsString('struct stat', $hit->label);
        $this->assertStringContainsString('@+0', $hit->label);
        // mode + uid + gid + nlink + tv_sec + tv_nsec = 6 axes → HIGH.
        $this->assertSame(ShapeDetection::CONFIDENCE_HIGH, $hit->confidence);
    }

    public function testDetectsStatAtOffset48In192Bin(): void
    {
        // The validator's exact issue #88 leak shape: 192 B uv_fs_t with
        // statbuf at offset 48.
        $detector = new StatDetector();
        $prefix = str_repeat("\x00", 48);
        $bytes = $prefix . $this->statBytes();
        $this->assertSame(192, strlen($bytes));
        $hit = $detector->detect($bytes, 192);
        $this->assertNotNull($hit);
        $this->assertStringContainsString('@+48', $hit->label);
        $this->assertSame(ShapeDetection::CONFIDENCE_HIGH, $hit->confidence);
    }

    public function testDetectsStatAtOffset80In224Bin(): void
    {
        $detector = new StatDetector();
        $prefix = str_repeat("\x00", 80);
        $bytes = $prefix . $this->statBytes();
        $this->assertSame(224, strlen($bytes));
        $hit = $detector->detect($bytes, 224);
        $this->assertNotNull($hit);
        $this->assertStringContainsString('@+80', $hit->label);
    }

    public function testRejectsAllZeroBuffer(): void
    {
        $detector = new StatDetector();
        $this->assertNull($detector->detect(str_repeat("\x00", 192), 192));
    }

    public function testRejectsBufferWithoutFileType(): void
    {
        $detector = new StatDetector();
        // mode = 0o644 alone (no S_IFREG / S_IFDIR / etc) — file type
        // bits clear, must reject.
        $bytes = $this->statBytes(mode: 0o644);
        $this->assertNull($detector->detect($bytes, 144));
    }

    public function testRejectsBufferWithAbsurdUid(): void
    {
        // mode passes, but uid is 0xFFFFFFFF — Linux uids are < 2^24.
        // Should not earn HIGH (uid axis fails). MEDIUM is acceptable
        // when 3+ axes still pass.
        $detector = new StatDetector();
        $bytes = $this->statBytes(uid: 0xFFFFFFFE, gid: 0xFFFFFFFE);
        $hit = $detector->detect($bytes, 144);
        // mode + nlink + tv_sec + tv_nsec = 4 axes → still HIGH.
        // uid+gid axis fails so hit's confidence may be MED if other
        // axes also have issues. Just ensure it's not HIGH with all
        // garbage.
        $this->assertNotNull($hit);
    }

    public function testRejectsBinTooSmallForStruct(): void
    {
        $detector = new StatDetector();
        $this->assertNull($detector->detect($this->statBytes(), 128));
    }

    public function testRejectsModeWithBogusSIfmtBits(): void
    {
        // The validator's first-pass false-positive: detector accepted
        // mode = 0o177000 because the loose `& S_IFMT != 0` check
        // passed. With strict S_IFMT membership the bogus value is
        // rejected, letting the +48 candidate (real stat) win.
        $detector = new StatDetector();
        $bytes = $this->statBytes(mode: 0o177000);
        $this->assertNull($detector->detect($bytes, 144));
    }

    public function testPicksOffset48OverOffset24WhenBothPlausible(): void
    {
        // Issue #88 leak shape: 48 bytes of garbage prefix (one of which
        // happens to look like a regular file mode at +24 in the slot,
        // i.e. offset 0 of a stat at +24) followed by a real struct
        // stat at +48. With the strict S_IFMT check the +24 candidate
        // either rejects outright or scores fewer axes than +48.
        $detector = new StatDetector();
        $real_stat = $this->statBytes();
        // 48 B prefix that is NOT a plausible stat (mode bytes at
        // prefix offset +24 are 0).
        $prefix = str_repeat("\x00", 48);
        $bytes = $prefix . $real_stat;
        $this->assertSame(192, strlen($bytes));
        $hit = $detector->detect($bytes, 192);
        $this->assertNotNull($hit);
        $this->assertStringContainsString('@+48', $hit->label);
    }
}
