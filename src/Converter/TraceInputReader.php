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

use Reli\Converter\BinaryTrace\BinaryTraceReader;
use Reli\Converter\BinaryTrace\BinaryTraceWriter;

/**
 * Auto-detecting trace input reader.
 *
 * Handles gzip-compressed input transparently (detects 0x1f 0x8b magic).
 * Then peeks the first 4 bytes to distinguish rbt ("RELI") from phpspy text.
 * Yields ParsedCallTrace for all formats.
 */
final class TraceInputReader
{
    private ?BinaryTraceReader $binary_reader = null;

    /**
     * @param resource $stream
     * @return iterable<ParsedCallTrace>
     */
    public function read($stream): iterable
    {
        // Transparently decompress gzip input.
        // For raw input, returns the original stream (seeked back) or
        // a php://temp buffer for non-seekable streams like stdin.
        $stream = StreamDecompressor::decompressIfNeeded($stream);

        $magic = fread($stream, 4);
        if ($magic === false || strlen($magic) < 4) {
            return;
        }

        if ($magic === BinaryTraceWriter::MAGIC) {
            // rbt format — seek back to start so reader gets the full header
            $meta = stream_get_meta_data($stream);
            if ($meta['seekable']) {
                fseek($stream, 0);
                $this->binary_reader = new BinaryTraceReader();
                foreach ($this->binary_reader->read($stream) as $sample) {
                    yield $sample->trace;
                }
            } else {
                // Non-seekable: buffer into php://temp
                $wrapped = fopen('php://temp', 'r+');
                assert($wrapped !== false);
                fwrite($wrapped, $magic);
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    fwrite($wrapped, $chunk);
                }
                rewind($wrapped);
                $this->binary_reader = new BinaryTraceReader();
                foreach ($this->binary_reader->read($wrapped) as $sample) {
                    yield $sample->trace;
                }
                fclose($wrapped);
            }
        } else {
            // phpspy text format — seek back or buffer
            $meta = stream_get_meta_data($stream);
            if ($meta['seekable']) {
                fseek($stream, 0);
                $parser = new PhpSpyCompatibleParser();
                yield from $parser->parseFile($stream);
            } else {
                $wrapped = fopen('php://temp', 'r+');
                assert($wrapped !== false);
                fwrite($wrapped, $magic);
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    fwrite($wrapped, $chunk);
                }
                rewind($wrapped);
                $parser = new PhpSpyCompatibleParser();
                yield from $parser->parseFile($wrapped);
                fclose($wrapped);
            }
        }
    }

    public function getBinaryReader(): ?BinaryTraceReader
    {
        return $this->binary_reader;
    }
}
