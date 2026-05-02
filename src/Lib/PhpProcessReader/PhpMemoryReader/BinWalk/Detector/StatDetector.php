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

/**
 * Detect Linux glibc `struct stat`-shaped buffers.
 *
 * Layout (Linux x86_64 glibc, 144 B):
 *
 *   +0    dev_t     st_dev      (8)
 *   +8    ino_t     st_ino      (8)
 *   +16   nlink_t   st_nlink    (8)
 *   +24   mode_t    st_mode     (4)
 *   +28   uid_t     st_uid      (4)
 *   +32   gid_t     st_gid      (4)
 *   +36   int       __pad0      (4)
 *   +40   dev_t     st_rdev     (8)
 *   +48   off_t     st_size     (8)
 *   +56   blksize_t st_blksize  (8)
 *   +64   blkcnt_t  st_blocks   (8)
 *   +72   timespec  st_atim     (16: sec + nsec)
 *   +88   timespec  st_mtim     (16)
 *   +104  timespec  st_ctim     (16)
 *   +120  long      __unused[3] (24)
 *
 * The validator's issue #88 leak holds a struct stat at offset 48 inside
 * a 192 B slot (uv_fs_t.statbuf field) and at offset 80 inside a 224 B
 * slot. Detection must therefore scan a few candidate offsets — we try
 * the most plausible ones (0, 16, 32, 40, 48, 64, 80) up to the largest
 * offset that still leaves room for the 144 B struct in the slot.
 *
 * Confidence axes (the design's HIGH bar is "all five aligned"):
 *   1. mode has S_IFMT bits set (regular file / dir / symlink / …)
 *   2. uid + gid are sub-2^24 (Linux convention; bigger means garbage)
 *   3. nlink is 1..2^16 (huge nlinks are nonsense for a real inode)
 *   4. at least one timespec has tv_sec within ±50 years of now
 *   5. that same timespec's tv_nsec is < 1e9
 *
 * Three-axis match → MEDIUM, four-or-more → HIGH. Below 3 → null.
 *
 * Detector returns the offset where the stat was found in the label
 * so users can `inspector:memory:peek` straight at the right region:
 * `struct stat @+48 (mode 0o100644, size 0)`.
 */
final class StatDetector implements ShapeDetector
{
    private const STAT_SIZE = 144;
    private const SECONDS_PER_YEAR = 31_557_600;
    private const TIME_WINDOW_YEARS = 50;
    /** Linux S_IFMT mask covers bits 12..15 of mode. */
    private const S_IFMT = 0xF000;

    /**
     * Candidate offsets to scan. Includes natural boundaries (0, multiples
     * of 8) plus the ones the validator's traffic actually exhibited
     * (48 for 192 B uv_fs_t, 80 for 224 B uv_fs_t variants).
     */
    private const OFFSETS = [0, 8, 16, 24, 32, 40, 48, 56, 64, 72, 80];

    #[\Override]
    public function name(): string
    {
        return 'StatDetector';
    }

    #[\Override]
    public function detect(string $fingerprint, int $bin_size): ?ShapeDetection
    {
        // Must fit a struct stat somewhere — even with offset 0 we need
        // the full 144 B.
        if ($bin_size < self::STAT_SIZE) {
            return null;
        }
        $fp_len = strlen($fingerprint);

        $best = null;
        foreach (self::OFFSETS as $offset) {
            if ($offset + self::STAT_SIZE > $bin_size) {
                continue;
            }
            // Visible window for this candidate: how much of the stat
            // does the fingerprint cover?
            $visible_end = min($fp_len, $offset + self::STAT_SIZE);
            $visible = $visible_end - $offset;
            if ($visible < 36) {
                // Below mode + uid + gid we can't even start scoring.
                continue;
            }
            $hit = $this->tryAtOffset($fingerprint, $offset, $visible);
            if ($hit === null) {
                continue;
            }
            if (
                $best === null
                || $this->scoreOf($hit) > $this->scoreOf($best)
            ) {
                $best = $hit;
            }
        }
        return $best;
    }

    /**
     * @return ShapeDetection|null
     */
    private function tryAtOffset(string $fp, int $offset, int $visible): ?ShapeDetection
    {
        // st_mode @ +24 (4), st_uid @ +28 (4), st_gid @ +32 (4)
        /** @var array{1: int} $m */
        $m = unpack('V', substr($fp, $offset + 24, 4));
        $mode = $m[1];
        if (($mode & self::S_IFMT) === 0) {
            return null;
        }

        /** @var array{1: int} $u */
        $u = unpack('V', substr($fp, $offset + 28, 4));
        $uid = $u[1];
        /** @var array{1: int} $g */
        $g = unpack('V', substr($fp, $offset + 32, 4));
        $gid = $g[1];
        $axes = 1; // mode passed
        if ($uid < 0x1000000 && $gid < 0x1000000) {
            $axes++;
        }

        // st_nlink @ +16 (8 on x86_64). Only count if visible.
        if ($visible >= 24) {
            /** @var array{1: int} $n */
            $n = unpack('P', substr($fp, $offset + 16, 8));
            $nlink = $n[1];
            if ($nlink >= 1 && $nlink < 0x10000) {
                $axes++;
            }
        }

        // Timespecs at +72 / +88 / +104. Visible at all?
        if ($visible >= 80) {
            $now = time();
            $window = self::TIME_WINDOW_YEARS * self::SECONDS_PER_YEAR;
            $any_time_ok = false;
            $any_nsec_ok = false;
            foreach ([72, 88, 104] as $ts_off) {
                if ($visible < $ts_off + 16) {
                    break;
                }
                /** @var array{1: int} $sec */
                $sec = unpack('P', substr($fp, $offset + $ts_off, 8));
                /** @var array{1: int} $nsec */
                $nsec = unpack('P', substr($fp, $offset + $ts_off + 8, 8));
                if (
                    $sec[1] > 0
                    && abs($sec[1] - $now) <= $window
                ) {
                    $any_time_ok = true;
                }
                if ($nsec[1] >= 0 && $nsec[1] < 1_000_000_000) {
                    $any_nsec_ok = true;
                }
            }
            if ($any_time_ok) {
                $axes++;
            }
            if ($any_nsec_ok) {
                $axes++;
            }
        }

        if ($axes < 3) {
            return null;
        }

        $confidence = $axes >= 4
            ? ShapeDetection::CONFIDENCE_HIGH
            : ShapeDetection::CONFIDENCE_MEDIUM;

        // Format mode as octal so the user's eye can match it against
        // their `peek` output (validator showed mode 0o100644).
        $size_label = '';
        if ($visible >= 56) {
            /** @var array{1: int} $sz */
            $sz = unpack('P', substr($fp, $offset + 48, 8));
            $size_label = sprintf(', size %d', $sz[1]);
        }

        return new ShapeDetection(
            label: sprintf(
                'struct stat @+%d (mode 0o%o%s)',
                $offset,
                $mode,
                $size_label,
            ),
            confidence: $confidence,
        );
    }

    private function scoreOf(ShapeDetection $d): int
    {
        return $d->confidence === ShapeDetection::CONFIDENCE_HIGH ? 2 : 1;
    }
}
