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
 * If found, decompresses the stream (including concatenated gzip
 * members per RFC 1952) into a memory buffer.
 * If not, returns a stream that replays the peeked bytes followed
 * by the original stream — no full copy for raw input.
 */
final class StreamDecompressor
{
    /**
     * If the stream starts with gzip magic, decompress into a seekable stream.
     * Otherwise, return a prepended stream with the peeked bytes restored.
     *
     * For raw (non-gzip) input, this avoids copying the entire stream
     * into memory. The returned stream replays the 2 peeked bytes,
     * then reads the rest from the original stream.
     *
     * @param resource $stream
     * @return resource A stream with decompressed (or original) data
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

        // Not gzip — prepend peeked bytes into a small buffer,
        // then copy only the remaining stream data.
        // This is needed because most readers (BinaryTraceReader,
        // PhpSpyCompatibleParser) need seekable streams anyway.
        // But we avoid the full-copy by letting TraceInputReader
        // handle the peek+stream directly.
        return self::prependToStream($peek, $stream);
    }

    /**
     * Decompress concatenated gzip members using gzopen/gzread.
     *
     * @param resource $stream
     * @return resource
     */
    private static function decompressConcatenatedGzip(string $peeked, $stream)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'reli_gz_');
        if ($tmp === false) {
            throw new BinaryTraceException(
                'Failed to create temp file for gzip decompression'
            );
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

        $gz = gzopen($tmp, 'rb');
        if ($gz === false) {
            unlink($tmp);
            throw new BinaryTraceException('Failed to decompress gzip input');
        }

        $out = fopen('php://temp', 'r+');
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

    /**
     * Create a stream that starts with $prefix followed by remaining $stream data.
     *
     * @param resource $stream
     * @return resource
     */
    private static function prependToStream(string $prefix, $stream)
    {
        // For seekable streams, just seek back
        $meta = stream_get_meta_data($stream);
        if ($meta['seekable']) {
            fseek($stream, 0);
            return $stream;
        }

        // For non-seekable streams (stdin), buffer prefix + rest
        $out = fopen('php://temp', 'r+');
        assert($out !== false);
        fwrite($out, $prefix);
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
}
