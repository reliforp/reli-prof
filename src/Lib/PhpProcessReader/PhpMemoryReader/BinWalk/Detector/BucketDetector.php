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
 * Detect HashTable Bucket headers (32 B slots).
 *
 * Layout:
 *
 *   +0..+7   zval value          (u8)
 *   +8       zval u1.v.type      (zend_uchar)
 *   +9..+15  zval u1.v.{flags,u} + u2
 *   +16..+23 zend_ulong h        (hash)
 *   +24..+31 zend_string *key    (NULL for indexed entries)
 *
 * Tightening over the original header-only check, in response to the
 * issue #88 validator data ("13 zval_type variants × 15 hits each from
 * uninit'd HashTable expansion slots, all with all-zero value/h/key"):
 *
 *  - Pointer-bearing zvals (IS_STRING, IS_ARRAY, IS_OBJECT, IS_RESOURCE,
 *    IS_REFERENCE, IS_CONSTANT_AST, IS_INDIRECT, IS_PTR, IS_ALIAS_PTR)
 *    require a plausible non-zero pointer at +0..+7. Bytes near zero or
 *    obvious garbage get rejected.
 *  - All-zero (value=0, h=0, key=0) buckets — regardless of zval type
 *    byte — collapse to a single `Bucket(uninit?)` label so the report
 *    stops listing "+15 each" garbage variants.
 *  - Symbol map covers IS_CONSTANT_AST (11), IS_INDIRECT (13), IS_PTR
 *    (14), IS_ALIAS_PTR (15); deeper-internal types ≥ 16 are rejected.
 *
 * Confidence stays MEDIUM on a 24 B fingerprint because the trailing
 * key-zend_string isn't followed; the design's HIGH-confidence Bucket
 * detector that walks into the key remains future work.
 */
final class BucketDetector implements ShapeDetector
{
    /** Pointer sanity ceiling — anything beyond this is not a userspace address. */
    private const POINTER_MAX = 0x800000000000;

    /**
     * Pointer floor — addresses below 0x1000 are either NULL or kernel-
     * /reserved-page territory; never a real PHP allocation.
     */
    private const POINTER_MIN = 0x1000;

    /**
     * zval type bytes that store a pointer at +0..+7. For these, an
     * all-zero or otherwise-bogus value is a strong rejection signal.
     */
    private const POINTER_ZVAL_TYPES = [6, 7, 8, 9, 10, 11, 13, 14, 15];

    #[\Override]
    public function name(): string
    {
        return 'BucketDetector';
    }

    #[\Override]
    public function detect(
        string $fingerprint,
        int $bin_size,
        ?DetectorContext $context = null,
    ): ?ShapeDetection {
        if ($bin_size !== 32 || strlen($fingerprint) < 24) {
            return null;
        }

        $type_byte = ord($fingerprint[8]);
        if ($type_byte > 15) {
            // Deep-internal types only show up in unsafe contexts; in
            // a HashTable bucket they're almost always garbage from an
            // uninit'd slot or a non-bucket allocation.
            return null;
        }

        /** @var array{1: int} $val */
        $val = unpack('P', substr($fingerprint, 0, 8));
        $value = $val[1];

        /** @var array{1: int} $hu */
        $hu = unpack('P', substr($fingerprint, 16, 8));
        $hash = $hu[1];

        // Key is at +24..+31; only available on full-slot fingerprints
        // (32 B bin, fingerprint ≥ 32 B which is guaranteed for bin
        // size 32). Non-NULL keys point at zend_string.
        $key = 0;
        if (strlen($fingerprint) >= 32) {
            /** @var array{1: int} $kv */
            $kv = unpack('P', substr($fingerprint, 24, 8));
            $key = $kv[1];
        }

        // Collapse "all-zero" / freshly-allocated bucket slots to a
        // single label so the per-bin shape table doesn't fan out
        // into 13 × `+15` zval-type variants.
        if ($value === 0 && $hash === 0 && $key === 0) {
            return new ShapeDetection(
                label: 'Bucket(uninit?)',
                confidence: ShapeDetection::CONFIDENCE_LOW,
            );
        }

        // Pointer-bearing zvals: value must look like a userspace
        // pointer. Reject obviously garbage values.
        if (in_array($type_byte, self::POINTER_ZVAL_TYPES, true)) {
            if (!$this->looksLikePointer($value)) {
                return null;
            }
        }

        // Non-NULL key must look like a pointer too. NULL is fine
        // (indexed bucket entry).
        if ($key !== 0 && !$this->looksLikePointer($key)) {
            return null;
        }

        $type_label = match ($type_byte) {
            0 => 'IS_UNDEF',
            1 => 'IS_NULL',
            2 => 'IS_FALSE',
            3 => 'IS_TRUE',
            4 => 'IS_LONG',
            5 => 'IS_DOUBLE',
            6 => 'IS_STRING',
            7 => 'IS_ARRAY',
            8 => 'IS_OBJECT',
            9 => 'IS_RESOURCE',
            10 => 'IS_REFERENCE',
            11 => 'IS_CONSTANT_AST',
            13 => 'IS_INDIRECT',
            14 => 'IS_PTR',
            15 => 'IS_ALIAS_PTR',
            default => sprintf('zval_type=%d', $type_byte),
        };

        return new ShapeDetection(
            label: sprintf('Bucket(zval %s)', $type_label),
            confidence: ShapeDetection::CONFIDENCE_MEDIUM,
        );
    }

    private function looksLikePointer(int $value): bool
    {
        // Reject negative-looking (high bit set) and below the userspace
        // floor; require 8-byte alignment that PHP allocations honour.
        if ($value <= 0 || $value >= self::POINTER_MAX) {
            return false;
        }
        if ($value < self::POINTER_MIN) {
            return false;
        }
        return ($value & 0x7) === 0;
    }
}
