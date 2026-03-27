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

namespace Reli\Lib\Process\MemoryReader;

use FFI;
use FFI\CData;

final class RecordingMemoryReader implements MemoryReaderInterface
{
    /** @var array<array{address: int, size: int, data: string}> */
    private array $recorded_regions = [];

    public function __construct(
        private MemoryReaderInterface $inner,
    ) {
    }

    #[\Override]
    public function read(int $pid, int $remote_address, int $size): CData
    {
        $cdata = $this->inner->read($pid, $remote_address, $size);

        // Record the raw bytes
        $this->recorded_regions[] = [
            'address' => $remote_address,
            'size' => $size,
            'data' => FFI::string($cdata, $size),
        ];

        return $cdata;
    }

    /**
     * Return all recorded regions, merged where they overlap or are near each other.
     * Regions within $merge_gap bytes of each other are merged to reduce region count.
     * @return array<array{address: int, size: int, data: string}>
     */
    public function getRecordedRegions(int $merge_gap = 128): array
    {
        if ($this->recorded_regions === []) {
            return [];
        }

        // Sort by address
        $regions = $this->recorded_regions;
        usort(
            $regions,
            /** @param array{address: int, size: int, data: string} $a */
            /** @param array{address: int, size: int, data: string} $b */
            fn(array $a, array $b) => $a['address'] <=> $b['address'],
        );

        // Merge overlapping/adjacent/nearby regions
        $merged = [$regions[0]];
        for ($i = 1; $i < count($regions); $i++) {
            $last = &$merged[count($merged) - 1];
            $cur = $regions[$i];
            $last_end = $last['address'] + $last['size'];

            if ($cur['address'] <= $last_end + $merge_gap) {
                // Overlapping, adjacent, or within gap: extend
                $cur_end = $cur['address'] + $cur['size'];
                if ($cur_end > $last_end) {
                    // Fill any gap with zeros, then append the new data
                    $gap = $cur['address'] - $last_end;
                    if ($gap > 0) {
                        $last['data'] .= str_repeat("\0", $gap);
                    }
                    $overlap = max(0, $last_end - $cur['address']);
                    $last['data'] .= substr($cur['data'], $overlap);
                    $last['size'] = $cur_end - $last['address'];
                }
            } else {
                $merged[] = $cur;
            }
            unset($last);
        }

        return $merged;
    }
}
