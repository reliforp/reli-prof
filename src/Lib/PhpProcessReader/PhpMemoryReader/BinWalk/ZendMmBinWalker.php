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

use FFI;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmChunk;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmPageInfoFree;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmPageInfoLarge;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmPageInfoSmall;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Dereferencer;

/**
 * Enumerate every live ZendMM small-bin / large-run allocation.
 *
 * Walks `chunk->map` for every chunk reachable from the heap's main chunk,
 * subtracts the per-bin freelists (`heap->free_slot[bin]`) and emits a
 * histogram + a list of periodic groups.
 *
 * See docs/internals/design-orphan-allocation-analysis.md (sections A and D).
 */
final class ZendMmBinWalker
{
    /** Fingerprint length (first N bytes of the slot used as a shape key). */
    private const FINGERPRINT_BYTES = 24;

    /** Per-page chunk page count (2 MiB / 4 KiB). */
    private const PAGES_PER_CHUNK = ZendMmChunk::SIZE / ZendMmChunk::PAGE_SIZE;

    /** Defensive cap on freelist length per bin (a chunk holds at most 512 8 B slots). */
    private const FREELIST_WALK_LIMIT = 200_000;

    public function __construct(
        private MemoryReaderInterface $memory_reader,
    ) {
    }

    public function walk(
        int $pid,
        ZendMmChunk $main_chunk,
        Dereferencer $dereferencer,
    ): BinWalkResult {
        $heap = $main_chunk->heap_slot;
        $free_heads = $heap->getFreeSlotHeads();

        // free_set[bin_num] => map<int $address, true>
        /** @var array<int, array<int, true>> $free_set */
        $free_set = [];
        $partial = false;
        foreach ($free_heads as $bin_num => $head) {
            $set = [];
            $cursor = $head;
            $walked = 0;
            while ($cursor !== 0) {
                if (isset($set[$cursor])) {
                    // Cycle in freelist — corrupt or already-visited node.
                    $partial = true;
                    break;
                }
                $set[$cursor] = true;
                $walked++;
                if ($walked > self::FREELIST_WALK_LIMIT) {
                    $partial = true;
                    break;
                }
                $next = $this->readPointerAt($pid, $cursor);
                if ($next === null) {
                    $partial = true;
                    break;
                }
                $cursor = $next;
            }
            $free_set[$bin_num] = $set;
        }

        /** @var array<int, array{count: int, total_bytes: int}> $histogram */
        $histogram = [];
        $large_run_count = 0;
        $large_run_bytes = 0;
        $live_small_slot_count = 0;
        $live_small_slot_bytes = 0;
        $walked_chunk_count = 0;

        /**
         * Per-bin live slot capture used for periodicity detection.
         *
         * Keyed by `[bin_num][chunk_addr] => list<{addr,fingerprint}>`. Slots
         * are appended in address order, so the consumer can scan for runs of
         * constant stride directly.
         *
         * @var array<int, array<int, list<array{addr: int, fp: string}>>>
         */
        $live_by_bin_by_chunk = [];

        foreach ($main_chunk->iterateChunks($dereferencer) as $chunk) {
            $walked_chunk_count++;
            $chunk_addr = $chunk->getPointer()->address;
            $page = 0;
            while ($page < self::PAGES_PER_CHUNK) {
                try {
                    $info = $chunk->map->getPageInfo($page);
                } catch (\Throwable) {
                    // Bogus map entry — stop walking this chunk.
                    $partial = true;
                    break;
                }
                if ($info instanceof ZendMmPageInfoFree) {
                    $page++;
                    continue;
                }
                if ($info instanceof ZendMmPageInfoLarge) {
                    $n = $info->getLargePagesCount();
                    if ($n <= 0 || $page + $n > self::PAGES_PER_CHUNK) {
                        $partial = true;
                        break;
                    }
                    $large_run_count++;
                    $large_run_bytes += $n * ZendMmChunk::PAGE_SIZE;
                    $page += $n;
                    continue;
                }
                // Remaining branch: small-bin run.

                $bin_num = $info->getBinNum();
                $bin_size = $info->getBinSize();
                $bin_pages = $info->getBinPages();
                $elements = $info->getBinElements();
                if ($bin_size <= 0 || $bin_pages <= 0 || $elements <= 0) {
                    $partial = true;
                    break;
                }
                if ($page + $bin_pages > self::PAGES_PER_CHUNK) {
                    $partial = true;
                    break;
                }

                $run_addr = $chunk_addr + $page * ZendMmChunk::PAGE_SIZE;
                $run_bytes = $bin_pages * ZendMmChunk::PAGE_SIZE;

                $run_blob = $this->readBytes($pid, $run_addr, $run_bytes);

                $bin_free_set = $free_set[$bin_num] ?? [];

                for ($i = 0; $i < $elements; $i++) {
                    $slot_addr = $run_addr + $i * $bin_size;
                    if (isset($bin_free_set[$slot_addr])) {
                        continue;
                    }
                    $live_small_slot_count++;
                    $live_small_slot_bytes += $bin_size;
                    if (!isset($histogram[$bin_num])) {
                        $histogram[$bin_num] = ['count' => 0, 'total_bytes' => 0];
                    }
                    $histogram[$bin_num]['count']++;
                    $histogram[$bin_num]['total_bytes'] += $bin_size;

                    if ($run_blob !== null) {
                        $offset = $i * $bin_size;
                        $fp_len = $bin_size < self::FINGERPRINT_BYTES
                            ? $bin_size
                            : self::FINGERPRINT_BYTES;
                        $fp = substr($run_blob, $offset, $fp_len);
                        $live_by_bin_by_chunk[$bin_num][$chunk_addr][] = [
                            'addr' => $slot_addr,
                            'fp' => $fp,
                        ];
                    }
                }

                $page += $bin_pages;
            }
        }

        ksort($histogram);
        $periodic_groups = PeriodicityDetector::detect($live_by_bin_by_chunk);

        return new BinWalkResult(
            small_bin_histogram: $histogram,
            large_run_count: $large_run_count,
            large_run_bytes: $large_run_bytes,
            live_small_slot_count: $live_small_slot_count,
            live_small_slot_bytes: $live_small_slot_bytes,
            periodic_groups: $periodic_groups,
            walked_chunk_count: $walked_chunk_count,
            partial: $partial,
        );
    }

    private function readPointerAt(int $pid, int $address): ?int
    {
        $bytes = $this->readBytes($pid, $address, 8);
        if ($bytes === null) {
            return null;
        }
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', $bytes);
        return $unpacked[1];
    }

    private function readBytes(int $pid, int $address, int $size): ?string
    {
        if ($size <= 0) {
            return '';
        }
        try {
            $cdata = $this->memory_reader->read($pid, $address, $size);
        } catch (MemoryReaderException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
        return FFI::string($cdata, $size);
    }
}
