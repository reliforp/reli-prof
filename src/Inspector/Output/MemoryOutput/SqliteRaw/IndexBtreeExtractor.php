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

namespace Reli\Inspector\Output\MemoryOutput\SqliteRaw;

/**
 * Extract an index b-tree from a SQLite source file with all
 * internal pgno references rewritten so the caller can drop the
 * resulting pages anywhere in a destination file.
 *
 * Used by the parallel-CREATE-INDEX path: each worker creates the
 * index natively in its own shard file, then the main process
 * extracts the index pages here and copies them into the main DB
 * via shared mmap. Native SQLite handles the sort + leaf assembly
 * (where PHP-side approaches lose to the C implementation), and the
 * extractor only has to walk the resulting b-tree and relocate.
 *
 * Index page formats (only these have pgno refs to rewrite):
 *   - Interior (type 0x02): header has `right_most_pgno` at offset 8
 *     (4 bytes BE). Each cell starts with `left_child_pgno` (4 BE)
 *     followed by the divider key payload.
 *   - Leaf     (type 0x0a): cells are just `varint payload_size +
 *     payload`. No pgno refs anywhere.
 *
 * Reference: https://www.sqlite.org/fileformat.html#b_tree_pages
 *
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedAssignment
 */
final class IndexBtreeExtractor
{
    public const int PAGE_SIZE = 4096;
    private const int INDEX_INTERIOR_TYPE = 0x02;
    private const int INDEX_LEAF_TYPE = 0x0a;
    private const int INTERIOR_HEADER_SIZE = 12;

    /**
     * Walk the index b-tree rooted at `$src_root_pgno` in
     * `$source_fp`, collect every reachable page, and emit them with
     * pgnos relocated into a contiguous range starting at
     * `$first_dest_pgno`. Internal `right_most_pgno` and
     * `left_child_pgno` references are rewritten in place to follow
     * the relocation map.
     *
     * Returns the relocated page list in the order pages were
     * discovered (BFS), the relocated root pgno, and the page count
     * — so the caller can `ftruncate`/allocate the right amount of
     * room in the destination file before writing.
     *
     * @param resource $source_fp opened binary-mode file handle to a
     *     SQLite database that has the index b-tree available at
     *     `$src_root_pgno`.
     * @return array{list<array{int, string}>, int, int}
     *     [(dest_pgno, page_bytes), ...], dest_root_pgno, page_count
     */
    /**
     * Count the pages in the index b-tree rooted at `$src_root_pgno`
     * without materialising any of them. Useful when the caller
     * needs to claim a destination pgno range before doing the
     * actual extract — e.g. via a shared-counter atomic claim so
     * the destination layout has no gaps.
     *
     * @param resource $source_fp
     */
    public static function countPages($source_fp, int $src_root_pgno): int
    {
        $visited = [$src_root_pgno => true];
        $queue = [$src_root_pgno];
        while ($queue !== []) {
            $src_pgno = array_shift($queue);
            $page = self::readPage($source_fp, $src_pgno);
            $type = ord($page[0]);
            if ($type !== self::INDEX_INTERIOR_TYPE) {
                continue;
            }
            /** @var array{1: int} $cc */
            $cc = unpack('n', substr($page, 3, 2));
            $cell_count = $cc[1];
            /** @var array{1: int} $rm */
            $rm = unpack('N', substr($page, 8, 4));
            $rightmost = $rm[1];
            if (!isset($visited[$rightmost])) {
                $visited[$rightmost] = true;
                $queue[] = $rightmost;
            }
            for ($i = 0; $i < $cell_count; $i++) {
                /** @var array{1: int} $co */
                $co = unpack('n', substr($page, self::INTERIOR_HEADER_SIZE + $i * 2, 2));
                $cell_offset = $co[1];
                /** @var array{1: int} $child */
                $child = unpack('N', substr($page, $cell_offset, 4));
                if (!isset($visited[$child[1]])) {
                    $visited[$child[1]] = true;
                    $queue[] = $child[1];
                }
            }
        }
        return count($visited);
    }

    /** @param resource $source_fp */
    public static function extractAndRelocate(
        $source_fp,
        int $src_root_pgno,
        int $first_dest_pgno,
    ): array {
        $reloc = [$src_root_pgno => $first_dest_pgno];
        $next_dest = $first_dest_pgno + 1;
        $queue = [$src_root_pgno];
        $cached_pages = [];

        while ($queue !== []) {
            $src_pgno = array_shift($queue);
            $page = self::readPage($source_fp, $src_pgno);
            $cached_pages[$src_pgno] = $page;

            $type = ord($page[0]);
            if ($type !== self::INDEX_INTERIOR_TYPE) {
                continue;
            }
            /** @var array{1: int} $cc */
            $cc = unpack('n', substr($page, 3, 2));
            $cell_count = $cc[1];
            /** @var array{1: int} $rm */
            $rm = unpack('N', substr($page, 8, 4));
            $rightmost = $rm[1];
            self::ensureMapped($reloc, $rightmost, $next_dest, $queue);

            for ($i = 0; $i < $cell_count; $i++) {
                /** @var array{1: int} $co */
                $co = unpack('n', substr($page, self::INTERIOR_HEADER_SIZE + $i * 2, 2));
                $cell_offset = $co[1];
                /** @var array{1: int} $child */
                $child = unpack('N', substr($page, $cell_offset, 4));
                self::ensureMapped($reloc, $child[1], $next_dest, $queue);
            }
        }

        $output = [];
        foreach ($reloc as $src_pgno => $dest_pgno) {
            $page = $cached_pages[$src_pgno] ?? self::readPage($source_fp, $src_pgno);
            $output[] = [$dest_pgno, self::rewritePagePgnos($page, $reloc)];
        }
        return [$output, $first_dest_pgno, count($reloc)];
    }

    /**
     * @param array<int, int> $reloc
     * @param-out array<int, int> $reloc
     * @param list<int> $queue
     * @param-out list<int> $queue
     */
    private static function ensureMapped(
        array &$reloc,
        int $src_pgno,
        int &$next_dest,
        array &$queue,
    ): void {
        if (isset($reloc[$src_pgno])) {
            return;
        }
        $reloc[$src_pgno] = $next_dest;
        $next_dest++;
        $queue[] = $src_pgno;
    }

    /** @param resource $fp */
    private static function readPage($fp, int $pgno): string
    {
        fseek($fp, ($pgno - 1) * self::PAGE_SIZE);
        $bytes = fread($fp, self::PAGE_SIZE);
        if ($bytes === false || strlen($bytes) !== self::PAGE_SIZE) {
            throw new \RuntimeException("failed to read page {$pgno}");
        }
        return $bytes;
    }

    /**
     * Rewrite every pgno reference inside an index page using the
     * supplied map. For interior pages that means the `right_most_pgno`
     * header field plus each cell's `left_child_pgno`; for leaf pages
     * it's a no-op.
     *
     * @param array<int, int> $reloc
     */
    private static function rewritePagePgnos(string $page, array $reloc): string
    {
        $type = ord($page[0]);
        if ($type !== self::INDEX_INTERIOR_TYPE) {
            return $page;
        }
        /** @var array{1: int} $cc */
        $cc = unpack('n', substr($page, 3, 2));
        $cell_count = $cc[1];
        /** @var array{1: int} $rm */
        $rm = unpack('N', substr($page, 8, 4));
        $rightmost = $rm[1];
        $page = substr_replace($page, pack('N', $reloc[$rightmost] ?? $rightmost), 8, 4);

        for ($i = 0; $i < $cell_count; $i++) {
            /** @var array{1: int} $co */
            $co = unpack('n', substr($page, self::INTERIOR_HEADER_SIZE + $i * 2, 2));
            $cell_offset = $co[1];
            /** @var array{1: int} $child */
            $child = unpack('N', substr($page, $cell_offset, 4));
            $page = substr_replace(
                $page,
                pack('N', $reloc[$child[1]] ?? $child[1]),
                $cell_offset,
                4,
            );
        }
        return $page;
    }
}
