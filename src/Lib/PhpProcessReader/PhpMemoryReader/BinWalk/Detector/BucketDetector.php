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
 * A `Bucket` is laid out as:
 *
 *   +0..+15  zval val            (zval = u64 value + u32 type/flags + u32 type_info)
 *   +16..+23 zend_ulong h        (hash)
 *   +24..+31 zend_string *key    (NULL for indexed entries)
 *
 * Within the zval, byte +8 holds the type tag (zend_uchar `u1.v.type`) —
 * IS_UNDEF (0) … IS_PTR (13) on PHP 7+ runs, with IS_INDIRECT etc. up to
 * 17. Anything outside [0, 21] is a strong signal that this is not a zval
 * at all, so the detector rejects.
 *
 * On the 24 B fingerprint we only see (val + h) and not the trailing
 * key pointer, so the strongest verdict header-only inspection can earn
 * is MED. The design's HIGH-confidence Bucket detector does follow-up
 * reads to walk into the key zend_string; that is intentionally deferred
 * to Phase 2.5.
 */
final class BucketDetector implements ShapeDetector
{
    /**
     * Whitelist of plausible zval u1.v.type values across PHP 7.x / 8.x.
     * IS_UNDEF=0 and IS_NULL=1 are common in newly-allocated buckets;
     * IS_FALSE/IS_TRUE/IS_LONG/IS_DOUBLE/IS_STRING/IS_ARRAY/IS_OBJECT
     * /IS_RESOURCE/IS_REFERENCE land in 2..10; the rest are internal
     * (IS_INDIRECT, IS_PTR, IS_ALIAS_PTR, etc.).
     */
    private const VALID_ZVAL_TYPES = [
        0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21,
    ];

    #[\Override]
    public function name(): string
    {
        return 'BucketDetector';
    }

    #[\Override]
    public function detect(string $fingerprint, int $bin_size): ?ShapeDetection
    {
        if ($bin_size !== 32 || strlen($fingerprint) < 24) {
            return null;
        }

        $type_byte = ord($fingerprint[8]);
        if (!in_array($type_byte, self::VALID_ZVAL_TYPES, true)) {
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
            13 => 'IS_INDIRECT',
            default => sprintf('zval_type=%d', $type_byte),
        };

        return new ShapeDetection(
            label: sprintf('Bucket(zval %s)', $type_label),
            confidence: ShapeDetection::CONFIDENCE_MEDIUM,
        );
    }
}
