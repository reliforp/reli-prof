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
 * Detect zend_string headers + payload.
 *
 * Layout (Linux x86_64 PHP 7.x / 8.x):
 *
 *   +0..+7   zend_refcounted_h gc  (refcount u32 + u32 type_info)
 *   +8..+15  zend_ulong h          (hash, may be 0 for never-hashed)
 *   +16..+23 size_t len            (string length, NUL excluded)
 *   +24..    char val[1]           (payload, NUL terminated)
 *
 * Header-only validation is far too permissive — the validator's
 * issue #88 run found 1842 `zend_string(len=2)` matches against a real
 * count of 27. Tightening adds, in order of cheapness:
 *
 *  1. NUL-terminator at `+24+len` (rejects most false positives by
 *     itself — random bytes almost never have a NUL exactly there).
 *  2. ASCII-printable ratio of payload ≥ 70% (PHP-internal strings
 *     are ASCII-clean; binary buffers fail this).
 *  3. DJB2 hash check when `gc.h` is non-zero (the high "computed
 *     marker" bit is masked off so PHP-version differences don't
 *     break the comparison). On match, promote MEDIUM → HIGH.
 *
 * For strings whose payload extends past the fingerprint horizon, the
 * strict checks degrade gracefully — the detector keeps the MEDIUM
 * verdict on header-only match.
 */
final class ZendStringDetector implements ShapeDetector
{
    private const MIN_BIN = 32;

    /** Refcount sanity ceiling (2^28); above this it's almost certainly garbage. */
    private const MAX_REFCOUNT = 0x10000000;

    /** Minimum ratio of printable bytes in a payload to accept as a real string. */
    private const PRINTABLE_RATIO_THRESHOLD = 0.7;

    /**
     * Lower bit mask for DJB2 hash comparison — strips the high
     * "computed marker" bit that PHP toggles on stored hashes.
     */
    private const HASH_LOW_MASK = 0x7FFFFFFFFFFFFFFF;

    #[\Override]
    public function name(): string
    {
        return 'ZendStringDetector';
    }

    #[\Override]
    public function detect(string $fingerprint, int $bin_size): ?ShapeDetection
    {
        $fp_len = strlen($fingerprint);
        if ($bin_size < self::MIN_BIN || $fp_len < 24) {
            return null;
        }

        // gc.refcount: u32 LE at offset 0
        /** @var array{1: int} $rc */
        $rc = unpack('V', substr($fingerprint, 0, 4));
        $refcount = $rc[1];
        if ($refcount === 0 || $refcount > self::MAX_REFCOUNT) {
            return null;
        }

        // type_info at offset 4 — reject only obvious garbage (all 1s).
        /** @var array{1: int} $ti */
        $ti = unpack('V', substr($fingerprint, 4, 4));
        if ($ti[1] === 0xFFFFFFFF) {
            return null;
        }

        // h at offset 8 (size_t LE), len at offset 16.
        /** @var array{1: int} $hv */
        $hv = unpack('P', substr($fingerprint, 8, 8));
        $hash = $hv[1];
        /** @var array{1: int} $ln */
        $ln = unpack('P', substr($fingerprint, 16, 8));
        $len = $ln[1];

        $payload_capacity = $bin_size - 24 - 1; // header + NUL
        if ($len < 0 || $len > $payload_capacity) {
            return null;
        }

        // Header passes; promote confidence based on payload-side
        // validation when the bytes are reachable from the fingerprint.
        $confidence = ShapeDetection::CONFIDENCE_MEDIUM;

        $nul_offset = 24 + $len;
        $needed = $nul_offset + 1;

        if ($needed <= $fp_len) {
            // Strict NUL terminator check — single-byte test that
            // alone eliminates almost all false positives.
            if ($fingerprint[$nul_offset] !== "\x00") {
                return null;
            }

            $payload = $len > 0 ? substr($fingerprint, 24, $len) : '';
            if ($payload !== '' && !$this->isPrintableEnough($payload)) {
                return null;
            }

            // Hash promotion to HIGH — only when gc.h is set.
            if ($hash !== 0 && $len > 0 && $this->djb2Matches($payload, $hash)) {
                $confidence = ShapeDetection::CONFIDENCE_HIGH;
            }
        }

        return new ShapeDetection(
            label: sprintf('zend_string(len=%d)', $len),
            confidence: $confidence,
        );
    }

    private function isPrintableEnough(string $payload): bool
    {
        $printable = 0;
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($payload[$i]);
            // Standard ASCII printable + a couple of common harmless
            // controls used in PHP-internal strings (NUL inside name
            // mangling for protected/private props, plus tab/newline).
            if (
                ($byte >= 0x20 && $byte < 0x7f)
                || $byte === 0x00
                || $byte === 0x09
                || $byte === 0x0a
            ) {
                $printable++;
            }
        }
        return ($printable / $len) >= self::PRINTABLE_RATIO_THRESHOLD;
    }

    /**
     * PHP's zend_inline_hash_func / DJB2 variant.
     *
     * The stored hash on a zend_string may carry a high "computed
     * marker" bit (Z_HASH_VAL); compare via the low mask so PHP-version
     * differences don't break the comparison.
     */
    private function djb2Matches(string $payload, int $stored_hash): bool
    {
        $hash = 5381;
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            // ((hash << 5) + hash) + byte, modulo 2^64. PHP's int is
            // 64-bit signed; `&` with the low mask keeps the result
            // positive and matching zend_ulong semantics.
            $hash = (($hash << 5) + $hash + ord($payload[$i])) & self::HASH_LOW_MASK;
        }
        return ($stored_hash & self::HASH_LOW_MASK) === ($hash & self::HASH_LOW_MASK);
    }
}
