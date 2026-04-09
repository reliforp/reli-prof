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

namespace Reli\Converter\BinaryTrace;

use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;

final class BinaryTraceReader
{
    /** @var array<int, ParsedCallFrame> frame_id => frame */
    private array $frames = [];

    /** @var array<int, int[]> stack_id => frame_id[] */
    private array $stacks = [];

    private int $sampling_period_us = 0;
    private int $flags = 0;

    /**
     * Read header and yield ParsedCallTrace for each SAMPLE event.
     *
     * @param resource $stream
     * @return iterable<ParsedCallTrace>
     */
    public function read($stream): iterable
    {
        $this->readHeader($stream);

        while (!feof($stream)) {
            $type_byte = fread($stream, 1);
            if ($type_byte === '' || $type_byte === false) {
                break;
            }

            $type_int = ord($type_byte);
            $payload_length = Varint::decodeFromStream($stream);

            $type = EventType::tryFrom($type_int);
            if ($type === null) {
                // Unknown event: skip payload
                if ($payload_length > 0) {
                    $this->readExact($stream, $payload_length);
                }
                continue;
            }

            $payload = $payload_length > 0 ? $this->readExact($stream, $payload_length) : '';

            switch ($type) {
                case EventType::FRAME_DEF:
                    $this->handleFrameDef($payload);
                    break;
                case EventType::STACK_DEF:
                    $this->handleStackDef($payload);
                    break;
                case EventType::SAMPLE:
                    yield $this->handleSample($payload);
                    break;
                case EventType::CHECKPOINT:
                case EventType::SEGMENT_END:
                    // Informational, no action needed for basic reading
                    break;
            }
        }
    }

    public function getSamplingPeriodUs(): int
    {
        return $this->sampling_period_us;
    }

    public function hasTimestamps(): bool
    {
        return ($this->flags & BinaryTraceWriter::FLAG_HAS_TIMESTAMPS) !== 0;
    }

    /**
     * @param resource $stream
     */
    private function readHeader($stream): void
    {
        $header = $this->readExact($stream, 16);

        $magic = substr($header, 0, 4);
        if ($magic !== BinaryTraceWriter::MAGIC) {
            throw new BinaryTraceException(
                sprintf('Invalid magic: expected "RELI", got "%s"', $magic)
            );
        }

        $version = ord($header[4]);
        if ($version !== BinaryTraceWriter::VERSION) {
            throw new BinaryTraceException(
                sprintf('Unsupported version: %d (expected %d)', $version, BinaryTraceWriter::VERSION)
            );
        }

        $this->flags = ord($header[5]);
        // bytes 6-7: reserved
        $this->sampling_period_us = unpack('V', substr($header, 8, 4))[1];
        // bytes 12-15: reserved
    }

    private function handleFrameDef(string $payload): void
    {
        $offset = 0;
        [$frame_id, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        [$func_len, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        $function_name = substr($payload, $offset, $func_len);
        $offset += $func_len;

        [$file_len, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;
        $file_name = substr($payload, $offset, $file_len);
        $offset += $file_len;

        [$lineno, $consumed] = Varint::decode($payload, $offset);

        $this->frames[$frame_id] = new ParsedCallFrame($function_name, $file_name, $lineno);
    }

    private function handleStackDef(string $payload): void
    {
        $offset = 0;
        [$stack_id, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        [$depth, $consumed] = Varint::decode($payload, $offset);
        $offset += $consumed;

        $frame_ids = [];
        for ($i = 0; $i < $depth; $i++) {
            [$fid, $consumed] = Varint::decode($payload, $offset);
            $offset += $consumed;
            $frame_ids[] = $fid;
        }

        $this->stacks[$stack_id] = $frame_ids;
    }

    private function handleSample(string $payload): ParsedCallTrace
    {
        $offset = 0;
        [$stack_id, $consumed] = Varint::decode($payload, $offset);

        if (!isset($this->stacks[$stack_id])) {
            throw new BinaryTraceException("Reference to undefined stack_id: {$stack_id}");
        }

        $frame_ids = $this->stacks[$stack_id];
        $frames = [];
        foreach ($frame_ids as $fid) {
            if (!isset($this->frames[$fid])) {
                throw new BinaryTraceException("Reference to undefined frame_id: {$fid}");
            }
            $frames[] = $this->frames[$fid];
        }

        return new ParsedCallTrace(...$frames);
    }

    /**
     * @param resource $stream
     */
    private function readExact($stream, int $length): string
    {
        $data = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = fread($stream, $remaining);
            if ($chunk === '' || $chunk === false) {
                throw new BinaryTraceException(
                    sprintf('Unexpected end of stream: expected %d bytes, got %d', $length, $length - $remaining)
                );
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }
}
