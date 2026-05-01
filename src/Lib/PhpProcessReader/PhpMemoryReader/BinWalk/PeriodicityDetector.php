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

    /**
     * @param array<int, array<int, list<array{addr: int, fp: string}>>> $live_by_bin_by_chunk
     * @return list<PeriodicGroup>
     */
    public static function detect(
        array $live_by_bin_by_chunk,
        int $threshold = self::PERIODIC_THRESHOLD,
    ): array {
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
                    $out[] = new PeriodicGroup(
                        bin_num: $bin_num,
                        bin_size: $bin_size,
                        count: count($addrs),
                        stride: $stride,
                        fingerprint_hex: bin2hex($fp),
                        sample_addr: $addrs[0],
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
