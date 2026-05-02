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
 * Detect zend_string headers.
 *
 * A `zend_string` is laid out as:
 *
 *   +0..+7   zend_refcounted_h gc  (refcount u32 + u32 type_info)
 *   +8..+15  zend_ulong h          (hash, may be 0 for never-hashed)
 *   +16..+23 size_t len            (string length, NUL excluded)
 *   +24..    char val[1]           (payload, NUL terminated)
 *
 * On the 24 B fingerprint we see the whole header but not the payload
 * NUL terminator, so this stays MED on a header-only match. Validation:
 * `len` must fit inside the slot (`len + 24 + 1 (NUL) <= bin_size`),
 * `gc.refcount` must be a small positive count (we accept ≤ 2^28 to
 * tolerate interned strings with synthetic refcounts), and the string
 * is rejected if it would obviously overflow the bin.
 *
 * For very small bins (8 B / 16 B) the header doesn't even fit, so the
 * detector skips them. Bins ≥ 32 B are the realistic targets — typical
 * PHP-internal short strings ("__construct", property names, …) live
 * there.
 */
final class ZendStringDetector implements ShapeDetector
{
    private const MIN_BIN = 32;

    /** Refcount sanity ceiling (2^28); above this it's almost certainly garbage. */
    private const MAX_REFCOUNT = 0x10000000;

    #[\Override]
    public function name(): string
    {
        return 'ZendStringDetector';
    }

    #[\Override]
    public function detect(string $fingerprint, int $bin_size): ?ShapeDetection
    {
        if ($bin_size < self::MIN_BIN || strlen($fingerprint) < 24) {
            return null;
        }

        // gc.refcount: u32 LE at offset 0
        /** @var array{1: int} $rc */
        $rc = unpack('V', substr($fingerprint, 0, 4));
        $refcount = $rc[1];
        if ($refcount === 0 || $refcount > self::MAX_REFCOUNT) {
            return null;
        }

        // type_info at offset 4 (u32). Top byte is the zval-type-tag
        // analogue; for zend_string it's IS_STRING (6) when the string
        // is reachable from a zval, but interned-string headers carry
        // GC_FLAGS in the high bits. The common case has the LOW byte
        // of type_info either 6 (IS_STRING) or 0 (when used as a raw
        // refcounted slot before promotion). We don't gate on this —
        // too many variants — but we do reject obvious garbage where
        // type_info is all 1s.
        /** @var array{1: int} $ti */
        $ti = unpack('V', substr($fingerprint, 4, 4));
        if ($ti[1] === 0xFFFFFFFF) {
            return null;
        }

        // len at offset 16 (size_t LE). Must fit in the slot with a
        // null terminator. Allow zero-length strings (rare but legal
        // for sentinel use).
        /** @var array{1: int} $ln */
        $ln = unpack('P', substr($fingerprint, 16, 8));
        $len = $ln[1];
        if ($len < 0) {
            return null;
        }
        $payload_capacity = $bin_size - 24 - 1; // header + NUL
        if ($len > $payload_capacity) {
            return null;
        }

        return new ShapeDetection(
            label: sprintf('zend_string(len=%d)', $len),
            confidence: ShapeDetection::CONFIDENCE_MEDIUM,
        );
    }
}
