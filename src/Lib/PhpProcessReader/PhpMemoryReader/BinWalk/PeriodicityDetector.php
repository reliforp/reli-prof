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

use Reli\Lib\PhpInternals\Types\Zend\ZendMmBinsInfo;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\BucketDetector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\ShapeDetection;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\ShapeDetector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\ZendStringDetector;

/**
 * Periodicity detection over bin-walker output.
 *
 * Pure function over `[bin_num][chunk_addr] => list<{addr, fingerprint}>`
 * — kept separate from the walker so the algorithm can be tested without
 * having to spin up a PHP target / FFI memory reader.
 *
 * Picked up by {@see ZendMmBinWalker::detectPeriodicGroups} after it
 * collects per-bin live-slot fingerprints from the chunk page maps.
 */
final class PeriodicityDetector
{
    /**
     * Minimum same-shape repeat count before a periodic group is reported.
     *
     * Below this, the run is statistical noise — keep findings actionable.
     */
    public const PERIODIC_THRESHOLD = 32;

    /** Cap on per-group address samples kept for later reachability probes. */
    public const MAX_SAMPLES = 16;

    /**
     * @param array<int, array<int, list<array{addr: int, fp: string}>>> $live_by_bin_by_chunk
     * @param list<ShapeDetector>|null $detectors When null, the built-in
     *     PHP-shape detectors are used. Pass an empty list to disable
     *     detection entirely (tests / future opt-out).
     * @param array<int, mixed>|null $reachable_set When set, the keys of
     *     this map are addresses claimed by the root walker; each group's
     *     samples are probed and the result attached as
     *     {@see PeriodicGroup::$reachability}. The design's negative-
     *     evidence rule (C, "ReachabilityFilter"): groups whose samples
     *     are all reachable were matched by accident and downstream
     *     callers should suppress them as leak signals.
     * @return list<PeriodicGroup>
     */
    public static function detect(
        array $live_by_bin_by_chunk,
        int $threshold = self::PERIODIC_THRESHOLD,
        ?array $detectors = null,
        ?array $reachable_set = null,
    ): array {
        if ($detectors === null) {
            $detectors = self::defaultDetectors();
        }
        $out = [];
        foreach ($live_by_bin_by_chunk as $bin_num => $by_chunk) {
            $bin_size = ZendMmBinsInfo::getSize($bin_num);
            foreach ($by_chunk as $slots) {
                /** @var array<string, list<int>> $by_fp */
                $by_fp = [];
                foreach ($slots as $s) {
                    $by_fp[$s['fp']][] = $s['addr'];
                }
                foreach ($by_fp as $fp => $addrs) {
                    if (count($addrs) < $threshold) {
                        continue;
                    }
                    sort($addrs);
                    $stride = self::modeStride($addrs);
                    if ($stride === 0) {
                        continue;
                    }
                    $detection = self::pickBestDetection($detectors, $fp, $bin_size);
                    $samples = self::sampleAddresses($addrs);
                    $verdict = self::classifyReachability($samples, $reachable_set);
                    $reachable_count = $verdict[0];
                    $reachability = $verdict[1];
                    $out[] = new PeriodicGroup(
                        bin_num: $bin_num,
                        bin_size: $bin_size,
                        count: count($addrs),
                        stride: $stride,
                        fingerprint_hex: bin2hex($fp),
                        sample_addr: $addrs[0],
                        detection: $detection,
                        sample_addrs: $samples,
                        reachability: $reachability,
                        reachable_samples: $reachable_count,
                        sampled_count: count($samples),
                    );
                }
            }
        }
        usort(
            $out,
            static fn(PeriodicGroup $a, PeriodicGroup $b) => $b->count <=> $a->count,
        );
        return $out;
    }

    /**
     * Take up to MAX_SAMPLES addresses from the head and tail of the
     * sorted list. Cheap, deterministic, and gives the reachability
     * filter both ends of the run — extension leaks tend to be
     * uniformly unreachable, so even a head-only sample would catch
     * most cases, but tail coverage saves us from misclassifying mixed
     * regions where only the start of the page is claimed.
     *
     * @param list<int> $sorted_addrs
     * @return list<int>
     */
    private static function sampleAddresses(array $sorted_addrs): array
    {
        $n = count($sorted_addrs);
        if ($n <= self::MAX_SAMPLES) {
            return $sorted_addrs;
        }
        $half = (int)floor(self::MAX_SAMPLES / 2);
        $head = array_slice($sorted_addrs, 0, $half);
        $tail = array_slice($sorted_addrs, -$half);
        $merged = array_values(array_unique(array_merge($head, $tail)));
        return $merged;
    }

    /**
     * @param list<int> $samples
     * @param array<int, mixed>|null $reachable_set
     * @return array{0: int, 1: ?string}
     */
    private static function classifyReachability(array $samples, ?array $reachable_set): array
    {
        if ($reachable_set === null || $samples === []) {
            return [0, null];
        }
        $reachable_count = 0;
        foreach ($samples as $addr) {
            if (isset($reachable_set[$addr])) {
                $reachable_count++;
            }
        }
        $sample_count = count($samples);
        if ($reachable_count === 0) {
            return [0, PeriodicGroup::REACHABILITY_ORPHAN];
        }
        if ($reachable_count === $sample_count) {
            return [$reachable_count, PeriodicGroup::REACHABILITY_REACHABLE];
        }
        return [$reachable_count, PeriodicGroup::REACHABILITY_MIXED];
    }

    /** @return list<ShapeDetector> */
    private static function defaultDetectors(): array
    {
        return [
            new BucketDetector(),
            new ZendStringDetector(),
        ];
    }

    /**
     * Pick the highest-confidence detection from the registered detectors.
     * Confidence-first per the design's selection rule
     * (HIGH > MEDIUM > LOW), then first-registered wins on tie. Detectors
     * that return null are skipped.
     *
     * @param list<ShapeDetector> $detectors
     */
    private static function pickBestDetection(
        array $detectors,
        string $fp,
        int $bin_size,
    ): ?ShapeDetection {
        $best = null;
        foreach ($detectors as $d) {
            $hit = $d->detect($fp, $bin_size);
            if ($hit === null) {
                continue;
            }
            if ($best === null) {
                $best = $hit;
                continue;
            }
            if (self::confidenceRank($hit->confidence) > self::confidenceRank($best->confidence)) {
                $best = $hit;
            }
        }
        return $best;
    }

    private static function confidenceRank(string $c): int
    {
        return match ($c) {
            ShapeDetection::CONFIDENCE_HIGH => 3,
            ShapeDetection::CONFIDENCE_MEDIUM => 2,
            ShapeDetection::CONFIDENCE_LOW => 1,
            default => 0,
        };
    }

    /** @param list<int> $sorted_addrs */
    public static function modeStride(array $sorted_addrs): int
    {
        $n = count($sorted_addrs);
        if ($n < 2) {
            return 0;
        }
        /** @var array<int, int> $counts */
        $counts = [];
        for ($i = 1; $i < $n; $i++) {
            $delta = $sorted_addrs[$i] - $sorted_addrs[$i - 1];
            if ($delta <= 0) {
                continue;
            }
            $counts[$delta] = ($counts[$delta] ?? 0) + 1;
        }
        if ($counts === []) {
            return 0;
        }
        arsort($counts);
        /** @var int $mode */
        $mode = array_key_first($counts);
        return $mode;
    }
}
