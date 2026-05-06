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
 * Two open modes:
 *
 *   - {@see open}: builds in memory, flushes the whole file with one
 *     file_put_contents on close. Simple, used by TableMerger when
 *     the target starts empty so re-writing the existing region adds
 *     no extra work.
 *
 *   - {@see openForPatch}: opens the file in `rb+` mode without
 *     slurping it. Pages added via appendPage are buffered in memory;
 *     pages overwritten via writePage are tracked separately. close()
 *     pwrites only the modified pages plus the appended tail and
 *     patches the in-place file header — the unmodified region of an
 *     existing 88 MB output is left as-is on disk. Used by the
 *     integer-index merge step which only touches a few index
 *     rootpages and appends an index leaf tail to a file that's
 *     already nearly final.
 *
 * The merger keeps the same sqlite_master.rootpage values that SQLite
 * assigned at CREATE TABLE / CREATE INDEX time, so it never has to
 * round-trip through the schema table.
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
     * Original bytes of the file at open() time, populated only in
     * the slurped mode. In patch mode this stays an empty string and
     * existing pages are read lazily from $fh.
     */
    private string $base_bytes = '';

    /** @var resource|null file handle for patch mode */
    private $fh = null;

    /** @var list<string> appended page contents in order */
    private array $appended_pages = [];

    /** @var array<int, string> 1-indexed page no => new page bytes */
    private array $rewritten_pages = [];

    private bool $patch_mode;

    private function __construct(
        string $bytes,
        public readonly int $page_size,
        private int $original_page_count,
        private int $page_count,
        bool $patch_mode,
        mixed $fh,
    ) {
        $this->base_bytes = $bytes;
        $this->patch_mode = $patch_mode;
        if (is_resource($fh)) {
            $this->fh = $fh;
        }
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
        return new self(
            $bytes,
            (int)$page_size,
            $page_count,
            $page_count,
            patch_mode: false,
            fh: null,
        );
    }

    /**
     * Open in patch mode: reads only the 100-byte header, leaves the
     * rest of the file on disk. Subsequent appendPage / writePage
     * calls are buffered; close() pwrites the modifications without
     * rewriting the unchanged region.
     */
    public static function openForPatch(string $path): self
    {
        $fh = fopen($path, 'rb+');
        if ($fh === false) {
            throw new \RuntimeException("failed to open SQLite file for patch: {$path}");
        }
        $header = fread($fh, Format::HEADER_SIZE);
        if ($header === false || strlen($header) < Format::HEADER_SIZE) {
            fclose($fh);
            throw new \RuntimeException("file is shorter than SQLite header: {$path}");
        }
        if (substr($header, 0, 16) !== Format::MAGIC) {
            fclose($fh);
            throw new \RuntimeException("not a SQLite database (bad magic): {$path}");
        }
        $page_size_field = unpack('n', substr($header, Format::HDR_PAGE_SIZE, 2))[1];
        $page_size = $page_size_field === 1 ? 65536 : (int)$page_size_field;
        $page_count = (int)unpack('N', substr($header, Format::HDR_DB_PAGE_COUNT, 4))[1];
        return new self(
            $header,
            (int)$page_size,
            $page_count,
            $page_count,
            patch_mode: true,
            fh: $fh,
        );
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
        if ($this->patch_mode) {
            \assert($this->fh !== null);
            if (fseek($this->fh, $offset) !== 0) {
                throw new \RuntimeException("failed to seek to page {$pgno}");
            }
            $bytes = fread($this->fh, $this->page_size);
            if ($bytes === false || strlen($bytes) !== $this->page_size) {
                throw new \RuntimeException("short read on page {$pgno}");
            }
            return $bytes;
        }
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
     *
     * In patch mode (opened via {@see openForPatch}) only the modified
     * pages, the appended tail, and the header are pwritten — the
     * unchanged region of the file is left as-is on disk. In slurped
     * mode the whole file is rewritten.
     */
    public function close(string $path): void
    {
        if ($this->patch_mode) {
            $this->closePatch($path);
            return;
        }

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
        $bytes = $this->bumpHeader($bytes);

        // Write everything: original (with in-place rewrites) followed
        // by appended pages. implode() does a single linear pass over
        // the appended chunks, avoiding the O(N^2) growth of repeated
        // string concatenation.
        $payload = $bytes . implode('', $this->appended_pages);
        if (file_put_contents($path, $payload) === false) {
            throw new \RuntimeException("failed to write SQLite file: {$path}");
        }
    }

    private function closePatch(string $_path): void
    {
        \assert($this->fh !== null);
        $fh = $this->fh;
        try {
            // Bump header in place. The header stayed in $this->base_bytes
            // since openForPatch slurped just the first 100 bytes.
            $header = $this->bumpHeader($this->base_bytes);
            if (fseek($fh, 0) !== 0) {
                throw new \RuntimeException('failed to seek to file header');
            }
            if (fwrite($fh, $header) !== Format::HEADER_SIZE) {
                throw new \RuntimeException('short write on file header');
            }

            // Pwrite each rewritten page. Pages whose pgno is in the
            // appended range get folded into the appended tail below;
            // those in the original region are pwritten in place.
            foreach ($this->rewritten_pages as $pgno => $data) {
                if ($pgno > $this->original_page_count) {
                    // Page was appended *and* later rewritten — fold
                    // back into appended_pages so the tail write picks
                    // it up.
                    $append_idx = $pgno - 1 - $this->original_page_count;
                    $this->appended_pages[$append_idx] = $data;
                    continue;
                }
                $offset = ($pgno - 1) * $this->page_size;
                if (fseek($fh, $offset) !== 0) {
                    throw new \RuntimeException("failed to seek to page {$pgno}");
                }
                if (fwrite($fh, $data) !== $this->page_size) {
                    throw new \RuntimeException("short write on page {$pgno}");
                }
            }

            // Write appended tail in one shot at the end of the
            // original-region region.
            if ($this->appended_pages !== []) {
                $tail = implode('', $this->appended_pages);
                $tail_offset = $this->original_page_count * $this->page_size;
                if (fseek($fh, $tail_offset) !== 0) {
                    throw new \RuntimeException('failed to seek to appended tail');
                }
                if (fwrite($fh, $tail) !== strlen($tail)) {
                    throw new \RuntimeException('short write on appended tail');
                }
            }
        } finally {
            fclose($fh);
            $this->fh = null;
        }
    }

    /**
     * Patch the in-memory header buffer with new page count, change
     * counter, and schema cookie. Returns the patched header bytes.
     */
    private function bumpHeader(string $bytes): string
    {
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
        return $bytes;
    }
}
