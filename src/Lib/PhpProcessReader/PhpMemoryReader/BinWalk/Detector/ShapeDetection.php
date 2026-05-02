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
 * A detector's verdict on a slot's byte shape.
 *
 * Confidence levels track the design doc: HIGH means the byte pattern is
 * specific enough that a false positive is unlikely (e.g. zend_string
 * header with valid gc + plausible len + NUL terminator at the right
 * offset); MED means the pattern is suggestive but not unique; LOW means
 * "could be" — only useful as supporting evidence.
 *
 * A detector that needs more bytes than the 24 B fingerprint to commit
 * to HIGH should return MED here; the design's full pipeline (Phase 2.5
 * and beyond) is expected to read the rest of the slot before promoting.
 */
final class ShapeDetection
{
    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';

    public function __construct(
        public readonly string $label,
        public readonly string $confidence,
    ) {
    }
}
