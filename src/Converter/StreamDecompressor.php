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
 * If found, decompresses the entire stream into a memory buffer.
 * If not, returns the original data as-is (prepending the peeked bytes).
 */
final class StreamDecompressor
{
    /**
     * If the stream starts with gzip magic, decompress it into a memory stream.
     * Otherwise, return a stream with the original content.
     *
     * @param resource $stream
     * @return resource A seekable stream with decompressed (or original) data
     */
    public static function decompressIfNeeded($stream)
    {
        $peek = fread($stream, 2);
        if ($peek === false || strlen($peek) < 2) {
            // Too short — wrap what we got
            $out = fopen('php://memory', 'r+');
            assert($out !== false);
            if ($peek !== false && $peek !== '') {
                fwrite($out, $peek);
            }
            rewind($out);
            return $out;
        }

        if ($peek === "\x1f\x8b") {
            // Gzip magic detected — read all and decompress
            $compressed = $peek;
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $compressed .= $chunk;
            }

            $decompressed = @gzdecode($compressed);
            if ($decompressed === false) {
                throw new BinaryTraceException('Failed to decompress gzip input');
            }

            $out = fopen('php://memory', 'r+');
            assert($out !== false);
            fwrite($out, $decompressed);
            rewind($out);
            return $out;
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
}
