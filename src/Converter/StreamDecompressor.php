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

namespace Reli\Converter;

use Reli\Converter\BinaryTrace\BinaryTraceException;

/**
 * Transparent gzip decompression for input streams.
 *
 * Peeks the first 2 bytes for the gzip magic number (0x1f 0x8b).
 * If found, decompresses the entire stream (including concatenated
 * gzip members per RFC 1952) into a memory buffer.
 * If not, returns the original data as-is (prepending the peeked bytes).
 */
final class StreamDecompressor
{
    /**
     * If the stream starts with gzip magic, decompress it into a memory stream.
     * Handles concatenated gzip members (multiple gzencode outputs appended).
     * Otherwise, return a stream with the original content.
     *
     * @param resource $stream
     * @return resource A seekable stream with decompressed (or original) data
     */
    public static function decompressIfNeeded($stream)
    {
        $peek = fread($stream, 2);
        if ($peek === false || strlen($peek) < 2) {
            $out = fopen('php://memory', 'r+');
            assert($out !== false);
            if ($peek !== false && $peek !== '') {
                fwrite($out, $peek);
            }
            rewind($out);
            return $out;
        }

        if ($peek === "\x1f\x8b") {
            return self::decompressConcatenatedGzip($peek, $stream);
        }

        // Not gzip — prepend peeked bytes and return
        $out = fopen('php://memory', 'r+');
        assert($out !== false);
        fwrite($out, $peek);
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($out, $chunk);
        }
        rewind($out);
        return $out;
    }

    /**
     * Decompress concatenated gzip members using gzopen/gzread,
     * which correctly handles multiple gzip members in one stream.
     *
     * @param resource $stream
     * @return resource
     */
    private static function decompressConcatenatedGzip(string $peeked, $stream)
    {
        // Write full compressed data to a temp file for gzopen
        $tmp = tempnam(sys_get_temp_dir(), 'reli_gz_');
        if ($tmp === false) {
            throw new BinaryTraceException('Failed to create temp file for gzip decompression');
        }

        $tmp_fh = fopen($tmp, 'wb');
        assert($tmp_fh !== false);
        fwrite($tmp_fh, $peeked);
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($tmp_fh, $chunk);
        }
        fclose($tmp_fh);

        // Use gzopen to decompress all concatenated members
        $gz = gzopen($tmp, 'rb');
        if ($gz === false) {
            unlink($tmp);
            throw new BinaryTraceException('Failed to decompress gzip input');
        }

        $out = fopen('php://memory', 'r+');
        assert($out !== false);
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($out, $chunk);
        }
        gzclose($gz);
        unlink($tmp);

        if (ftell($out) === 0) {
            fclose($out);
            throw new BinaryTraceException('Failed to decompress gzip input');
        }

        rewind($out);
        return $out;
    }
}
