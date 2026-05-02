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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\ShapeDetection;

/**
 * A run of like-shaped live small-bin slots observed at constant stride.
 *
 * "Like-shaped" means the slots share the same first-24-byte fingerprint —
 * cheap structural similarity that, combined with the stride / count, is
 * enough to surface "16,000 copies of one shape in bin[32 B]" without any
 * detector knowing what the shape is.
 *
 * When the detector pipeline (Detector\\*) recognises the bytes as a
 * known structure it attaches a {@see ShapeDetection} to label the
 * group; downstream renderers can show "Bucket(zval IS_STRING)" or
 * "zend_string(len=11)" instead of bare hex.
 *
 * Reachability is the design's negative-evidence rule (orphan-allocation
 * doc, section C). A group with `reachability = REACHABLE` is normal
 * PHP-claimed data that the detectors matched by accident; a group with
 * `reachability = ORPHAN` is the leak signal and gets promoted to HIGH
 * confidence in the report.
 */
final class PeriodicGroup
{
    public const REACHABILITY_ORPHAN = 'orphan';
    public const REACHABILITY_REACHABLE = 'reachable';
    public const REACHABILITY_MIXED = 'mixed';

    /**
     * @param list<int> $sample_addrs Up to 16 addresses sampled from the
     *     group (head + tail of the sorted address list). Lets a later
     *     reachability annotator probe membership without having kept the
     *     full address list around. Always includes {@see $sample_addr}
     *     as the first entry.
     */
    public function __construct(
        public readonly int $bin_num,
        public readonly int $bin_size,
        public readonly int $count,
        public readonly int $stride,
        public readonly string $fingerprint_hex,
        public readonly int $sample_addr,
        public readonly ?ShapeDetection $detection = null,
        public readonly array $sample_addrs = [],
        public readonly ?string $reachability = null,
        public readonly int $reachable_samples = 0,
        public readonly int $sampled_count = 0,
    ) {
    }

    /**
     * Promote/demote shape detection confidence using the reachability
     * verdict. An ORPHAN group with a MED detection earns HIGH; a
     * REACHABLE group earns nothing extra (the design's negative-evidence
     * rule says these are coincidental matches).
     */
    public function effectiveConfidence(): ?string
    {
        if ($this->detection === null) {
            return null;
        }
        if ($this->reachability === self::REACHABILITY_ORPHAN) {
            return ShapeDetection::CONFIDENCE_HIGH;
        }
        return $this->detection->confidence;
    }
}
