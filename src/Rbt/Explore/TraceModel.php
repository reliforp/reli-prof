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

namespace Reli\Rbt\Explore;

use Reli\Converter\BinaryTrace\BinaryTraceReader;
use Reli\Converter\StreamDecompressor;

/**
 * In-memory model of a .rbt trace, optimised for the interactive
 * explorer's repeated aggregation queries.
 *
 * Frames are interned twice: once with `file:line` ("with-line") and
 * once without ("no-line"). Each sample stores the with-line frame
 * IDs (leaf-to-root); the no-line view applies `$no_line_map` on the
 * fly so we don't keep two parallel sample arrays.
 */
final class TraceModel
{
    /**
     * @param list<string>     $frame_keys         frame_id => "function file:line"
     * @param list<string>     $frame_keys_no_line no_line_id => "function"
     * @param list<int>        $no_line_map        frame_id => no_line_id
     * @param list<list<int>>  $samples            sample_idx => list of frame_ids (leaf->root)
     */
    public function __construct(
        public readonly array $frame_keys,
        public readonly array $frame_keys_no_line,
        public readonly array $no_line_map,
        public readonly array $samples,
        public readonly int $sampling_period_us,
        public readonly string $source_path,
    ) {
    }

    /**
     * Load a .rbt file (gzip-aware) into a fully realised model.
     */
    public static function load(string $path): self
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open trace file: {$path}");
        }
        try {
            $decoded = StreamDecompressor::decompressIfNeeded($stream);
            $reader = new BinaryTraceReader();

            /** @var array<string, int> $key_to_id */
            $key_to_id = [];
            /** @var list<string> $frame_keys */
            $frame_keys = [];

            /** @var array<string, int> $no_line_to_id */
            $no_line_to_id = [];
            /** @var list<string> $frame_keys_no_line */
            $frame_keys_no_line = [];

            /** @var list<int> $no_line_map */
            $no_line_map = [];

            /** @var list<list<int>> $samples */
            $samples = [];

            foreach ($reader->read($decoded) as $sample) {
                $stack = [];
                foreach ($sample->trace->call_frames as $frame) {
                    $function = $frame->function_name;
                    $file = $frame->file_name;
                    $line = $frame->lineno;
                    $with_line = $function . ' ' . $file . ':' . $line;

                    $frame_id = $key_to_id[$with_line] ?? null;
                    if ($frame_id === null) {
                        $frame_id = count($frame_keys);
                        $key_to_id[$with_line] = $frame_id;
                        $frame_keys[] = $with_line;

                        $no_line_id = $no_line_to_id[$function] ?? null;
                        if ($no_line_id === null) {
                            $no_line_id = count($frame_keys_no_line);
                            $no_line_to_id[$function] = $no_line_id;
                            $frame_keys_no_line[] = $function;
                        }
                        // frame_id == count($no_line_map) at this point,
                        // so [] append keeps no_line_map[$frame_id] in sync.
                        $no_line_map[] = $no_line_id;
                    }
                    $stack[] = $frame_id;
                }
                $samples[] = $stack;
            }

            return new self(
                frame_keys: $frame_keys,
                frame_keys_no_line: $frame_keys_no_line,
                no_line_map: $no_line_map,
                samples: $samples,
                sampling_period_us: $reader->getSamplingPeriodUs(),
                source_path: $path,
            );
        } finally {
            fclose($stream);
        }
    }

    public function sampleCount(): int
    {
        return count($this->samples);
    }

    public function durationSeconds(): float
    {
        if ($this->sampling_period_us <= 0) {
            return 0.0;
        }
        return (float)$this->sampleCount() * (float)$this->sampling_period_us / 1_000_000.0;
    }

    /**
     * @return list<string>
     */
    public function keysFor(bool $no_line): array
    {
        return $no_line ? $this->frame_keys_no_line : $this->frame_keys;
    }
}
