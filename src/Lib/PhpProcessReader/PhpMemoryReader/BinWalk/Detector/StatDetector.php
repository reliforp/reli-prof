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
     * Exact S_IFMT values for the 7 POSIX file types. The mode field
     * must mask down to *exactly* one of these — anything else is
     * garbage. The validator's issue #88 retest landed on the +24
     * candidate offset because the loose `mode & S_IFMT != 0` check
     * accepted `0o177000` (= 0xfe00, which is actually `st_dev` read
     * at the wrong offset). Strict membership rejects that and lets
     * the +48 candidate (the real stat) win.
     */
    private const VALID_S_IFMT = [
        0o100000, // S_IFREG  regular file
        0o040000, // S_IFDIR  directory
        0o020000, // S_IFCHR  character device
        0o060000, // S_IFBLK  block device
        0o010000, // S_IFIFO  FIFO
        0o120000, // S_IFLNK  symlink
        0o140000, // S_IFSOCK socket
    ];

    /**
     * Candidate offsets to scan. Includes the natural 8-byte boundaries
     * the validator actually exhibits (48 for 192 B uv_fs_t, 80 for
     * 224 B variants) plus 4-byte intermediates so we tolerate stat
     * embedded after fields with non-8-byte natural alignment.
     */
    private const OFFSETS = [0, 4, 8, 12, 16, 20, 24, 28, 32, 36, 40, 44, 48, 52, 56, 60, 64, 68, 72, 76, 80];

    /**
     * Minimum number of validation axes a candidate offset must score
     * before we accept the slot. Bumped from the original 3 after the
     * second validator pass found a single-S_IFMT-axis match at @+32
     * winning over the real stat at @+48 — combining mode + uid/gid +
     * nlink + blksize + timespec sanity makes accidental matches
     * essentially impossible.
     */
    private const MIN_AXES = 4;
    private const HIGH_AXES = 5;

    /** Plausible st_blksize values — Linux fs blocksizes. */
    private const VALID_BLKSIZES = [
        512, 1024, 2048, 4096, 8192, 16384, 32768, 65536, 131072,
        262144, 524288, 1048576,
    ];

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
        $best_axes = -1;
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
            $candidate = $this->tryAtOffset($fingerprint, $offset, $visible);
            if ($candidate === null) {
                continue;
            }
            // Tiebreaker: when two offsets pass, the one that earned
            // more validation axes wins. Without this, +24 and +48 can
            // both look HIGH and the first-encountered one (+24) is
            // taken; with tighter S_IFMT the +24 case usually rejects
            // outright now, but keep the granular score so future
            // similar shapes don't regress.
            [$detection, $axes] = $candidate;
            if ($axes > $best_axes) {
                $best = $detection;
                $best_axes = $axes;
            }
        }
        return $best;
    }

    /**
     * @return array{0: ShapeDetection, 1: int}|null Detection + axis
     *     count that earned it (so the caller can rank multi-offset
     *     matches granularly).
     */
    private function tryAtOffset(string $fp, int $offset, int $visible): ?array
    {
        // st_mode @ +24 (4), st_uid @ +28 (4), st_gid @ +32 (4)
        /** @var array{1: int} $m */
        $m = unpack('V', substr($fp, $offset + 24, 4));
        $mode = $m[1];
        if (!in_array($mode & self::S_IFMT, self::VALID_S_IFMT, true)) {
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

        // st_blksize @ +56 (8). Standard Linux fs blocksizes are powers
        // of two from 512 to 1 MiB. Random uint64s essentially never hit
        // that whitelist, so this axis is a strong false-positive killer.
        if ($visible >= 64) {
            /** @var array{1: int} $bs */
            $bs = unpack('P', substr($fp, $offset + 56, 8));
            if (in_array($bs[1], self::VALID_BLKSIZES, true)) {
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

        if ($axes < self::MIN_AXES) {
            return null;
        }

        $confidence = $axes >= self::HIGH_AXES
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

        return [
            new ShapeDetection(
                label: sprintf(
                    'struct stat @+%d (mode 0o%o%s)',
                    $offset,
                    $mode,
                    $size_label,
                ),
                confidence: $confidence,
            ),
            $axes,
        ];
    }
}
