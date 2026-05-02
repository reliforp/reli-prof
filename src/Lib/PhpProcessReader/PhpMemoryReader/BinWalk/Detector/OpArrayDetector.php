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
 * Detect 32-byte-stride structures whose every element starts with a
 * pointer that looks like a code-segment (`.text`) address.
 *
 * The validator's issue #88 retest had ~2,000 unclassified 224 B slots
 * laid out as
 *
 *     [.text ptr][24 B small ints + flags] × 7  → 7 × 32 B = 224
 *
 * That's the shape of a 7-instruction `zend_op` array, but it might
 * also be any other PHP-internal (or libuv-internal) function-pointer
 * table laid out the same way. Without dragging in a full symbol
 * resolver — the design's HIGH-confidence FunctionPointerDetector,
 * deferred to Phase 2.5 — we can still surface "this looks like a
 * function-pointer-bearing struct of N elements" so the slots stop
 * showing up as `unclassified`.
 *
 * Validation:
 *   - At least 3 consecutive 32-byte sub-blocks each start with a
 *     plausible `.text` pointer (in [0x100000, POINTER_MAX), 4-byte
 *     aligned to match common ELF text alignment).
 *   - The total length stays within the slot.
 *
 * Confidence:
 *   - 3-4 sub-blocks → MEDIUM (could still be coincidence).
 *   - 5+ sub-blocks  → HIGH (the chance of a 5-pointer .text run by
 *     accident is negligible).
 *
 * Symbol resolution (resolving the leading `.text` ptr to e.g.
 * `execute_ex+0x...` or `php_uv_*`) is the design's negative-evidence
 * promotion path and remains future work.
 */
final class OpArrayDetector implements ShapeDetector
{
    private const STRIDE = 32;
    private const MIN_HITS = 3;
    private const HIGH_CONFIDENCE_HITS = 5;

    /**
     * Lower bound for `.text` pointer sanity. ELF executables on Linux
     * x86_64 typically map `.text` at 0x400000+; PIE and dynamic
     * libraries can be anywhere from 0x100000 upward.
     */
    private const TEXT_MIN = 0x100000;

    /** Userspace ceiling — same as other detectors. */
    private const TEXT_MAX = 0x800000000000;

    #[\Override]
    public function name(): string
    {
        return 'OpArrayDetector';
    }

    #[\Override]
    public function detect(string $fingerprint, int $bin_size): ?ShapeDetection
    {
        if ($bin_size < self::STRIDE * self::MIN_HITS) {
            return null;
        }
        $fp_len = strlen($fingerprint);
        $max_in_slot = intdiv($bin_size, self::STRIDE);
        $max_in_fp = intdiv($fp_len, self::STRIDE);
        $max_ops = min($max_in_slot, $max_in_fp);
        if ($max_ops < self::MIN_HITS) {
            return null;
        }

        $hits = 0;
        for ($i = 0; $i < $max_ops; $i++) {
            $offset = $i * self::STRIDE;
            /** @var array{1: int} $u */
            $u = unpack('P', substr($fingerprint, $offset, 8));
            $ptr = $u[1];
            if (!$this->looksLikeTextPointer($ptr)) {
                break; // Run broken — count consecutive only.
            }
            $hits++;
        }

        if ($hits < self::MIN_HITS) {
            return null;
        }

        $confidence = $hits >= self::HIGH_CONFIDENCE_HITS
            ? ShapeDetection::CONFIDENCE_HIGH
            : ShapeDetection::CONFIDENCE_MEDIUM;

        return new ShapeDetection(
            label: sprintf('func_ptr_array(%d × 32 B)', $hits),
            confidence: $confidence,
        );
    }

    private function looksLikeTextPointer(int $ptr): bool
    {
        if ($ptr <= 0 || $ptr >= self::TEXT_MAX) {
            return false;
        }
        if ($ptr < self::TEXT_MIN) {
            return false;
        }
        // ELF function entries are typically 16-byte aligned but we
        // accept 4-byte alignment to tolerate older toolchains and
        // odd JIT-emitted code.
        return ($ptr & 0x3) === 0;
    }
}
