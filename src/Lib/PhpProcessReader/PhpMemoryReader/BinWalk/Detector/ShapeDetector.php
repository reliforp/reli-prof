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
 * Best-effort decoder for the byte shape of a single bin slot.
 *
 * The bin walker collects the first ~24 bytes of every live small slot
 * as a fingerprint; detectors get those bytes plus the slot size and may
 * return a {@see ShapeDetection} when the bytes match a known structural
 * pattern (Bucket header, zend_string header, …).
 *
 * Detectors are intentionally header-only — they can match on the first
 * 24 B fingerprint alone, no follow-up reads of the heap. That keeps the
 * detector pipeline a pure function over already-collected data and lets
 * us run it in PeriodicityDetector without touching the live target.
 *
 * Reachability filtering (the design's "negative-evidence rule") is
 * applied separately by the caller; detectors should report any structural
 * match they see and let downstream code suppress it when the slot is
 * already claimed by the root walker.
 */
interface ShapeDetector
{
    public function name(): string;

    /**
     * Inspect the slot's first-24-byte fingerprint and bin size; return
     * a labelled detection or null when the bytes don't match the
     * detector's expected shape.
     */
    public function detect(string $fingerprint, int $bin_size): ?ShapeDetection;
}
