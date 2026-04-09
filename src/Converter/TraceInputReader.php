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
 * Peeks the first 4 bytes to distinguish rbt (magic "RELI") from phpspy text.
 * Yields ParsedCallTrace for all formats so converters don't need to know the input type.
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
        $magic = fread($stream, 4);
        if ($magic === false || strlen($magic) < 4) {
            return;
        }

        if ($magic === BinaryTraceWriter::MAGIC) {
            // rbt format — reconstruct the full header and read
            $header_rest = fread($stream, 12);
            if ($header_rest === false || strlen($header_rest) < 12) {
                return;
            }

            // Write the full header into a memory stream so the reader can parse it
            $wrapped = fopen('php://memory', 'r+');
            assert($wrapped !== false);
            fwrite($wrapped, $magic . $header_rest);

            // Copy remaining data
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
        } else {
            // phpspy text format — prepend the peeked bytes back
            $wrapped = fopen('php://memory', 'r+');
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

    public function getBinaryReader(): ?BinaryTraceReader
    {
        return $this->binary_reader;
    }
}
