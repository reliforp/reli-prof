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

use FFI\CData;
use Reli\Lib\File\LibcFileWriter;

/**
 * Final-pass housekeeping for a SQLite file the shared-mmap writer
 * has populated:
 *
 *   - install unused mid-file pages onto a freelist trunk so SQLite's
 *     `integrity_check` doesn't flag them as orphans;
 *   - update the in-header database size and bump the file change
 *     counter / version-valid-for so a freshly-opened PDO connection
 *     sees a consistent file.
 *
 * Reference: https://www.sqlite.org/fileformat.html#the_database_header
 *
 * @psalm-suppress MixedArgument
 */
final class SqliteFileMaintainer
{
    private const int PAGE_SIZE = 4096;
    private const int MAX_LEAVES_PER_TRUNK = (self::PAGE_SIZE - 8) / 4;

    public const int FILE_HEADER_CHANGE_COUNTER_OFF = 24;
    public const int FILE_HEADER_PAGE_COUNT_OFF = 28;
    public const int FILE_HEADER_FREELIST_TRUNK_OFF = 32;
    public const int FILE_HEADER_FREELIST_COUNT_OFF = 36;
    public const int FILE_HEADER_VERSION_VALID_FOR_OFF = 92;

    /**
     * Install `$unused_pgnos` onto the freelist and update the file
     * header so SQLite considers the file consistent. Splits across
     * multiple trunk pages if needed; the first unused pgno becomes
     * the first trunk and is consumed for that purpose.
     *
     * @param list<int> $unused_pgnos
     */
    /**
     * Lightweight header update for callers that have already
     * extended a SQLite file (e.g. {@see ParallelIndexBuilder}'s
     * append phase) and just need to refresh the in-header page
     * count + bump the change counter / version-valid-for so a
     * freshly-opened PDO connection trusts the new size. Uses
     * regular file I/O instead of mmap so the caller doesn't have
     * to re-mmap just to twiddle 12 bytes.
     */
    public static function updateFileHeaderViaFp(string $path, int $total_pages): void
    {
        $fp = fopen($path, 'r+b');
        if ($fp === false) {
            throw new \RuntimeException("failed to open {$path} for header update");
        }
        try {
            fseek($fp, self::FILE_HEADER_CHANGE_COUNTER_OFF);
            $bytes = fread($fp, 4);
            if ($bytes === false || strlen($bytes) !== 4) {
                throw new \RuntimeException('failed to read change counter');
            }
            /** @var array{1: int} $cc */
            $cc = unpack('N', $bytes);
            $new_counter = $cc[1] + 1;

            fseek($fp, self::FILE_HEADER_CHANGE_COUNTER_OFF);
            fwrite($fp, pack('N', $new_counter) . pack('N', $total_pages));
            fseek($fp, self::FILE_HEADER_VERSION_VALID_FOR_OFF);
            fwrite($fp, pack('N', $new_counter));
        } finally {
            fclose($fp);
        }
    }

    public static function finalize(
        CData $data_ptr,
        int $first_mapped_pgno,
        int $total_pages,
        array $unused_pgnos,
    ): void {
        sort($unused_pgnos);
        $trunk_chain = self::buildTrunkChain($unused_pgnos);
        foreach ($trunk_chain as [$trunk_pgno, $trunk_bytes]) {
            LibcFileWriter::memcpyFromString(
                $data_ptr,
                ($trunk_pgno - $first_mapped_pgno) * self::PAGE_SIZE,
                $trunk_bytes,
            );
        }

        // Read the existing change counter (4 BE) so we can bump it.
        $counter_bytes = self::readBytes($data_ptr, self::FILE_HEADER_CHANGE_COUNTER_OFF, 4);
        /** @var array{1: int} $cc */
        $cc = unpack('N', $counter_bytes);
        $new_counter = $cc[1] + 1;

        $first_trunk_pgno = $trunk_chain === [] ? 0 : $trunk_chain[0][0];
        $free_count = count($unused_pgnos);

        // Compose a single header patch covering all four updated
        // u32 fields in one memcpy.
        LibcFileWriter::memcpyFromString(
            $data_ptr,
            self::FILE_HEADER_CHANGE_COUNTER_OFF,
            pack('N', $new_counter)
                . pack('N', $total_pages)
                . pack('N', $first_trunk_pgno)
                . pack('N', $free_count),
        );
        // Mark the in-header page count as valid by setting
        // version-valid-for == change counter.
        LibcFileWriter::memcpyFromString(
            $data_ptr,
            self::FILE_HEADER_VERSION_VALID_FOR_OFF,
            pack('N', $new_counter),
        );
    }

    /**
     * Build the freelist trunk-page chain for the given unused pgnos.
     * The first unused pgno is consumed as the first trunk.
     *
     * @param list<int> $unused_pgnos sorted
     * @return list<array{int, string}> list of (trunk_pgno, page_bytes)
     */
    private static function buildTrunkChain(array $unused_pgnos): array
    {
        if ($unused_pgnos === []) {
            return [];
        }
        $chain = [];
        $i = 0;
        $n = count($unused_pgnos);
        while ($i < $n) {
            $trunk_pgno = $unused_pgnos[$i];
            $i++;
            $leaves = [];
            while ($i < $n && count($leaves) < self::MAX_LEAVES_PER_TRUNK) {
                $leaves[] = $unused_pgnos[$i];
                $i++;
                if (count($leaves) === self::MAX_LEAVES_PER_TRUNK && $i < $n) {
                    // Reserve the next pgno as the next trunk.
                    break;
                }
            }
            $chain[] = [$trunk_pgno, $leaves];
        }
        $out = [];
        $count_chain = count($chain);
        for ($idx = 0; $idx < $count_chain; $idx++) {
            [$trunk_pgno, $leaves] = $chain[$idx];
            $next_trunk = $idx + 1 < $count_chain ? $chain[$idx + 1][0] : 0;
            $body = pack('N', $next_trunk) . pack('N', count($leaves));
            foreach ($leaves as $leaf_pgno) {
                $body .= pack('N', $leaf_pgno);
            }
            $body .= str_repeat("\x00", self::PAGE_SIZE - strlen($body));
            $out[] = [$trunk_pgno, $body];
        }
        return $out;
    }

    private static function readBytes(CData $ptr, int $offset, int $length): string
    {
        $libc = \FFI::cdef('typedef unsigned char uint8_t;', null);
        /** @var \FFI\CArray<int> $view */
        $view = $libc->cast('uint8_t*', $ptr);
        /** @psalm-suppress InvalidArgument */
        return \FFI::string(\FFI::addr($view[$offset]), $length);
    }
}
