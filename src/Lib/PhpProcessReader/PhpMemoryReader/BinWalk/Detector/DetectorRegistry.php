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
 * Default detector lineup + the confidence-rank / best-pick helpers that
 * both the bin walker (per-slot scan) and the periodicity detector
 * (per-group label) consume.
 *
 * Lives here rather than inside PeriodicityDetector because the per-slot
 * shape scan that surfaces "76% of bin[3] is Bucket(IS_STRING)" — added to
 * cover content-varying shapes that don't form periodic groups — needs the
 * same pipeline without dragging in the stride-detection plumbing.
 */
final class DetectorRegistry
{
    /** @return list<ShapeDetector> */
    public static function defaults(): array
    {
        return [
            // FunctionPointerDetector first so module-resolved labels
            // ("func_ptr (libuv.so.1)") win tie-breaks over the
            // pattern-only OpArrayDetector ("func_ptr_array(N × 32 B)")
            // when both would match.
            new FunctionPointerDetector(),
            new BucketDetector(),
            new ZendStringDetector(),
            new StatDetector(),
            new OpArrayDetector(),
        ];
    }

    /**
     * Pick the highest-confidence detection from the registered detectors.
     * Confidence-first per the design's selection rule
     * (HIGH > MEDIUM > LOW), then first-registered wins on tie. Detectors
     * that return null are skipped.
     *
     * `$context` is forwarded to every detector — those that don't need
     * it ignore the parameter via their default-null arg.
     *
     * @param list<ShapeDetector> $detectors
     */
    public static function pickBest(
        array $detectors,
        string $bytes,
        int $bin_size,
        ?DetectorContext $context = null,
    ): ?ShapeDetection {
        $best = null;
        foreach ($detectors as $d) {
            $hit = $d->detect($bytes, $bin_size, $context);
            if ($hit === null) {
                continue;
            }
            if (
                $best === null
                || self::confidenceRank($hit->confidence) > self::confidenceRank($best->confidence)
            ) {
                $best = $hit;
            }
        }
        return $best;
    }

    public static function confidenceRank(string $c): int
    {
        return match ($c) {
            ShapeDetection::CONFIDENCE_HIGH => 3,
            ShapeDetection::CONFIDENCE_MEDIUM => 2,
            ShapeDetection::CONFIDENCE_LOW => 1,
            default => 0,
        };
    }
}
