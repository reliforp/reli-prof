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
 * Streaming assembler for SQLite table-leaf b-tree pages.
 *
 * Workers call {@see append} once per row in ascending rowid order;
 * when adding a cell would overflow the current 4 KiB page the
 * builder finalises it (header + cell pointer array + cells laid out
 * in the cell content area) and hands the page to the supplied
 * `$emit` callback. The callback is responsible for memcpy'ing those
 * bytes into the shared mmap region — the builder itself keeps no
 * per-page string buffer, so memory use stays bounded by one page
 * worth of in-flight cells regardless of total table size.
 *
 * Reference: https://www.sqlite.org/fileformat.html#b_tree_pages
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
final class LeafPageBuilder
{
    public const int PAGE_SIZE = 4096;
    private const int LEAF_HEADER_SIZE = 8;
    private const int TABLE_LEAF_TYPE = 0x0d;

    /** @var list<string> */
    private array $cells = [];
    private int $cells_bytes = 0;
    private int $current_max_rowid = 0;

    private int $next_pgno;
    private int $pages_written = 0;

    /** @var list<array{int, int}> [max_rowid_in_page, pgno] */
    private array $page_index = [];

    /** @var \Closure(int, string): void */
    private \Closure $emit;

    /**
     * @param int $first_pgno 1-based SQLite page number of the first
     *     leaf this builder will emit.
     * @param int $max_pages soft budget; throws if exceeded.
     * @param \Closure(int, string): void $emit invoked with
     *     `(pgno, page_bytes)` whenever a 4 KiB page is finalised.
     */
    public function __construct(
        int $first_pgno,
        private int $max_pages,
        \Closure $emit,
    ) {
        $this->next_pgno = $first_pgno;
        $this->emit = $emit;
    }

    /**
     * Append one already-encoded table-leaf cell. `$cell` is the full
     * `varint payload_size, varint rowid, payload` byte sequence as
     * produced by {@see TableCellEncoder::encodeTableLeafCell}.
     */
    public function append(int $rowid, string $cell): void
    {
        $cell_len = strlen($cell);
        $required = self::LEAF_HEADER_SIZE
            + 2 * (count($this->cells) + 1)
            + $this->cells_bytes
            + $cell_len;
        if ($required > self::PAGE_SIZE && count($this->cells) > 0) {
            $this->flush();
        }
        $this->cells[] = $cell;
        $this->cells_bytes += $cell_len;
        $this->current_max_rowid = $rowid;
    }

    /**
     * Finalise any in-flight cells and return the page index
     * `[(max_rowid, pgno), ...]` the interior-page builder will need.
     *
     * @return list<array{int, int}>
     */
    public function finalize(): array
    {
        if (count($this->cells) > 0) {
            $this->flush();
        }
        return $this->page_index;
    }

    public function pagesWritten(): int
    {
        return $this->pages_written;
    }

    private function flush(): void
    {
        if ($this->pages_written >= $this->max_pages) {
            throw new \RuntimeException(
                "LeafPageBuilder exceeded page budget of {$this->max_pages} pages",
            );
        }
        $page = self::assembleLeafPage($this->cells, $this->cells_bytes);
        ($this->emit)($this->next_pgno, $page);
        $this->page_index[] = [$this->current_max_rowid, $this->next_pgno];
        $this->next_pgno++;
        $this->pages_written++;
        $this->cells = [];
        $this->cells_bytes = 0;
    }

    /**
     * Pure page-bytes assembler — exposed for unit tests. Mirrors
     * SQLite's freelist allocation order: cells are laid out so the
     * first-appended cell sits at the highest offset and subsequent
     * cells grow downward toward the cell-pointer array. The pointer
     * array still lists them in append (rowid-ascending) order, so
     * the resulting page is byte-identical to what SQLite writes for
     * an equivalent INSERT sequence.
     *
     * @param list<string> $cells
     */
    public static function assembleLeafPage(array $cells, int $cells_bytes): string
    {
        $n = count($cells);
        if ($n === 0) {
            throw new \InvalidArgumentException('cannot assemble an empty leaf page');
        }
        $content_start = self::PAGE_SIZE - $cells_bytes;
        $pointer_array_end = self::LEAF_HEADER_SIZE + 2 * $n;
        if ($content_start < $pointer_array_end) {
            throw new \RuntimeException(
                'cells exceed page capacity: '
                . "{$cells_bytes} cell bytes + {$pointer_array_end} header/pointer bytes > "
                . self::PAGE_SIZE,
            );
        }

        $offsets = [];
        $cursor = self::PAGE_SIZE;
        foreach ($cells as $cell) {
            $cursor -= strlen($cell);
            $offsets[] = $cursor;
        }

        // PAGE_SIZE = 4096 → content_start fits in u16 verbatim.
        // SQLite's "0 means 65536" encoding for the cell-content-start
        // field only matters when page_size = 65536, which we don't
        // support.
        $header = chr(self::TABLE_LEAF_TYPE)
            . "\x00\x00"
            . pack('n', $n)
            . pack('n', $content_start)
            . "\x00";
        $pointer_array = pack('n*', ...$offsets);
        $free_bytes = $content_start - $pointer_array_end;
        return $header
            . $pointer_array
            . ($free_bytes > 0 ? str_repeat("\x00", $free_bytes) : '')
            . implode('', array_reverse($cells));
    }
}
