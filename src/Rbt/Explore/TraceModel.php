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
     * @param list<string>     $frame_keys             frame_id => "function file:line"
     * @param list<string>     $frame_keys_no_line     no_line_id => "function"
     * @param list<int>        $no_line_map            frame_id => no_line_id
     * @param list<string>     $frame_keys_opcode      opcode_id => "function file:line [OP]"
     * @param list<string>     $frame_keys_no_line_opcode nlo_id => "function [OP]"
     * @param list<int>        $opcode_map             frame_id => opcode_id
     * @param list<int>        $no_line_opcode_map     frame_id => nlo_id
     * @param list<list<int>>  $samples                sample_idx => list of frame_ids (leaf->root)
     */
    public function __construct(
        public readonly array $frame_keys,
        public readonly array $frame_keys_no_line,
        public readonly array $no_line_map,
        public readonly array $samples,
        public readonly int $sampling_period_us,
        public readonly string $source_path,
        public readonly array $frame_keys_opcode = [],
        public readonly array $frame_keys_no_line_opcode = [],
        public readonly array $opcode_map = [],
        public readonly array $no_line_opcode_map = [],
    ) {
    }

    /**
     * Load a .rbt file (gzip-aware) into a fully realised model.
     *
     * @param array<string, string> $path_map source-prefix => local-prefix
     *     mappings applied to every file path in the trace. Longer
     *     prefixes are tried first so a specific mapping always wins
     *     over a broader one (e.g. "/app/vendor" before "/app").
     */
    public static function load(string $path, array $path_map = []): self
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

            // Opcode-qualified key sets (with_opcode variants)
            /** @var array<string, int> $opcode_key_to_id */
            $opcode_key_to_id = [];
            /** @var list<string> $frame_keys_opcode */
            $frame_keys_opcode = [];

            /** @var array<string, int> $nlo_to_id */
            $nlo_to_id = [];
            /** @var list<string> $frame_keys_no_line_opcode */
            $frame_keys_no_line_opcode = [];

            /** @var list<int> $opcode_map */
            $opcode_map = [];
            /** @var list<int> $no_line_opcode_map */
            $no_line_opcode_map = [];

            /** @var list<list<int>> $samples */
            $samples = [];

            foreach ($reader->read($decoded) as $sample) {
                $stack = [];
                foreach ($sample->trace->call_frames as $frame) {
                    $function = $frame->function_name;
                    $file = $path_map !== [] ? self::mapPath($frame->file_name, $path_map) : $frame->file_name;
                    $line = $frame->lineno;
                    $opcode = $frame->opcode_name;

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
                        $no_line_map[] = $no_line_id;

                        // Opcode-qualified keys
                        $op_suffix = ($opcode !== null && $opcode !== '') ? ' [' . $opcode . ']' : '';

                        $with_line_op = $with_line . $op_suffix;
                        $op_id = $opcode_key_to_id[$with_line_op] ?? null;
                        if ($op_id === null) {
                            $op_id = count($frame_keys_opcode);
                            $opcode_key_to_id[$with_line_op] = $op_id;
                            $frame_keys_opcode[] = $with_line_op;
                        }
                        $opcode_map[] = $op_id;

                        $func_op = $function . $op_suffix;
                        $nlo_id = $nlo_to_id[$func_op] ?? null;
                        if ($nlo_id === null) {
                            $nlo_id = count($frame_keys_no_line_opcode);
                            $nlo_to_id[$func_op] = $nlo_id;
                            $frame_keys_no_line_opcode[] = $func_op;
                        }
                        $no_line_opcode_map[] = $nlo_id;
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
                frame_keys_opcode: $frame_keys_opcode,
                frame_keys_no_line_opcode: $frame_keys_no_line_opcode,
                opcode_map: $opcode_map,
                no_line_opcode_map: $no_line_opcode_map,
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<string, string> $path_map
     */
    private static function mapPath(string $path, array $path_map): string
    {
        $best_prefix = '';
        $best_replacement = '';
        foreach ($path_map as $from => $to) {
            if (str_starts_with($path, $from) && strlen($from) > strlen($best_prefix)) {
                $best_prefix = $from;
                $best_replacement = $to;
            }
        }
        if ($best_prefix === '') {
            return $path;
        }
        return $best_replacement . substr($path, strlen($best_prefix));
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
    public function keysFor(bool $no_line, bool $with_opcode = false): array
    {
        if ($with_opcode) {
            return $no_line ? $this->frame_keys_no_line_opcode : $this->frame_keys_opcode;
        }
        return $no_line ? $this->frame_keys_no_line : $this->frame_keys;
    }
}
