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
 * Page-level writer for an existing SQLite database file.
 *
 * Builds in memory and flushes to disk on close(). The expected use is
 * a one-shot merge: open the file, append shard leaf pages, overwrite
 * the per-table rootpages with newly-built interior pages, update the
 * header, write back. The merger keeps the same sqlite_master.rootpage
 * values that SQLite assigned at CREATE TABLE time, so it never has
 * to round-trip through the schema table.
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedOperand
 * @psalm-suppress MixedArgument
 * @psalm-suppress PossiblyInvalidArrayAccess
 * @psalm-suppress MixedArgumentTypeCoercion
 * @psalm-suppress RedundantCast
 * @psalm-suppress MissingConstructor
 * @psalm-suppress InvalidArrayOffset
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress PropertyTypeCoercion
 */
final class Writer
{
    /**
     * Original bytes of the file at open() time. Subsequent appends
     * accumulate in $appended_pages instead of being concat'd into a
     * single string — `$bytes .= $page` is O(strlen($bytes)) per
     * call, which becomes quadratic over thousands of appended pages.
     * Pages overwritten via writePage() are tracked in
     * $rewritten_pages so close() can apply them to the original
     * region of the file.
     */
    private string $base_bytes;

    /** @var list<string> appended page contents in order */
    private array $appended_pages = [];

    /** @var array<int, string> 1-indexed page no => new page bytes */
    private array $rewritten_pages = [];

    private function __construct(
        string $bytes,
        public readonly int $page_size,
        private int $original_page_count,
        private int $page_count,
    ) {
        $this->base_bytes = $bytes;
    }

    public static function open(string $path): self
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("failed to open SQLite file: {$path}");
        }
        if (strlen($bytes) < Format::HEADER_SIZE) {
            throw new \RuntimeException("file is shorter than SQLite header: {$path}");
        }
        if (substr($bytes, 0, 16) !== Format::MAGIC) {
            throw new \RuntimeException("not a SQLite database (bad magic): {$path}");
        }
        $page_size_field = unpack('n', substr($bytes, Format::HDR_PAGE_SIZE, 2))[1];
        $page_size = $page_size_field === 1 ? 65536 : (int)$page_size_field;
        $page_count = (int)unpack('N', substr($bytes, Format::HDR_DB_PAGE_COUNT, 4))[1];
        return new self($bytes, (int)$page_size, $page_count, $page_count);
    }

    /**
     * Append `$data` (which must be exactly page_size bytes long) as a
     * new page at the end of the file. Returns the new page's
     * 1-indexed page number.
     */
    public function appendPage(string $data): int
    {
        if (strlen($data) !== $this->page_size) {
            throw new \InvalidArgumentException(
                'page must be exactly ' . $this->page_size
                . ' bytes; got ' . strlen($data)
            );
        }
        $this->appended_pages[] = $data;
        $this->page_count++;
        return $this->page_count;
    }

    /**
     * Overwrite an existing page (1-indexed) with the given data.
     */
    public function writePage(int $pgno, string $data): void
    {
        if ($pgno < 1 || $pgno > $this->page_count) {
            throw new \OutOfRangeException("page {$pgno} out of range (1..{$this->page_count})");
        }
        if (strlen($data) !== $this->page_size) {
            throw new \InvalidArgumentException(
                'page must be exactly ' . $this->page_size
                . ' bytes; got ' . strlen($data)
            );
        }
        $this->rewritten_pages[$pgno] = $data;
    }

    /**
     * Read a page (used by the merger to grab the current contents of
     * a rootpage before rewriting it).
     */
    public function readPage(int $pgno): string
    {
        if ($pgno < 1 || $pgno > $this->page_count) {
            throw new \OutOfRangeException("page {$pgno} out of range (1..{$this->page_count})");
        }
        if (isset($this->rewritten_pages[$pgno])) {
            return $this->rewritten_pages[$pgno];
        }
        if ($pgno > $this->original_page_count) {
            return $this->appended_pages[$pgno - 1 - $this->original_page_count];
        }
        $offset = ($pgno - 1) * $this->page_size;
        return substr($this->base_bytes, $offset, $this->page_size);
    }

    public function pageCount(): int
    {
        return $this->page_count;
    }

    /**
     * Finalise: bump the change counter, schema cookie (so cached
     * statements get invalidated), update the in-header page count,
     * and flush. After this the writer must not be used.
     */
    public function close(string $path): void
    {
        // Apply rewritten pages to the original region of the file.
        $bytes = $this->base_bytes;
        foreach ($this->rewritten_pages as $pgno => $data) {
            $offset = ($pgno - 1) * $this->page_size;
            if ($offset + $this->page_size <= strlen($bytes)) {
                $bytes = substr_replace($bytes, $data, $offset, $this->page_size);
                continue;
            }
            // Page was added by appendPage() and later rewritten.
            // Patch the corresponding entry in $appended_pages so the
            // imploded suffix below picks it up.
            $append_idx = $pgno - 1 - $this->original_page_count;
            $this->appended_pages[$append_idx] = $data;
        }

        // Update in-header page count, change counter, schema cookie
        // (defensive — schema shape unchanged but rootpage contents
        // changed in-place).
        $bytes = substr_replace(
            $bytes,
            pack('N', $this->page_count),
            Format::HDR_DB_PAGE_COUNT,
            4,
        );
        $cc = (int)unpack('N', substr($bytes, Format::HDR_CHANGE_COUNTER, 4))[1];
        $bytes = substr_replace(
            $bytes,
            pack('N', ($cc + 1) & 0xffffffff),
            Format::HDR_CHANGE_COUNTER,
            4,
        );
        $sc = (int)unpack('N', substr($bytes, Format::HDR_SCHEMA_COOKIE, 4))[1];
        $bytes = substr_replace(
            $bytes,
            pack('N', ($sc + 1) & 0xffffffff),
            Format::HDR_SCHEMA_COOKIE,
            4,
        );

        // Write everything: original (with in-place rewrites) followed
        // by appended pages. implode() does a single linear pass over
        // the appended chunks, avoiding the O(N^2) growth of repeated
        // string concatenation.
        $payload = $bytes . implode('', $this->appended_pages);
        if (file_put_contents($path, $payload) === false) {
            throw new \RuntimeException("failed to write SQLite file: {$path}");
        }
    }
}
